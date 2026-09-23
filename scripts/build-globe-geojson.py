#!/usr/bin/env python3
"""產生地球儀用的國界 GeoJSON（world-atlas 110m，可選擇補上 50m 獨有的小島）。

## ⚠️ 預設不補小島（實測結論，別再改回去）

補小島會讓地球的鏡頭動畫明顯變鈍。原因不是資料量——頂點只多 9%——而是
**three-globe 對每個 feature 建一個 cap mesh + 一條 stroke line**：

| | feature | 物件／draw call | 頂點 | 互動 |
|---|---|---|---|---|
| **110m（預設）** | **177** | **354** | 10,583 | 順 |
| 110m + 50m 小島 | 238 | 476 | 12,218 | 選取／回到世界的動畫會鈍 |

cap 即使完全透明也照建照畫（`polygonCapColor` 回傳 `'rgba(0,0,0,0)'` 是真值，
three-globe 的 `hasCap` 就成立），而且**不能省**——點擊偵測就是對這片透明 cap
做 raycast，拿掉國家就點不到了。

真的需要小島國可點的話，別走 polygon，改用另一個便宜的圖層（點標記）。

## 補小島的效果（`--with-islands`）

world-atlas 的兩個解析度各有問題（實測）：

| 方案                      | 可點地域 | 頂點數  | 成本   |
|---------------------------|----------|---------|--------|
| 110m                      |      175 |  11,180 | 基準   |
| 50m                       |      236 |  98,196 | 8.8x   |
| 110m + 50m 獨有的小島     |      236 |  12,822 | 1.15x  |

50m 那 8.8 倍幾乎全花在大國海岸線變細，但我們缺的是「小島存不存在」而不是精細度
（新加坡、馬爾他、馬爾地夫…在 110m 裡整個沒有 feature，所以點不到）。
混合方案涵蓋率等同 50m，傳輸與 GPU 成本卻幾乎跟 110m 一樣。

## 輸出

`public/geo/countries-110m.json`（或 `--with-islands` 的 `countries-hybrid.json`）
— 直接是 GeoJSON FeatureCollection，
globe.gl 的 `polygonsData` 可以直接吃，前端不需要再跑 `topojson.feature()`。
副檔名刻意用 `.json` 而不是 `.geojson`：nginx 的 gzip_types 認得 application/json，
`.geojson` 會被當成 octet-stream 而不壓縮（差別是 85 KB vs 247 KB）。

feature 保留 `id`（ISO 3166-1 numeric，字串）與 `properties.name`，
前端就是靠這兩個欄位對到圖譜資料的。

## 用法

    python3 scripts/build-globe-geojson.py                  # 產生 countries-110m.json
    python3 scripts/build-globe-geojson.py --with-islands   # 產生 countries-hybrid.json
    python3 scripts/build-globe-geojson.py --check          # 只印統計，不寫檔
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
        "--with-islands",
        action="store_true",
        help="補上 50m 獨有的 61 個小島（涵蓋率較好但動畫會鈍，見檔頭說明）",
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
    print(f"  50m 獨有補上：{len(extras)}" + ("" if args.with_islands else "（未啟用 --with-islands）"))
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
