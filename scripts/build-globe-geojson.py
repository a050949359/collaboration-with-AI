#!/usr/bin/env python3
"""產生地球儀用的國界 GeoJSON（world-atlas 110m 骨架 + 50m 獨有的小島）。

## 為什麼是「混合」而不是直接用 50m

world-atlas 的兩個解析度各有問題（實測）：

| 方案                      | 可點地域 | feature | 塊    | 頂點   | gzip   |
|---------------------------|----------|---------|-------|--------|--------|
| 110m                      |      175 |     177 |   285 | 11,180 |  59 KB |
| 50m                       |      236 |     241 | 1,616 | 99,539 | 642 KB |
| 110m + 50m 獨有的小島     |      236 |     238 |   436 | 12,218 |  90 KB |

我們缺的是「小島存不存在」而不是海岸線精細度——新加坡、馬爾他、馬爾地夫…在
110m 裡整個沒有 feature，所以點不到。補完涵蓋率等同 50m，成本卻幾乎跟 110m 一樣。

50m 的代價在**初次載入**：瓶頸不是下載（本機 6 ms）也不是 JSON.parse（8 ms），
而是 three-globe 建幾何。它把每個 MultiPolygon 拆成「塊」，逐塊 new 出
Group + Mesh + LineSegments + 2 份材質，再跑 `ConicPolygonGeometry`
（沿輪廓依 curvature resolution 補球面點 → 大塊還要灑球面格點做 point-in-polygon
→ earcut 三角化，跨換日線／極區的塊改走更慢的 d3-geo-voronoi）。
這一整串在同一個 task 內跑完才還給瀏覽器：50m 實測是單一個 5.6 秒長任務
（headless CPU），期間整頁凍住。混合版沒有這種巨型任務。

註：塊數不是唯一因素，大塊比小塊貴得多（灑格點與 point-in-polygon 的成本隨該塊
頂點數走），所以「只濾掉小島塊」省不了多少——50m 濾到 606 塊，頂點仍留 86%。

## 逐塊的執行期成本（另一條軸線，跟本腳本無關但一起記著）

three-globe 預設每塊自己 new 一份材質。前端已改成共用 material 實例、且未選取的
cap 用 `colorWrite: false` 讓它不進透明佇列。cap **不能省**——點擊偵測就是對這片
看不見的 cap 做 raycast，拿掉國家就點不到了。

## 輸出

`public/geo/countries-hybrid.json`（或 `--no-islands` 的 `countries-110m.json`）
— 直接是 GeoJSON FeatureCollection，
globe.gl 的 `polygonsData` 可以直接吃，前端不需要再跑 `topojson.feature()`。
副檔名刻意用 `.json` 而不是 `.geojson`：nginx 的 gzip_types 認得 application/json，
`.geojson` 會被當成 octet-stream 而不壓縮（差別是 85 KB vs 247 KB）。

feature 保留 `id`（ISO 3166-1 numeric，字串）與 `properties.name`，
前端就是靠這兩個欄位對到圖譜資料的。

## 用法

    python3 scripts/build-globe-geojson.py                # 產生 countries-hybrid.json（出貨用）
    python3 scripts/build-globe-geojson.py --no-islands   # 產生 countries-110m.json
    python3 scripts/build-globe-geojson.py --check        # 只印統計，不寫檔
"""

from __future__ import annotations

import argparse
import json
import sys
import urllib.request
from pathlib import Path

SOURCES = {
    "110m": "https://cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json",
    "50m": "https://cdn.jsdelivr.net/npm/world-atlas@2/countries-50m.json",
}

REPO_ROOT = Path(__file__).resolve().parent.parent
OUT_PATH = REPO_ROOT / "public" / "geo" / "countries-110m.json"
OUT_PATH_WITH_ISLANDS = REPO_ROOT / "public" / "geo" / "countries-hybrid.json"
CACHE_DIR = Path("/tmp/world-atlas-cache")

# 座標四捨五入位數。4 位 ≈ 11 公尺，對一顆螢幕上幾百 px 的地球綽綽有餘，
# 但能把檔案砍掉一半以上（原始資料是 quantize 過的浮點，尾數很長）。
PRECISION = 4


def fetch(url: str) -> dict:
    """抓來源 topojson，順手存一份到 /tmp 避免重跑時重抓。"""
    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    cached = CACHE_DIR / url.rsplit("/", 1)[-1]

    if not cached.exists():
        print(f"  下載 {url}")
        with urllib.request.urlopen(url, timeout=60) as resp:
            cached.write_bytes(resp.read())
    else:
        print(f"  沿用快取 {cached}")

    return json.loads(cached.read_text())


