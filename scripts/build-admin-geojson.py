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
    """問出每個 QID 是哪一國的第幾層。
    回傳 {qid: {'country': 國家QID, 'level': 1|2, 'parent': 第一層QID（只有 level 2 有）}}。

    第一層：?country wdt:P150 ?item（國家直接「含有」它）
    第二層：?l1 wdt:P150 ?item 且 ?country wdt:P150 ?l1
    兩者都問不到的就是更深層或歷史實體，呼叫端會丟掉。
    """
    cached = CACHE_DIR / "wikidata-levels-v2.json"

    if cached.exists():
        print(f"  沿用快取 {cached}")

        return json.loads(cached.read_text())

    levels: dict[str, dict] = {}

    for level, pattern, select in (
        (1, "?country wdt:P150 ?item .", "?item ?country"),
        (2, "?l1 wdt:P150 ?item . ?country wdt:P150 ?l1 .", "?item ?country ?l1"),
    ):
        pending = [q for q in qids if q not in levels]
        print(f"  查第 {level} 層（{len(pending)} 個 QID）")

        for start in range(0, len(pending), SPARQL_CHUNK):
            chunk = pending[start : start + SPARQL_CHUNK]
            values = " ".join(f"wd:{q}" for q in chunk)
            data = sparql(
                f"SELECT {select} WHERE {{"
                f" VALUES ?item {{ {values} }} {pattern}"
                f" ?country wdt:P31/wdt:P279* wd:Q6256 . }}"
            )

            for binding in data["results"]["bindings"]:
                item = binding["item"]["value"].rsplit("/", 1)[-1]
                entry = {
                    "country": binding["country"]["value"].rsplit("/", 1)[-1],
                    "level": level,
                }

                if "l1" in binding:
                    entry["parent"] = binding["l1"]["value"].rsplit("/", 1)[-1]

                # 同一塊地被兩國宣稱時取先問到的，不要讓它同時出現在兩國檔案裡
                levels.setdefault(item, entry)

            print(f"    {min(start + SPARQL_CHUNK, len(pending))}/{len(pending)}，累計 {len(levels)}")
            time.sleep(1)

    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    cached.write_text(json.dumps(levels))

    return levels


def p150_child_counts(parents: list[str]) -> dict[str, int]:
    """問每個第一層節點「應該」有幾個第二層子節點，用來判斷併起來的形狀完不完整。"""
    cached = CACHE_DIR / "p150-child-counts.json"

    if cached.exists():
        return json.loads(cached.read_text())

    counts: dict[str, int] = {}

    for start in range(0, len(parents), SPARQL_CHUNK):
        chunk = parents[start : start + SPARQL_CHUNK]
        values = " ".join(f"wd:{q}" for q in chunk)
        data = sparql(
            f"SELECT ?l1 (COUNT(DISTINCT ?c) AS ?n) WHERE {{"
            f" VALUES ?l1 {{ {values} }} ?l1 wdt:P150 ?c }} GROUP BY ?l1"
        )

        for binding in data["results"]["bindings"]:
            counts[binding["l1"]["value"].rsplit("/", 1)[-1]] = int(binding["n"]["value"])

        time.sleep(1)

    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    cached.write_text(json.dumps(counts))

    return counts


def run_mapshaper(src: Path, dst: Path, steps: list[str]) -> list[dict]:
    dst.unlink(missing_ok=True)
    command = ["npx", "-y", "mapshaper", str(src), *steps]
    # gj2008 ← 非常重要，不要拿掉。mapshaper 預設輸出 RFC 7946（外環逆時針），
    # 但 three-globe/globe.gl 吃的是舊 d3 慣例（外環順時針，world-atlas 就是這個）。
    # 繞向在球面上決定哪一側是「內部」，方向反了每一塊行政區都會被畫成
    # 「整顆球扣掉那塊」——畫面上就是整顆地球蓋上一層半透明色。
    command += ["-o", f"precision={PRECISION}", "gj2008", str(dst)]

    result = subprocess.run(command, capture_output=True, text=True, timeout=1800)

    if not dst.exists():
        raise RuntimeError(f"mapshaper 失敗：\n{result.stderr[-2000:]}")

    return json.loads(dst.read_text())["features"]


