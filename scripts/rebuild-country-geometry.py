#!/usr/bin/env python3
"""重產**單一國家**的行政區幾何檔 `public/geo/admin/{國家QID}.json`。

## 為什麼不是用 build-territory-geojson.py

那支是全量管線（在 wip/territory-admin-geometry 分支），一次重算 258 國，而且會先
`shutil.rmtree(public/geo/admin)`。要補一國的缺漏用它風險不成比例。這支只碰一個檔。

更重要的是**層級來源不同**，這才是這支存在的理由：

| | build-territory-geojson.py | 這支 |
|---|---|---|
| 哪些 QID 算第一層 | Wikidata 的 `wdt:P150` | **我們的圖譜**（part_of 關係） |

`wdt:` 是 truthy prefix——只要某個屬性上有任何一筆 statement 被標成 preferred rank，
它就**只回 preferred 的那些**，其餘靜默消失。實測後果：

- 台灣 Q865 的 P150 有 15 筆 claim，7 筆 preferred（6 直轄市 + 臺灣地區）、8 筆 normal。
  被藏起來的 normal 裡就有「臺灣省 Q32081」，而 13 縣 3 市全掛在它底下。於是全量管線
  把那 16 個判成「不屬於前兩層」丟掉，台灣只畫得出 5 塊。
- 沙烏地 Q851 有 14 筆 P150 claim，`wdt:` 只回 1 筆 → 13 個省在地圖上整批消失。

而 NE 那邊其實是齊的（台灣 21 個 feature、20 個自帶正確 wikidataid）。**形狀一直都在，
是層級判定把它們扔了。** 改以圖譜為準就不必跟 Wikidata 的 rank 慣例角力——圖譜是我們
能修、修了就算數的那一邊。

反過來說，圖譜沒有的就不畫。立陶宛是這個取捨的範例：NE 給 10 個縣（2010 年就被廢掉
行政職能），我們圖譜的第一層是 60 個市鎮，NE 沒有市鎮幾何——那就畫 0 塊，不要畫出
一組跟面板對不起來的過時邊界。

## 把第二層併成第一層（dissolve）

NE 給法國的是 101 個 département、給義大利的是 110 個 provincia，都是我們的**第二層**，
那些國家的第一層（région / regione）一條邊界都畫不出來。作法是用 mapshaper `-dissolve`
把子節點併起來生出第一層的形狀——它會真的消掉共用邊界，不是把多塊塞進同一個 MultiPolygon。

兩個前提，缺一不可：

1. **dissolve 要在簡化「之前」做**。消線靠的是邊界精確重合，簡化過就對不齊了。併完
   一起簡化，父子形狀共用的邊界才會被簡成同一條。
2. **子節點要夠齊**（預設門檻 80%）。只有一半的 département 併出來的 région 會缺一角，
   看起來卻像正確資料——寧可不畫。這裡的「應該有幾個」直接數**圖譜**的子節點，
   不像全量管線要另外去問 Wikidata 的 P150 child count。

只在「父節點自己沒有幾何」時才併，已經有自己形狀的不會被蓋掉。

## ⚠️ NE 的國家欄位不能拿來圈候選

**不要**用 `adm0_a3 == 目標國家` 去濾 admin-1。法國的海外領地在 NE 裡掛在自己的 adm0
代碼底下（MAF / BLM / SPM / NCL / WLF…），照 adm0_a3 濾會把它們整批丟掉——實測第一層
會從 23 掉到 18。作法是先用 QID 全域比對（精確比對，本來就不需要先圈國家），再從
配到的那幾個 adm0 代碼回推候選範圍，P300 退路只在那個範圍裡跑。

## 什麼時候該跑這支

**只在那一國的地圖真的壞掉的時候。** 不要為了「資料更一致」去重產一個看起來正常的國家。

重產的成本比直覺高：mapshaper 的拓樸簡化是整包一起算的，feature 集合只要變動一點，
共用邊界被簡成的弧就跟著不一樣——**等於整份檔案重寫**。法國實測：共同的 122 個 feature
裡有 100 個座標會變，換來的只是少畫一個不在圖譜裡的克利伯頓島、多三個第二層。
不划算，而且平白引入視覺風險。

台灣是另一個極端：攤平圖中間一個大洞、21 塊只畫得出 5 塊，那才值得重產。

## 安全閥

預設會擋：新結果的第一層數量比現有檔少就拒寫，要覆蓋得明確加 `--force`。

變少不一定是壞事，所以是擋下來讓人看、不是直接失敗。實測兩種情況都有：

- **法國 23 → 22**：少掉的是克利伯頓島 Q161258，它**不在我們圖譜裡**。舊檔畫得出來
  是因為舊管線照 Wikidata 判層，面板卻沒有它——所以少畫那一塊在資料上是對的。
  但如上所述，單為這點收益重寫整份檔案不值得，目前刻意不動法國。
- **義大利 18 → 16**：圖譜的 Friuli 與 Sicily 第二層是 0 個（NE 有那些省，圖譜沒匯），
  Sardinia 只有改制後的 3 個而 NE 是舊的 8 省、Aosta Valley 的子節點是 74 個 comuni
  而非省。這是圖譜資料缺口，**不該** `--force`，要先補圖譜。

## NE 的 wikidataid 缺漏怎麼補

缺 QID 的 feature 100% 都有 `iso_3166_2`，拿去問 Wikidata P300（ISO 3166-2 code）
可以救回一部分（台灣的臺中市 TW-TXG → Q245023 就是這樣救的）。**刻意不寫死任何
「地名 → QID」對照**：P300 是通則，一次解一類。

配對成功後會驗 P17（所屬國家）對不對得上——NE 的 wikidataid 不只會缺、還會**錯**，
而且是靜默錯（立陶宛的 Alytaus 縣標成一個市鎮的 QID，形狀是縣、名字是市鎮，零警告）。

## 用法

    python3 scripts/rebuild-country-geometry.py Q865           # 重產台灣
    python3 scripts/rebuild-country-geometry.py Q865 Q851      # 一次多國
    python3 scripts/rebuild-country-geometry.py Q865 --check   # 只印對照結果，不寫檔
    python3 scripts/rebuild-country-geometry.py Q142 --force   # 明知會變少仍要覆蓋

Token 解析沿用 territory_lib（`MCP_TERRITORY_TOKEN` > `MCP_TOKEN` > `.vscode/mcp.json`）。

寫檔後記得重跑 `python3 scripts/build-admin-manifest.py` 更新 index.json
（前端靠它知道哪一國能下鑽、鏡頭要對到哪個 bbox）。
"""

