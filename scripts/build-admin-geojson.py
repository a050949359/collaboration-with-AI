#!/usr/bin/env python3
"""產生行政區邊界 GeoJSON，逐國一檔：`public/geo/admin/{國家QID}.json`。

## 來源與層級

Natural Earth 10m admin-1（4,596 個 feature，94% 帶 `wikidataid`）。

重點發現（實測，別重推）：NE 的 admin-1 **不是**統一的「第一層」，它按各國實務切，
所以同一包裡同時含我們圖譜的第一層和第二層。把 4,282 個 QID 丟去 Wikidata 問 P150：

| | 數量 | 佔比 |
|---|---|---|
| 是某國的第一層（country P150 item） | 3,165 | 74% |
| 是第一層的子節點（我們的第二層）     |   576 | 13% |
| 兩層都不是（第三層／歷史實體）       |   541 | 13% |

義大利 110 個 feature 有 108 個是第二層、法國 101 個有 96 個。所以這裡**不做
「第一層幾何」，做 QID → 幾何的對照**：前端下鑽到哪一層就畫那層，多帶一個
`level` 欄位讓前端知道這塊屬於第幾層。兩層都不是的直接丟掉（畫不出來也對不到）。

因為 NE 自帶 `wikidataid`，對 QID 是精確比對，**不需要 point-in-polygon**。

geoBoundaries ADM1 評估過但沒採用：它沒有 QID（只能靠名稱或座標猜）、CC-BY 要標示、
「簡化檔」其實不小（日本 2.2 MB），而且它自己的層級也不是處處等於 P150
（義大利給 5 個 NUTS-1 大區、台灣給 22 個縣市）。修好的國家不比 NE 多。

## 簡化

NE 10m 原始 1,295,319 個頂點（俄羅斯單國就 134k ≈ 610 KB gzip），太重。
用 mapshaper 做 **保拓樸** 的 Visvalingam 簡化（相鄰行政區共用邊界一起簡化，
不會各簡各的簡出縫）。實測：

| 保留比例 | 頂點 | 全部國家 gzip 合計 | 最大單國(RUS) |
|---|---|---|---|
| 原始 | 1,295,319 | 5.0 MB | 610 KB |
| 12%（採用） | 165,840 | 0.84 MB | 83 KB |
| 6% | 96,415 | 0.52 MB | 46 KB |
| 3% | 60,734 | 0.36 MB | 26 KB |

12% 在地球視角（一個國家頂多幾百 px）看不出差別，留點餘裕給之後放大。
mapshaper 走 `npx -y mapshaper`，是**開發時**才跑的工具，不進 package.json
（產物已 commit，一般開發與部署都不需要它）。

## 用法

    python3 scripts/build-admin-geojson.py                  # 完整產生
    python3 scripts/build-admin-geojson.py --simplify 6     # 換簡化強度
    python3 scripts/build-admin-geojson.py --check          # 只印統計不寫檔

Wikidata 的查詢結果會快取在 /tmp，重跑不會再打一次（4,282 個 QID 分批查要幾分鐘）。
"""

from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

NE_URL = (
    "https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/"
    "geojson/ne_10m_admin_1_states_provinces.geojson"
)
SPARQL_ENDPOINT = "https://query.wikidata.org/sparql"
USER_AGENT = "ohya-territory-geometry/1.0 (https://ohya.vip)"

REPO_ROOT = Path(__file__).resolve().parent.parent
OUT_DIR = REPO_ROOT / "public" / "geo" / "admin"
CACHE_DIR = Path("/tmp/territory-geometry-cache")

# 一次問 Wikidata 幾個 QID。太大會撞 SPARQL 的查詢長度/逾時上限。
SPARQL_CHUNK = 400

# 輸出座標位數。3 位 ≈ 110 公尺；mapshaper 簡化後頂點已經很稀疏，再多位數只是浪費。
PRECISION = 0.001


def fetch_source() -> dict:
    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    cached = CACHE_DIR / "ne_10m_admin_1.geojson"

    if not cached.exists():
        print(f"  下載 {NE_URL}（約 40 MB）")
        with urllib.request.urlopen(urllib.request.Request(NE_URL, headers={"User-Agent": USER_AGENT}), timeout=300) as resp:
            cached.write_bytes(resp.read())
    else:
        print(f"  沿用快取 {cached}")

    return json.loads(cached.read_text())