def dissolve_into_parents(features: list[dict], have_geometry: set[str]) -> list[dict]:
    """把第二層併成第一層。

    NE 給法國的是 96 個 département、給義大利的是 108 個 provincia——都是我們的第二層，
    那些國家的第一層（région / regione）因此一條邊界都畫不出來。但我們知道每個第二層
    節點的父節點是誰，用 mapshaper `-dissolve` 把子節點併起來（它會真的消掉共用邊界，
    不是把多塊塞進同一個 MultiPolygon）就能生出第一層的形狀。淨賺 107 個第一層行政區。

    只在「父節點自己沒有幾何」時才併，而且**子節點要夠齊**：只有一半的 département
    併出來的 région 會缺一角，看起來卻像是正確資料，寧可不畫。
    """
    groups: dict[str, list[dict]] = {}

    for feature in features:
        properties = feature["properties"]
        parent = properties.get("parent")

        if properties["level"] == 2 and parent and parent not in have_geometry:
            groups.setdefault(parent, []).append(feature)

    if not groups:
        return []

    expected = p150_child_counts(sorted(groups))
    # 覆蓋率門檻 80%：NE 偶爾少一兩個小單位可以接受，少一半就不是這個形狀了
    usable = {p: fs for p, fs in groups.items() if len(fs) >= 0.8 * expected.get(p, len(fs))}
    skipped = len(groups) - len(usable)

    print(f"  併第二層 → 第一層：{len(usable)} 個父節點"
          f"（子節點不齊而跳過 {skipped} 個）")

    payload = [
        {
            "type": "Feature",
            "properties": {"parent": parent, "country": fs[0]["properties"]["country"]},
            "geometry": fs_i["geometry"],
        }
        for parent, fs in usable.items()
        for fs_i in fs
    ]

    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    src = CACHE_DIR / "to-dissolve.geojson"
    src.write_text(json.dumps({"type": "FeatureCollection", "features": payload}, ensure_ascii=False))

    dissolved = run_mapshaper(
        src,
        CACHE_DIR / "dissolved.geojson",
        ["-dissolve", "parent", "copy-fields=country"],
    )

    return [
        {
            "type": "Feature",
            "properties": {
                "qid": f["properties"]["parent"],
                # 名稱留空：併出來的是父節點，NE 的子節點名稱不適用。
                # 前端顯示走圖譜的 label，這裡本來就不是名稱來源。
                "name": None,
                "level": 1,
                "country": f["properties"]["country"],
            },
            "geometry": f["geometry"],
        }
        for f in dissolved
        if f.get("geometry")
    ]


def simplify(features: list[dict], percent: float) -> list[dict]:
    """用 mapshaper 做保拓樸簡化。各自跑 Douglas-Peucker 會讓相鄰行政區的共用邊界
    簡出不一致的線、變成一條條縫；mapshaper 會先建立拓樸再簡化，共用邊界只簡一次。"""
    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    src = CACHE_DIR / "to-simplify.geojson"

    src.write_text(
        json.dumps({"type": "FeatureCollection", "features": features}, ensure_ascii=False)
    )

    print(f"  mapshaper 簡化至 {percent}%…")

    return run_mapshaper(
        src,
        CACHE_DIR / "simplified.geojson",
        # keep-shapes：再小的行政區也不准簡到消失（否則城市型單位會整個不見）
        ["-simplify", "visvalingam", f"{percent}%", "keep-shapes"],
    )


def assert_clockwise(features: list[dict]) -> None:
    """外環必須是順時針。繞向錯了不會有任何錯誤訊息，只會在畫面上炸成一片色塊，
    所以在這裡擋下來（見 simplify() 裡 gj2008 的說明）。"""
    ccw = 0

    for feature in features:
        geometry = feature.get("geometry") or {}
        coordinates = geometry.get("coordinates") or []
        polygons = coordinates if geometry.get("type") == "MultiPolygon" else [coordinates]

        for polygon in polygons:
            if not polygon:
                continue

            ring = polygon[0]
            # shoelace：>0 順時針，<0 逆時針
            area = sum(
                (ring[i + 1][0] - ring[i][0]) * (ring[i + 1][1] + ring[i][1])
                for i in range(len(ring) - 1)
            )

            if area < 0:
                ccw += 1

    if ccw:
        raise RuntimeError(
            f"有 {ccw} 個外環是逆時針，three-globe 會把它畫成整顆球。"
            "檢查 mapshaper 的 -o 是否還帶著 gj2008。"
        )


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
                    "parent": info.get("parent"),
                },
                "geometry": feature["geometry"],
            }
        )

    print(f"  保留 {len(kept)}／丟棄：沒有 QID {dropped_no_qid}、不屬於前兩層 {dropped_unknown}")
    print(f"  簡化前頂點 {count_vertices(kept):,}")

    # 併之前先算出「哪些第一層已經有自己的幾何」，有的就不要再併一份
    have_geometry = {f["properties"]["qid"] for f in kept if f["properties"]["level"] == 1}
    # 併要在簡化「之前」做：dissolve 靠精確重合的邊界消線，簡化過就對不齊了。
    # 併完一起簡化，父子形狀共用的邊界才會被簡成同一條。
    kept += dissolve_into_parents(kept, have_geometry)

    simplified = simplify(kept, args.simplify)
    print(f"  簡化後頂點 {count_vertices(simplified):,}")
    assert_clockwise(simplified)

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