from __future__ import annotations

import argparse
import collections
import json
import subprocess
import sys
import urllib.parse
import urllib.request
from pathlib import Path

from territory_lib import call_tool, resolve_endpoint, resolve_token

NE_BASE = "https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/"
NE_ADMIN1 = NE_BASE + "ne_10m_admin_1_states_provinces.geojson"
NE_ADMIN0 = NE_BASE + "ne_10m_admin_0_countries.geojson"
SPARQL_ENDPOINT = "https://query.wikidata.org/sparql"
# Cloudflare 會擋 urllib 的預設 User-Agent（HTTP 403），一定要自己帶一個
USER_AGENT = "ohya-territory-geometry/1.0 (https://ohya.vip)"

REPO_ROOT = Path(__file__).resolve().parent.parent
OUT_DIR = REPO_ROOT / "public" / "geo" / "admin"
CACHE_DIR = Path("/tmp/territory-geometry-cache")
# NE 沒填 wikidataid 時的人工對照表（NE 的 name → 圖譜 QID）。理由見該檔的 _readme。
NAME_OVERRIDES = Path(__file__).resolve().parent / "ne-name-overrides.json"

# 跟全量管線一致，不然同一個目錄裡會混著兩種精細度
PRECISION = 0.001
DEFAULT_SIMPLIFY = 6.0
# dissolve 的子節點覆蓋率門檻。只有一半的 département 併出來的 région 會缺一角，
# 看起來卻像正確資料——寧可不畫。
DEFAULT_COVERAGE = 0.8


def get_json(url: str, timeout: int = 180) -> dict | list:
    request = urllib.request.Request(
        url, headers={"User-Agent": USER_AGENT, "Accept": "application/json"}
    )

    with urllib.request.urlopen(request, timeout=timeout) as response:
        return json.loads(response.read().decode())


def fetch_source(url: str, name: str) -> dict:
    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    cached = CACHE_DIR / name

    if not cached.exists():
        print(f"  下載 {name}（39 MB 的那份要一分鐘左右）")
        request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})

        with urllib.request.urlopen(request, timeout=600) as response:
            cached.write_bytes(response.read())
    else:
        print(f"  沿用快取 {cached}")

    return json.loads(cached.read_text(encoding="utf-8"))