def sparql(query: str) -> dict:
    url = SPARQL_ENDPOINT + "?" + urllib.parse.urlencode({"query": query, "format": "json"})
    request = urllib.request.Request(
        url,
        headers={"Accept": "application/sparql-results+json", "User-Agent": USER_AGENT},
    )

    last_error: Exception | None = None

    for attempt in range(3):
        try:
            with urllib.request.urlopen(request, timeout=180) as resp:
                return json.loads(resp.read())
        except Exception as error:  # noqa: BLE001 - SPARQL 端點偶發 429/逾時，重試即可
            last_error = error
            print(f"    第 {attempt + 1} 次失敗（{error}），5 秒後重試", file=sys.stderr)
            time.sleep(5)

    raise RuntimeError(f"Wikidata 查詢連續失敗：{last_error}")


def resolve_levels(qids: list[str]) -> dict[str, dict]:
    """問出每個 QID 是哪一國的第幾層。回傳 {qid: {'country': 國家QID, 'level': 1|2}}。

    第一層：?country wdt:P150 ?item（國家直接「含有」它）
    第二層：?l1 wdt:P150 ?item 且 ?country wdt:P150 ?l1
    兩者都問不到的就是更深層或歷史實體，呼叫端會丟掉。
    """
    cached = CACHE_DIR / "wikidata-levels.json"

    if cached.exists():
        print(f"  沿用快取 {cached}")

        return json.loads(cached.read_text())

    levels: dict[str, dict] = {}

    for level, pattern in (
        (1, "?country wdt:P150 ?item ."),
        (2, "?l1 wdt:P150 ?item . ?country wdt:P150 ?l1 ."),
    ):
        pending = [q for q in qids if q not in levels]
        print(f"  查第 {level} 層（{len(pending)} 個 QID）")

        for start in range(0, len(pending), SPARQL_CHUNK):
            chunk = pending[start : start + SPARQL_CHUNK]
            values = " ".join(f"wd:{q}" for q in chunk)
            data = sparql(
                f"SELECT ?item ?country WHERE {{"
                f" VALUES ?item {{ {values} }} {pattern}"
                f" ?country wdt:P31/wdt:P279* wd:Q6256 . }}"
            )

            for binding in data["results"]["bindings"]:
                item = binding["item"]["value"].rsplit("/", 1)[-1]
                country = binding["country"]["value"].rsplit("/", 1)[-1]
                # 同一塊地被兩國宣稱時取先問到的，不要讓它同時出現在兩國檔案裡
                levels.setdefault(item, {"country": country, "level": level})

            print(f"    {min(start + SPARQL_CHUNK, len(pending))}/{len(pending)}，累計 {len(levels)}")
            time.sleep(1)

    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    cached.write_text(json.dumps(levels))

    return levels


def simplify(features: list[dict], percent: float) -> list[dict]:
    """用 mapshaper 做保拓樸簡化。各自跑 Douglas-Peucker 會讓相鄰行政區的共用邊界
    簡出不一致的線、變成一條條縫；mapshaper 會先建立拓樸再簡化，共用邊界只簡一次。"""
    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    src = CACHE_DIR / "to-simplify.geojson"
    dst = CACHE_DIR / "simplified.geojson"
    dst.unlink(missing_ok=True)

    src.write_text(
        json.dumps({"type": "FeatureCollection", "features": features}, ensure_ascii=False)
    )

    command = [
        "npx", "-y", "mapshaper", str(src),
        # keep-shapes：再小的行政區也不准簡到消失（否則城市型單位會整個不見）
        "-simplify", "visvalingam", f"{percent}%", "keep-shapes",
        "-o", f"precision={PRECISION}", str(dst),
    ]
    print(f"  mapshaper 簡化至 {percent}%…")
    result = subprocess.run(command, capture_output=True, text=True, timeout=1800)

    if not dst.exists():
        raise RuntimeError(f"mapshaper 失敗：\n{result.stderr[-2000:]}")

    return json.loads(dst.read_text())["features"]