def decode_arcs(topology: dict) -> list[list[list[float]]]:
    """把 topojson 的 delta-encoded / quantized arcs 還原成絕對座標。

    在 arc 層級（而不是 ring 層級）就做四捨五入：相鄰國家共用同一條 arc，
    在這裡捨入才能保證兩邊邊界完全貼合，不會捨出縫隙。
    """
    transform = topology.get("transform")
    scale = transform["scale"] if transform else (1, 1)
    translate = transform["translate"] if transform else (0, 0)

    decoded = []

    for arc in topology["arcs"]:
        points = []
        x = y = 0

        for dx, dy in arc:
            if transform:
                x += dx
                y += dy
                point = [
                    round(x * scale[0] + translate[0], PRECISION),
                    round(y * scale[1] + translate[1], PRECISION),
                ]
            else:
                point = [round(dx, PRECISION), round(dy, PRECISION)]

            # 捨入後可能跟前一點重合，直接丟掉，不然會留下零長度線段
            if not points or points[-1] != point:
                points.append(point)

        decoded.append(points)

    return decoded


def stitch_ring(arcs: list, indices: list[int]) -> list | None:
    """把 arc index 串成一個封閉的 ring；退化成線的（<4 點）回 None。

    負的 index 代表這條 arc 要反向走，實際索引是 ~i（topojson 規格）。
    """
    ring: list[list[float]] = []

    for index in indices:
        points = arcs[~index][::-1] if index < 0 else arcs[index]

        if not points:
            continue

        # 接縫處頭尾會重複一個點，去掉一個
        ring.extend(points[1:] if ring and ring[-1] == points[0] else points)

    if len(ring) < 3:
        return None

    if ring[0] != ring[-1]:
        ring.append(ring[0])

    return ring if len(ring) >= 4 else None


def to_polygon(arcs: list, geometry: dict) -> dict | None:
    """topojson geometry → GeoJSON geometry（只處理 Polygon / MultiPolygon）。"""
    kind = geometry.get("type")

    if kind == "Polygon":
        rings = [r for r in (stitch_ring(arcs, part) for part in geometry["arcs"]) if r]

        return {"type": "Polygon", "coordinates": rings} if rings else None

    if kind == "MultiPolygon":
        polygons = []

        for polygon in geometry["arcs"]:
            rings = [r for r in (stitch_ring(arcs, part) for part in polygon) if r]

            if rings:
                polygons.append(rings)

        return {"type": "MultiPolygon", "coordinates": polygons} if polygons else None

    return None


def to_features(topology: dict) -> list[dict]:
    arcs = decode_arcs(topology)
    features = []

    for geometry in topology["objects"]["countries"]["geometries"]:
        shape = to_polygon(arcs, geometry)

        if not shape:
            continue

        features.append(
            {
                "type": "Feature",
                "id": geometry.get("id"),
                "properties": {"name": geometry.get("properties", {}).get("name")},
                "geometry": shape,
            }
        )

    return features


def count_vertices(features: list[dict]) -> int:
    total = 0

    for feature in features:
        coordinates = feature["geometry"]["coordinates"]
        polygons = (
            coordinates
            if feature["geometry"]["type"] == "MultiPolygon"
            else [coordinates]
        )
        total += sum(len(ring) for polygon in polygons for ring in polygon)

    return total


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--check", action="store_true", help="只印統計數字，不寫出檔案"
    )
    parser.add_argument(
        "--no-islands",
        dest="with_islands",
        action="store_false",
        help="不補 50m 獨有的 61 個小島，只輸出純 110m（那些小島國會點不到）",
    )
    args = parser.parse_args()

    print("來源：")
    base = to_features(fetch(SOURCES["110m"]))
    detailed = to_features(fetch(SOURCES["50m"]))

    base_ids = {f["id"] for f in base if f["id"]}

    # 只補「110m 整個沒有這個 id」的 feature。沒有 id 的（N. Cyprus / Somaliland /
    # Kosovo / Indian Ocean Ter. / Siachen Glacier）不補：前三個 110m 已經畫得出來，
    # 後兩個沒有 ISO numeric 本來就對不到圖譜，補了也點不了。
    extras = [f for f in detailed if f["id"] and f["id"] not in base_ids] if args.with_islands else []

    merged = base + extras
    merged.sort(key=lambda f: (f["id"] or "zzz", f["properties"]["name"] or ""))

    payload = {"type": "FeatureCollection", "features": merged}
    # separators：去掉 JSON 預設的空白，檔案小一成
    text = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))

    out_path = OUT_PATH_WITH_ISLANDS if args.with_islands else OUT_PATH

    print()
    print(f"  110m feature：{len(base)}（有 id {len(base_ids)}）")
    print(f"  50m 獨有補上：{len(extras)}" + ("" if args.with_islands else "（--no-islands）"))
    print(f"  合併後：{len(merged)} feature／{count_vertices(merged):,} 頂點")
    print(f"  大小：{len(text.encode()) / 1024:.0f} KB（未壓縮）")

    if args.check:
        print("\n--check：沒有寫檔")

        return 0

    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(text, encoding="utf-8")
    print(f"\n  已寫入 {out_path.relative_to(REPO_ROOT)}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