def sparql(query: str) -> list[dict]:
    url = SPARQL_ENDPOINT + "?" + urllib.parse.urlencode({"query": query, "format": "json"})

    return get_json(url)["results"]["bindings"]


def graph_levels(country_qid: str) -> tuple[dict[str, str], dict[str, str]]:
    """圖譜裡這個國家的前兩層。回傳 (第一層 {QID: 名稱}, 第二層 {QID: 父節點QID})。

    用 MCP 的 `read_subtree` 一次吃兩層——公開 REST 的 children 端點只能一次一個節點，
    法國要打 26 次、還跟 v1/airports 共用同一個 60/min 的節流桶。
    """
    error, text = call_tool(
        resolve_endpoint(), resolve_token(), "read_subtree",
        {"entity_name": country_qid, "depth": 2},
    )

    if error:
        raise RuntimeError(f"read_subtree({country_qid}) 失敗：{text}")

    payload = json.loads(text)

    if payload.get("truncated"):
        print("  ⚠️ read_subtree 回報 truncated，第二層可能不完整，dissolve 的覆蓋率會失真")

    level1: dict[str, str] = {}
    level2: dict[str, str] = {}

    for child in payload["tree"].get("children") or []:
        level1[child["qid"]] = (child.get("observations") or {}).get("label") or child["qid"]

        for grandchild in child.get("children") or []:
            level2[grandchild["qid"]] = child["qid"]

    return level1, level2


def resolve_by_iso_3166_2(codes: list[str]) -> dict[str, str]:
    """ISO 3166-2 代碼 → QID。用來救 NE 沒填 wikidataid 的 feature。"""
    if not codes:
        return {}

    values = " ".join(f'"{code}"' for code in sorted(set(codes)))
    rows = sparql(f"SELECT ?x ?code WHERE {{ VALUES ?code {{ {values} }} ?x wdt:P300 ?code . }}")

    return {row["code"]["value"]: row["x"]["value"].rsplit("/", 1)[-1] for row in rows}


def load_name_overrides() -> dict[str, dict[str, str]]:
    """讀人工對照表：{adm0_a3: {NE 的 name: QID}}。底線開頭的鍵是說明文字，略過。

    這張表只處理「NE 連 wikidataid 都沒填」的 feature——它們的 iso_3166_2 是 NE 自填的
    佔位碼（結尾帶 `~`），**沒有任何識別碼可查**，P300 與 P131 都無從下手。
    跟 P300 退路刻意不寫死「地名 → QID」不同：那裡有通則可用（ISO 3166-2），這裡沒有。
    """
    if not NAME_OVERRIDES.exists():
        return {}

    raw = json.loads(NAME_OVERRIDES.read_text(encoding="utf-8"))

    return {
        a3: {k: v for k, v in table.items() if not k.startswith("_")}
        for a3, table in raw.items()
        if not a3.startswith("_") and isinstance(table, dict)
    }


def p131_into(qids: list[str], parents: set[str]) -> dict[str, tuple[str, str]]:
    """配不到的 NE feature，問它的 P131 上層是不是我們的第一層節點。

    回傳 {QID: (父節點QID, 該實體的 P31)}。

    為什麼要這條：有些國家的父子關係在 Wikidata 上**只編碼在往上的 P131**，往下的
    P150 是空的。斯里蘭卡就是——9 個省的 P150 幾乎都是 0，但 NE 給的 24 個「區」
    每一個的 P131 都指向其中一個省（24/24）。只走 P150 就一塊都畫不出來。

    ⚠️ 界線：**圖譜仍然決定「有什麼」**（父節點必須已經在圖譜第一層裡），
    Wikidata 只回答「這塊形狀歸誰」。這跟先前特意改掉的「用 wdt:P150 判層級」不同——
    不會憑空多出圖譜沒有的節點，只是把已有節點的形狀拼出來。
    """
    if not qids:
        return {}

    found: dict[str, tuple[str, str]] = {}

    for start in range(0, len(qids), 250):
        values = " ".join(f"wd:{qid}" for qid in sorted(set(qids))[start : start + 250])
        rows = sparql(
            f"SELECT ?x ?p ?t WHERE {{ VALUES ?x {{ {values} }}"
            f" ?x wdt:P131 ?p ; wdt:P31 ?t . }}"
        )

        for row in rows:
            item = row["x"]["value"].rsplit("/", 1)[-1]
            parent = row["p"]["value"].rsplit("/", 1)[-1]

            if parent in parents:
                found.setdefault(item, (parent, row["t"]["value"].rsplit("/", 1)[-1]))

    return found