def count_vertices(features: list[dict]) -> int:
    total = 0

    for feature in features:
        geometry = feature.get("geometry") or {}
        coordinates = geometry.get("coordinates") or []
        polygons = coordinates if geometry.get("type") == "MultiPolygon" else [coordinates]
        total += sum(len(ring) for polygon in polygons for ring in polygon)

    return total


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--simplify", type=float, default=12.0, help="保留頂點比例（%%），預設 12")
    parser.add_argument("--check", action="store_true", help="只印統計，不寫檔")
    args = parser.parse_args()

    print("來源：")
    source = fetch_source()
    all_features = source["features"]

    qids: list[str] = []
    seen: set[str] = set()

    for feature in all_features:
        qid = feature["properties"].get("wikidataid")

        if qid and qid.startswith("Q") and qid not in seen:
            seen.add(qid)
            qids.append(qid)

    print(f"  feature {len(all_features)}，不重複 wikidataid {len(qids)}")

    print("Wikidata 層級判定：")
    levels = resolve_levels(qids)

    kept: list[dict] = []
    dropped_no_qid = dropped_unknown = 0

    for feature in all_features:
        qid = feature["properties"].get("wikidataid")

        if not qid:
            dropped_no_qid += 1
            continue

        info = levels.get(qid)

        if not info:
            dropped_unknown += 1
            continue

        kept.append(
            {
                "type": "Feature",
                "properties": {
                    "qid": qid,
                    "name": feature["properties"].get("name"),
                    "level": info["level"],
                    "country": info["country"],
                },
                "geometry": feature["geometry"],
            }
        )

    print(f"  保留 {len(kept)}／丟棄：沒有 QID {dropped_no_qid}、不屬於前兩層 {dropped_unknown}")
    print(f"  簡化前頂點 {count_vertices(kept):,}")

    simplified = simplify(kept, args.simplify)
    print(f"  簡化後頂點 {count_vertices(simplified):,}")

    # 逐國分檔。key 用國家 QID（Wikidata 問出來的，比 NE 自己的 ISO 代碼可靠，
    # 也正好是圖譜 entity 的 name，前端拿到國家就能直接組路徑）。
    by_country: dict[str, list[dict]] = {}

    for feature in simplified:
        properties = feature["properties"]

        if not feature.get("geometry"):
            continue

        by_country.setdefault(properties["country"], []).append(
            {
                "type": "Feature",
                # GeoJSON 的 id 放 QID：globe.gl 的 polygon 物件直接帶著它，
                # 前端比對子節點時不用再挖 properties。
                "id": properties["qid"],
                "properties": {"name": properties["name"], "level": properties["level"]},
                "geometry": feature["geometry"],
            }
        )

    total_bytes = 0
    payloads: dict[str, str] = {}

    for country, features in by_country.items():
        features.sort(key=lambda f: (f["properties"]["level"], f["id"]))
        text = json.dumps(
            {"type": "FeatureCollection", "features": features},
            ensure_ascii=False,
            separators=(",", ":"),
        )
        payloads[country] = text
        total_bytes += len(text.encode())

    level_1 = sum(1 for f in simplified if f["properties"]["level"] == 1)
    print()
    print(f"  國家檔 {len(payloads)} 個／feature {len(simplified)}"
          f"（第一層 {level_1}、第二層 {len(simplified) - level_1}）")
    print(f"  合計 {total_bytes / 1024 / 1024:.1f} MB")

    if args.check:
        print("\n--check：沒有寫檔")

        return 0

    # 先清空：國家可能因為來源更新而消失，留著舊檔會變成對不到資料的幽靈邊界
    if OUT_DIR.exists():
        shutil.rmtree(OUT_DIR)

    OUT_DIR.mkdir(parents=True, exist_ok=True)

    for country, text in payloads.items():
        (OUT_DIR / f"{country}.json").write_text(text, encoding="utf-8")

    print(f"\n  已寫入 {OUT_DIR.relative_to(REPO_ROOT)}/（{len(payloads)} 檔）")

    return 0


if __name__ == "__main__":
    sys.exit(main())