def sibling_counts(pairs: set[tuple[str, str]]) -> dict[str, int]:
    """每個 (父節點, P31) 組合底下「應該」有幾個同類實體，當 dissolve 的覆蓋率分母。

    不能直接用「我們配到幾個」當分母——那樣永遠是 100%，等於沒有門檻。
    已解散的不算（它們不該出現在現行的拼圖裡）。
    """
    expected: dict[str, int] = {}

    for parent, kind in pairs:
        rows = sparql(
            f"SELECT (COUNT(DISTINCT ?d) AS ?n) WHERE {{"
            f" ?d wdt:P131 wd:{parent} ; wdt:P31 wd:{kind} ."
            f" FILTER NOT EXISTS {{ ?d wdt:P576 ?dis }} }}"
        )
        count = int(rows[0]["n"]["value"]) if rows else 0
        expected[parent] = max(expected.get(parent, 0), count)

    return expected


def dissolved_entities(qids: list[str]) -> set[str]:
    """哪些 QID 有 P576（解散日期）。

    用來擋掉**危險的換 id**：NE 的形狀是跟著它自己那筆 wikidataid 的，如果那個實體
    已經解散，手上這塊就是**改制前的邊界**，不能因為 ISO 代碼被新單位沿用就貼上
    新實體的 QID——畫出來會是舊形狀配新名字，而且零警告。

    實測兩個案例：拉脫維亞 2021 把 119 個市鎮併成 43 個、摩洛哥 2015 把 16 個大區
    改成 12 個，ISO 代碼都被沿用，結果 30 個 feature 被貼上新 QID。
    """
    if not qids:
        return set()

    values = " ".join(f"wd:{qid}" for qid in sorted(set(qids)))
    rows = sparql(f"SELECT ?x WHERE {{ VALUES ?x {{ {values} }} ?x wdt:P576 ?d }}")

    return {row["x"]["value"].rsplit("/", 1)[-1] for row in rows}


def countries_of(qids: list[str]) -> dict[str, set[str]]:
    """每個 QID 的 P17（所屬國家）。NE 的 wikidataid 會靜默配錯，用這個擋下來。"""
    if not qids:
        return {}

    values = " ".join(f"wd:{qid}" for qid in sorted(set(qids)))
    rows = sparql(f"SELECT ?x ?c WHERE {{ VALUES ?x {{ {values} }} ?x wdt:P17 ?c . }}")

    result: dict[str, set[str]] = {}

    for row in rows:
        item = row["x"]["value"].rsplit("/", 1)[-1]
        result.setdefault(item, set()).add(row["c"]["value"].rsplit("/", 1)[-1])

    return result


def run_mapshaper(features: list[dict], steps: list[str], tag: str) -> list[dict]:
    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    src = CACHE_DIR / f"{tag}-in.geojson"
    dst = CACHE_DIR / f"{tag}-out.geojson"
    dst.unlink(missing_ok=True)
    src.write_text(
        json.dumps({"type": "FeatureCollection", "features": features}, ensure_ascii=False),
        encoding="utf-8",
    )

    result = subprocess.run(
        [
            "npx", "-y", "mapshaper", str(src), *steps,
            # gj2008 ← 不要拿掉。mapshaper 預設輸出 RFC 7946（外環逆時針），
            # 但 three-globe/globe.gl 吃舊 d3 慣例（外環順時針）。繞向在球面上決定
            # 哪一側是「內部」，反了會把每一塊畫成「整顆球扣掉那塊」。
            "-o", f"precision={PRECISION}", "gj2008", str(dst),
        ],
        capture_output=True, text=True, timeout=1800,
    )

    if not dst.exists():
        raise RuntimeError(f"mapshaper 失敗：\n{result.stderr[-2000:]}")

    return json.loads(dst.read_text(encoding="utf-8"))["features"]


def simplify(features: list[dict], percent: float, tag: str) -> list[dict]:
    """保拓樸簡化。各自跑 Douglas-Peucker 會讓相鄰行政區的共用邊界簡出不一致的線、
    變成一條條縫；mapshaper 先建拓樸再簡化，共用邊界只簡一次。

    國家輪廓要跟行政區**在同一次**簡化裡（NE 的 admin-0 與 admin-1 是同一份底圖），
    共用的海岸線才會被簡成同一條弧，疊起來不會露出雙線。
    """
    return run_mapshaper(
        features,
        # keep-shapes：再小的行政區也不准簡到消失（否則城市型單位會整個不見）
        ["-simplify", "visvalingam", f"{percent}%", "keep-shapes"],
        f"{tag}-simplify",
    )


def dissolve_into_parents(
    level2_features: list[dict], have_geometry: set[str], expected: dict[str, int],
    labels: dict[str, str], threshold: float, tag: str,
) -> list[dict]:
    """把第二層併成第一層的形狀。詳見檔頭「把第二層併成第一層」那節。

    `expected` 是每個父節點**應該**有幾個子節點，當覆蓋率的分母。來源有二：圖譜自己的
    第二層數量（全量管線得另外去問 Wikidata 的 P150 child count，我們直接數圖譜），
    以及 P131 那條路用 sibling_counts() 問回來的同類實體數。
    """
    groups: dict[str, list[dict]] = {}

    for feature in level2_features:
        parent = feature["properties"]["parent"]

        if parent not in have_geometry:
            groups.setdefault(parent, []).append(feature)

    if not groups:
        return []

    usable = {p: fs for p, fs in groups.items() if len(fs) >= threshold * expected.get(p, len(fs))}
    skipped = [
        f"{labels.get(p, p)} {len(fs)}/{expected.get(p, len(fs))}"
        for p, fs in groups.items()
        if p not in usable
    ]

    print(f"  併第二層 → 第一層：{len(usable)} 個父節點")

    if skipped:
        print(f"    子節點不齊而跳過（門檻 {threshold:.0%}）：{'、'.join(skipped)}")

    if not usable:
        return []

    dissolved = run_mapshaper(
        [
            {"type": "Feature", "properties": {"parent": parent}, "geometry": f["geometry"]}
            for parent, fs in usable.items()
            for f in fs
        ],
        ["-dissolve", "parent"],
        f"{tag}-dissolve",
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
            },
            "geometry": f["geometry"],
        }
        for f in dissolved
        if f.get("geometry")
    ]


def assert_clockwise(features: list[dict]) -> None:
    """外環必須順時針。繞向錯了不會有任何錯誤訊息，只會在畫面上炸成一片色塊。"""
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


def rebuild(
    country_qid: str, admin0: dict, admin1: dict, percent: float, coverage: float,
    check: bool, force: bool,
) -> bool:
    """回傳有沒有真的寫檔——呼叫端靠它決定要不要提醒重跑 manifest。"""
    children, level2 = graph_levels(country_qid)
    print(f"\n{country_qid}：圖譜第一層 {len(children)} 個、第二層 {len(level2)} 個")

    outline = next(
        (f for f in admin0["features"] if f["properties"].get("WIKIDATAID") == country_qid),
        None,
    )

    if outline is None:
        print("  ⚠️ NE admin-0 找不到這個國家的輪廓（WIKIDATAID 對不到），略過")

        return False

    wanted = set(children) | set(level2)
    overrides = load_name_overrides()

    # ⚠️ 不要用 adm0_a3 圈候選。法國的海外省在 NE 裡掛在自己的 adm0 代碼底下
    # （GLP / MTQ / REU / GUF / MAF / BLM / SPM、克利伯頓島…），照 adm0_a3 濾會把它們
    # 全丟掉——實測第一層會從 23 掉到 18。舊管線沒這問題是因為它靠 Wikidata 回的
    # ?country 分檔，不靠 NE 的國家欄位。QID 是精確比對，本來就不需要先圈國家。
    by_qid = [f for f in admin1["features"] if f["properties"].get("wikidataid") in wanted]

    # P300 退路只在「已經配到東西的那幾個 adm0 代碼」裡找，不對全世界 264 筆缺 ID 的
    # feature 跑 SPARQL。加上這個國家自己的代碼，否則第一次配不到任何東西就沒機會救。
    # 濾掉空值：None 進了 codes，下面那行會把「所有沒填 adm0_a3 的 feature」全拉進候選
    # （None in codes 成立）。目前 NE 兩個檔都 100% 有填，所以是潛伏問題，但防護是免費的。
    codes = {f["properties"].get("adm0_a3") for f in by_qid}
    codes.add(outline["properties"].get("ADM0_A3"))
    codes.discard(None)
    codes.discard("")
    candidates = [f for f in admin1["features"] if f["properties"].get("adm0_a3") in codes]

    # P300 退路涵蓋**兩種**情況，不是只有「NE 沒填 wikidataid」：
    #   1. 沒填（臺中市）
    #   2. 填了但指錯實體 —— 日本的北海道 NE 指到 Q35581（P31=日本島嶼、沒有 ISO 碼），
    #      正確的都道府縣是 Q1037393（P31=都道府縣、P300=JP-01）。NE 的 iso_3166_2
    #      欄位填的是 JP-01，所以拿它查 P300 就能換回對的實體。
    # 判準統一成「解出來的 QID 不在圖譜裡」。這樣安全：P300 的結果仍然要在 wanted 裡
    # 才會被採用，不會憑空配出圖譜沒有的節點。
    # 跳過 NE 自填的佔位碼（科索沃 XK-X02~ 那種帶波浪號的），查了也沒有。
    needs_code = [
        f for f in candidates
        if f["properties"].get("wikidataid") not in wanted
        and f["properties"].get("iso_3166_2")
        and not f["properties"]["iso_3166_2"].endswith("~")
    ]
    by_code = resolve_by_iso_3166_2([f["properties"]["iso_3166_2"] for f in needs_code])

    # NE 原本指的實體若已解散，手上這塊就是改制前的邊界，不准換（見 dissolved_entities()）
    gone = dissolved_entities([
        f["properties"]["wikidataid"] for f in needs_code if f["properties"].get("wikidataid")
    ])

    print(f"  NE admin-1 候選 {len(candidates)} 個"
          f"（adm0_a3：{'、'.join(sorted(c for c in codes if c))}）")

    if gone:
        stale = [f for f in needs_code if f["properties"].get("wikidataid") in gone]
        print(f"  ⛔ {len(stale)} 個 feature 的 NE 實體已解散（形狀是改制前的），不換 id："
              f"{'、'.join(str(f['properties'].get('name')) for f in stale[:5])}"
              + ("…" if len(stale) > 5 else ""))

        for feature in stale:
            by_code.pop(feature["properties"].get("iso_3166_2"), None)

    if needs_code:
        rescued = [
            f for f in needs_code
            if by_code.get(f["properties"]["iso_3166_2"]) in wanted
            and f["properties"].get("wikidataid") not in gone
        ]
        wrong = [f for f in rescued if f["properties"].get("wikidataid")]
        print(f"  QID 對不到圖譜的 {len(needs_code)} 個 → P300 救回 {len(rescued)} 個"
              + (f"（其中 {len(wrong)} 個是 NE 指錯實體）" if wrong else ""))

        for feature in wrong:
            properties = feature["properties"]
            print(f"    ⚠️ {properties.get('name')}：NE 給 {properties['wikidataid']}，"
                  f"依 {properties['iso_3166_2']} 改用 {by_code[properties['iso_3166_2']]}")

    matched: list[dict] = []
    leftover: list[tuple[dict, str | None]] = []

    for feature in candidates:
        properties = feature["properties"]
        qid = properties.get("wikidataid")

        if qid not in wanted and qid not in gone:
            # 人工對照優先：它處理的是「NE 連 ID 都沒有」，P300 在那種情況下也查不到
            qid = (
                overrides.get(properties.get("adm0_a3"), {}).get(properties.get("name"))
                or by_code.get(properties.get("iso_3166_2"), qid)
            )

        if qid in children:
            level, parent = 1, None
        elif qid in level2:
            level, parent = 2, level2[qid]
        else:
            leftover.append((feature, qid))
            continue

        matched.append(
            {
                "type": "Feature",
                "properties": {
                    "qid": qid, "name": properties.get("name"), "level": level, "parent": parent,
                },
                "geometry": feature["geometry"],
            }
        )

    # NE 的 wikidataid 會靜默配錯，用 P17 擋。
    #
    # ⚠️ 不能直接拿 country_qid 當期望值——**屬地的 P17 是宗主國**：香港十八區的 P17 是
    # 中國 Q148、奧蘭市鎮的是芬蘭 Q33、北馬里亞納的是美國 Q30。那樣會對整個屬地噴出
    # 一整排假警報（實測香港 18 個全中）。改成多數決：同一國的行政區絕大多數會共用
    # 同一個 P17，偏離主流的那幾個才是可疑的——那正是這個檢查原本要抓的
    # 「NE 指到別國的實體」。
    p17 = countries_of([f["properties"]["qid"] for f in matched])
    tally = collections.Counter(c for codes_ in p17.values() for c in codes_)
    expected_country = tally.most_common(1)[0][0] if tally else country_qid
    odd = [
        f for f in matched
        if f["properties"]["qid"] in p17
        and expected_country not in p17[f["properties"]["qid"]]
    ]

    for feature in odd:
        qid = feature["properties"]["qid"]
        print(f"  ⚠️ {feature['properties']['name']}（{qid}）的 P17 是 "
              f"{'／'.join(sorted(p17[qid]))}，這一國其餘是 {expected_country}，可能配錯了")

    # 配不到的，再問一次 P131：上層如果正好是我們的第一層節點，就當成第二層（見 p131_into）
    by_p131 = p131_into([q for _, q in leftover if q and q not in gone], set(children))
    unmatched: list[str] = []

    for feature, qid in leftover:
        found = by_p131.get(qid or "")

        if not found:
            unmatched.append(f"{feature['properties'].get('name')}（{qid or '無 QID'}）")
            continue

        matched.append(
            {
                "type": "Feature",
                "properties": {
                    "qid": qid, "name": feature["properties"].get("name"),
                    "level": 2, "parent": found[0],
                },
                "geometry": feature["geometry"],
            }
        )

    if by_p131:
        print(f"  P131 歸屬：{len(by_p131)} 個 NE feature 的上層是我們的第一層節點，當成第二層")

    own = [f for f in matched if f["properties"]["level"] == 1]
    children_features = [f for f in matched if f["properties"]["level"] == 2]
    print(f"  配對成功 {len(matched)}（第一層 {len(own)}／{len(children)}、"
          f"第二層 {len(children_features)}／{len(level2)}）")

    if unmatched:
        print(f"  NE 有形狀但圖譜沒有（不畫）：{'、'.join(unmatched)}")

    # dissolve 要在簡化「之前」做：消線靠邊界精確重合，簡化過就對不齊了
    have_geometry = {f["properties"]["qid"] for f in own}
    # 覆蓋率的分母：圖譜自己的第二層數量，加上 P131 那條路問回來的同類實體數
    expected: dict[str, int] = {}

    for parent in level2.values():
        expected[parent] = expected.get(parent, 0) + 1

    expected.update(sibling_counts({(p, t) for p, t in by_p131.values()}))

    dissolved = dissolve_into_parents(
        children_features, have_geometry, expected, children, coverage, country_qid
    )

    # 還沒有形狀的第一層節點，回頭查 NE 的 **admin-0**。NE 把不少屬地／特別行政區當成
    # 獨立國家處理（香港、奧蘭、法屬玻里尼西亞、美屬薩摩亞、澳洲的外部領地…），所以它們
    # 的形狀在 admin-0 而不是 admin-1。admin-0 與 admin-1 是同一份底圖，跟著一起丟進
    # mapshaper 簡化就會對齊，不會露出雙線。
    drawn = have_geometry | {f["properties"]["qid"] for f in dissolved}
    from_admin0 = [
        {
            "type": "Feature",
            "properties": {"qid": qid, "name": f["properties"].get("NAME"), "level": 1},
            "geometry": f["geometry"],
        }
        for qid in children
        if qid not in drawn and qid != country_qid
        for f in admin0["features"]
        if f["properties"].get("WIKIDATAID") == qid
    ]

    if from_admin0:
        print(f"  從 admin-0 補回 {len(from_admin0)} 個（NE 把它們當成獨立國家）："
              f"{'、'.join(str(f['properties']['name']) for f in from_admin0)}")
        drawn |= {f["properties"]["qid"] for f in from_admin0}

    # 一塊行政區都配不到時，只剩國家輪廓——那種檔 build-admin-manifest.py 不會收進索引
    # （沒有第一層就不給下鑽），寫出來也沒人讀。摩洛哥就是這樣：NE 的 16 個 feature
    # 全是 2015 改制前的大區，一個都不能用。檢查要放在 admin-0 退路**之後**，
    # 否則只能靠那條退路救回的國家會被提早略過。
    if not matched and not from_admin0:
        print("  沒有任何行政區配得上，略過（只剩輪廓的檔不值得寫）")

        return False

    no_geometry = [f"{children[q]}（{q}）" for q in children if q not in drawn]

    if no_geometry:
        print(f"  第一層沒有形狀（不畫）：{'、'.join(no_geometry)}")

    payload = [
        {
            "type": "Feature",
            "properties": {"qid": country_qid, "name": outline["properties"].get("NAME"), "level": 0},
            "geometry": outline["geometry"],
        },
        *matched,
        *dissolved,
        *from_admin0,
    ]

    print(f"  簡化前頂點 {count_vertices(payload):,} → ", end="", flush=True)
    simplified = simplify(payload, percent, country_qid)
    assert_clockwise(simplified)
    print(f"簡化後 {count_vertices(simplified):,}")

    features = sorted(
        (
            {
                "type": "Feature",
                # id 放 QID：globe.gl 的 polygon 物件直接帶著它，前端比對子節點時
                # 不用再挖 properties
                "id": f["properties"]["qid"],
                "properties": {"name": f["properties"]["name"], "level": f["properties"]["level"]},
                "geometry": f["geometry"],
            }
            for f in simplified
            if f.get("geometry")
        ),
        key=lambda f: (f["properties"]["level"], f["id"]),
    )

    text = json.dumps(
        {"type": "FeatureCollection", "features": features},
        ensure_ascii=False,
        separators=(",", ":"),
    )
    out_path = OUT_DIR / f"{country_qid}.json"
    existing = (
        json.loads(out_path.read_text(encoding="utf-8"))["features"]
        if out_path.exists()
        else []
    )
    # 只數「對得上圖譜第一層」的，不要數原始筆數。舊管線照 Wikidata 判層，產出的檔案
    # 裡常有圖譜沒有的節點（美國的華盛頓市 Q61、利比亞的班加西市 Q40816、法國的克利伯頓島
    # Q161258…），那些本來就不該畫——面板列不出來。用原始筆數比會把「拿掉它們」誤判成倒退：
    # 馬爾地夫實測 20 → 19 被擋，但對得上圖譜的其實是 18 → 19，是改善。
    before = sum(
        1 for f in existing
        if f["properties"].get("level") == 1 and f.get("id") in children
    )
    after = sum(1 for f in features if f["properties"]["level"] == 1)
    print(f"  {out_path.relative_to(REPO_ROOT)}：{len(existing)} → {len(features)} feature"
          f"（第一層 {before} → {after}）／{len(text.encode()) / 1024:.0f} KB")

    if check:
        print("  --check：沒有寫檔")

        return False

    # 幾何缺漏是靜默的——配不到就少一塊，畫面上看起來只是「這國行政區比較少」。
    # 第一層變少幾乎都是上游出了事（NE 改版、圖譜關係被刪、dissolve 覆蓋率掉到門檻下），
    # 擋下來讓人看一眼，不要默默覆蓋掉好的產出。
    if after < before and not force:
        print(f"  ❌ 拒絕寫入：第一層會從 {before} 掉到 {after}。"
              "先確認是上游資料真的變了，還是配對出問題。確定要覆蓋請加 --force。")

        return False

    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(text, encoding="utf-8")
    print("  已寫入")

    return True


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("countries", nargs="+", help="國家 QID，例如 Q865")
    parser.add_argument(
        "--simplify", type=float, default=DEFAULT_SIMPLIFY,
        help=f"保留頂點比例（%%），預設 {DEFAULT_SIMPLIFY}——跟全量管線一致，不要隨意改",
    )
    parser.add_argument(
        "--coverage", type=float, default=DEFAULT_COVERAGE,
        help=f"dissolve 的子節點覆蓋率門檻，預設 {DEFAULT_COVERAGE}（低於此比例就不併）",
    )
    parser.add_argument("--check", action="store_true", help="只印對照結果，不寫檔")
    parser.add_argument(
        "--force", action="store_true",
        help="即使第一層數量會變少也照寫。預設擋下來，避免靜默毀掉需要 dissolve 的國家",
    )
    args = parser.parse_args()

    print("來源：")
    admin0 = fetch_source(NE_ADMIN0, "ne_10m_admin_0.geojson")
    admin1 = fetch_source(NE_ADMIN1, "ne_10m_admin_1.geojson")

    written = [
        country_qid
        for country_qid in args.countries
        if rebuild(
            country_qid, admin0, admin1, args.simplify, args.coverage, args.check, args.force
        )
    ]

    if written:
        print("\n⚠️ 記得重跑 python3 scripts/build-admin-manifest.py 更新 index.json")

    return 0


if __name__ == "__main__":
    sys.exit(main())
