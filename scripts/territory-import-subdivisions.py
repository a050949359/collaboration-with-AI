#!/usr/bin/env python3
"""territory-import-subdivisions.py — 對每個「上層」節點，抓 Wikidata 候選下一層行政區，
用 agy 過濾可信度，把通過的寫進 territory MCP（entity + part_of relation）。

「上層」節點可以是國家（第一層行政區匯入，既有用法），也可以是任意已存在於 territory
圖譜裡的節點（例如某國的第一層行政區，用來匯入第二層）——見下方 --under 用法。

用法：
  # 第一層（上層 = 國家，來源是本機 countries 表，既有用法不變）
  python3 scripts/territory-import-subdivisions.py --dry-run --countries Q865
  python3 scripts/territory-import-subdivisions.py --countries Q865,Q30
  python3 scripts/territory-import-subdivisions.py            # 全部 259 個國家

  # 第二層（或更深）：--under <QID> 會先用 read_graph 抓 <QID> 在圖譜裡既有的直屬子節點
  # （part_of 指向 <QID> 的節點），把每一個子節點當作這一輪的「上層」，往下再抓一層 P150。
  python3 scripts/territory-import-subdivisions.py --dry-run --under Q142      # 法國第一層底下每個行政區，各自試抓第二層
  python3 scripts/territory-import-subdivisions.py --under Q142 --countries Q1490   # 只對某個子節點（例如東京）跑第二層
                                                                                       # 注意：--countries 在 --under 模式下是用來篩選
                                                                                       # 「上層」清單本身的 QID，不是篩選第一層/第二層。

流程（每個「上層」節點一輪）：
  1. SPARQL 查 wdt:P150（"contains administrative territorial entity"，上層 -> 直屬下一層）
     取得候選清單：QID + label(en/zh-tw) + description + P31 instance-of label，純粹給 agy
     判斷用，不會拿來寫入 observation（見第 4 點）。
  2. 把候選清單整段放進 prompt，呼叫本機 agy CLI（純文字問答，不給任何工具權限，
     agy 不需要自己查資料或執行任何指令，只需要根據 prompt 裡的資料做判斷）。
  3. 解析 agy 回傳的 JSON（{"accepted": [{"qid","type"}, ...], "rejected": [{"qid","reason"}, ...]}）。
  4. 對 accepted 的每個 QID：
       create_entity(name=QID, type=<agy 判斷選出的 type>)     — 同步呼叫，firstOrCreate 保護，
       create_relation(from=QID, to=上層QID, relation_type="part_of")  — 同步呼叫，triple 唯一性保護
       refresh_observations(entity_name=QID) — 非同步：建一筆 job 丟進 Laravel queue，
       由伺服器端「自己重新打一次 Wikidata」撈 label/description/instance_of/座標/人口/面積，
       依欄位分別寫入 observation。這支腳本完全不碰 observation 的實際內容，
       第 1 步撈的候選資料只用於餵給 agy 判斷 accept/reject，不會被拿去寫入。
     entity/relation/refresh_observations 三個呼叫都是冪等或可安全重複觸發，
     就算中途被中斷，重跑也不會產生重複資料。

agy 呼叫失敗、JSON 解析失敗、單一寫入呼叫失敗都只印錯誤、跳過該上層節點或該候選，
不會讓整支腳本中斷。

依賴 scripts/territory_lib.py（token 解析、call_tool）與本機 `agy` CLI（需在 PATH 上）。
"""

import argparse
import json
import re
import subprocess
import sys
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from territory_lib import (  # noqa: E402
    USER_AGENT,
    WIKIDATA_ENDPOINT,
    call_tool,
    get_countries_with_qid,
    resolve_endpoint,
    resolve_token,
)

AGY_MODEL = "Gemini 3.1 Pro (High)"
AGY_TIMEOUT_SECONDS = 120

SUBDIVISION_SPARQL_TEMPLATE = """
SELECT ?subdivision ?subdivisionLabel ?subdivisionDescription
       (GROUP_CONCAT(DISTINCT ?instanceOfLabel; separator=", ") AS ?instanceOfLabels)
WHERE {{
  wd:{qid} wdt:P150 ?subdivision .
  OPTIONAL {{
    ?subdivision wdt:P31 ?instanceOf .
    ?instanceOf rdfs:label ?instanceOfLabel .
    FILTER(LANG(?instanceOfLabel) = "en")
  }}
  SERVICE wikibase:label {{ bd:serviceParam wikibase:language "en,zh-tw". }}
}}
GROUP BY ?subdivision ?subdivisionLabel ?subdivisionDescription
"""

JUDGE_PROMPT_TEMPLATE = """你是一位資深地理／行政區資料審核員，要判斷下面這批 Wikidata 候選實體，
是不是「{parent_name}（{parent_qid}）」目前真實有效的下一層行政區（例如省/州/直轄市，或再
往下一層的縣/市/區這類——實際層級由 {parent_name} 自身在行政體系中的位置決定，不是村里等
更細的層級，也不是山脈河流這類非行政區地理實體）。

## 重要限制
你不需要、也沒有被授權執行任何指令或工具——所有需要的資料都已經在下面的候選清單裡，
直接根據這份資料做判斷即可，不要嘗試查證、不要嘗試執行任何 script 或指令。

## 候選清單（JSON）
{candidates_json}

## 判斷原則
0. **清單裡每一個候選都已經由 Wikidata 的 P150 關係結構性確認是 {parent_name} 的直屬子行政區**
   （這份清單本身就是照著 {parent_name} 的 P150 撈出來的，不是關鍵字搜尋撈出來的雜訊）。
   description 欄位常常只會寫成通用的一句話（例如 "department of France"、"French department"），
   **不會特地把上層名稱重複寫進去**——這是正常現象，**不是**「查不到隸屬關係」的證據，
   絕對不要因為 description 沒有明確提到 {parent_name} 這個名字就 reject，那不是這裡要判斷的問題。
   你唯一要判斷的是：這個候選**現在**還是不是一個**有效、現行**的行政區（見下面第 2、3 點的判準）。
1. 只根據上面提供的 label / description / instance_of 判斷，不要腦補清單以外的資訊。
2. 濾掉：已廢除／歷史行政區（description 提到 "former"、"abolished"、"historical" 等）、
   跟其他候選明顯是同一個地方的重複實體、非行政區性質的地理實體（山脈、河流、地區泛稱等
   誤入候選清單的雜訊）。
3. 不確定的候選（instance_of 完全看不出是不是行政區性質，或有明確跡象顯示可能已廢除/歷史化）
   才歸類到 rejected，reason 寫「需人工確認：<原因>」——但**「description 沒有寫出上層名稱」
   本身不構成不確定的理由**（見第 0 點），不要因此放到 rejected。
4. 這次只抓 {parent_name} 直屬的下一層，不用判斷更深的子行政區。
5. 對每個 accepted 的候選，從它的 instance_of 清單裡挑一個**最能代表其行政區性質**的詞當 type
   （例如 instance_of 是 "city, big city, special municipality" 時，"special municipality" 比
   "city" 更能代表這是行政區，優先選它；沒有明顯行政區性質詞彙時才退回用最基本的 "city"/"state"
   之類）。type 只用小寫、單字或用底線連接的簡短英文（例如 "special_municipality"、"state"、
   "province"），不要整句話，**也不要把上層名稱/國籍形容詞包進去**（instance_of 常常是
   "province of Thailand"、"metropolitan city of South Korea" 這種格式，只取
   "province"、"metropolitan_city" 這個核心詞，"of Thailand"/"of South Korea" 這段要整個
   去掉——上層脈絡已經由 create_relation 的 part_of 關係表達，type 裡重複寫上層名稱只會讓同性質
   的行政區在不同上層之間變成不同 type，破壞跨層級比對的一致性）。

## 輸出格式
只輸出一個 JSON 物件，不要有其他文字、不要用 markdown code fence 包起來：
{{"accepted": [{{"qid": "Q...", "type": "special_municipality"}}, ...], "rejected": [{{"qid": "Q...", "reason": "..."}}, ...]}}
"""


def fetch_subdivision_candidates(parent_qid: str) -> list:
    query = SUBDIVISION_SPARQL_TEMPLATE.format(qid=parent_qid)
    url = WIKIDATA_ENDPOINT + "?" + urllib.parse.urlencode({"query": query, "format": "json"})
    req = urllib.request.Request(
        url,
        headers={"Accept": "application/sparql-results+json", "User-Agent": USER_AGENT},
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        data = json.loads(resp.read())

    candidates = {}
    for b in data.get("results", {}).get("bindings", []):
        uri = b.get("subdivision", {}).get("value", "")
        if not uri:
            continue
        qid = uri.rsplit("/", 1)[-1]
        candidates.setdefault(
            qid,
            {
                "qid": qid,
                "label": b.get("subdivisionLabel", {}).get("value", ""),
                "description": b.get("subdivisionDescription", {}).get("value", ""),
                "instance_of": b.get("instanceOfLabels", {}).get("value", ""),
            },
        )
    return list(candidates.values())


def judge_candidates(parent_qid: str, parent_name: str, candidates: list) -> dict:
    prompt = JUDGE_PROMPT_TEMPLATE.format(
        parent_name=parent_name,
        parent_qid=parent_qid,
        candidates_json=json.dumps(candidates, ensure_ascii=False, indent=2),
    )
    try:
        result = subprocess.run(
            ["agy", "-p", prompt, "--model", AGY_MODEL, "--print-timeout", f"{AGY_TIMEOUT_SECONDS}s"],
            capture_output=True,
            text=True,
            timeout=AGY_TIMEOUT_SECONDS + 10,
        )
    except (subprocess.TimeoutExpired, FileNotFoundError) as e:
        print(f"  agy invocation failed: {e}", file=sys.stderr)
        return {"accepted": [], "rejected": []}

    if result.returncode != 0:
        print(f"  agy exited {result.returncode}: {result.stderr.strip()}", file=sys.stderr)
        return {"accepted": [], "rejected": []}

    match = re.search(r"\{.*\}", result.stdout, re.DOTALL)
    if not match:
        print(f"  agy output had no JSON: {result.stdout[:200]!r}", file=sys.stderr)
        return {"accepted": [], "rejected": []}

    try:
        return json.loads(match.group(0))
    except json.JSONDecodeError as e:
        print(f"  failed to parse agy JSON: {e}", file=sys.stderr)
        return {"accepted": [], "rejected": []}


def sanitize_type(raw_type: str) -> str:
    # agy 應該已經回傳小寫底線格式，這裡只是防呆（避免多餘空白/大寫/標點混進去）。
    # 先做 NFKD 正規化 + 只留 ASCII，把重音符號轉成基底字母（例如日文羅馬拼音的 "dō" -> "do"）
    # 再清理，避免下面的正則表達式把整個重音字元直接砍掉（"dō" 曾經被砍成 "d"）。
    normalized = unicodedata.normalize("NFKD", raw_type or "").encode("ascii", "ignore").decode("ascii")
    cleaned = re.sub(r"[^a-zA-Z0-9]+", "_", normalized.strip()).strip("_").lower()
    return cleaned or "subdivision"


def fetch_entity_label(entity_qid: str, endpoint: str, token: str) -> str:
    """對單一節點呼叫 read_graph，取它的 label / label_en observation 當人類可讀名稱
    （行政區欄位是 label，國家欄位是 label_en——見 WriteTerritoryObservationJob），
    取不到就退回用 QID 本身。"""
    is_error, text = call_tool(endpoint, token, "read_graph", {"entity_name": entity_qid})
    if is_error:
        return entity_qid
    try:
        data = json.loads(text)
    except json.JSONDecodeError:
        return entity_qid
    entities = data.get("entities", [])
    if not entities:
        return entity_qid
    for obs in entities[0].get("observations", []):
        if obs.get("type") in ("label", "label_en"):
            return obs.get("content") or entity_qid
    return entity_qid


def fetch_children(parent_qid: str, endpoint: str, token: str) -> list:
    """呼叫 territory MCP 的 read_graph，取得 parent_qid 在圖譜裡既有的直屬子節點
    （part_of 關係指向 parent_qid 的節點），回傳 [{"qid","name"}, ...]——供 --under
    模式當作這一輪要處理的「上層」清單（例如抓某國第一層底下每個行政區，逐一往下抓第二層）。
    注意：read_graph(entity_name=X) 只回傳 X 這一個節點本身的 observations，relations 裡
    列出的子節點只有 QID，沒有附帶各自的 observations——所以每個子節點的顯示名稱需要
    再各自呼叫一次 read_graph 才能取得（見 fetch_entity_label），不能從第一次呼叫的
    entities 清單裡直接查表。"""
    is_error, text = call_tool(endpoint, token, "read_graph", {"entity_name": parent_qid})
    if is_error:
        print(f"read_graph({parent_qid}) failed: {text}", file=sys.stderr)
        return []
    try:
        data = json.loads(text)
    except json.JSONDecodeError as e:
        print(f"read_graph({parent_qid}) returned non-JSON: {e}", file=sys.stderr)
        return []

    child_qids = [
        rel.get("from")
        for rel in data.get("relations", [])
        if rel.get("relation_type") == "part_of" and rel.get("to") == parent_qid
    ]
    return [{"qid": qid, "name": fetch_entity_label(qid, endpoint, token)} for qid in child_qids]


def process_parent(parent_qid: str, parent_name: str, endpoint: str, token: str, dry_run: bool) -> None:
    print(f"=== {parent_name} ({parent_qid}) ===")
    try:
        candidates = fetch_subdivision_candidates(parent_qid)
    except (urllib.error.URLError, TimeoutError, OSError, json.JSONDecodeError) as e:
        # Wikidata 查詢服務偶爾會 429/502/read-timeout（尤其背靠背連續呼叫很多個上層節點時，
        # 見 territory-subdivisions 記憶檔的 rate-limiting 記錄）——這裡沒接住的話，單一上層節點
        # 的暫時性網路錯誤會讓整支腳本直接掛掉，白白浪費前面已經處理完的上層節點進度。
        # 印錯誤、跳過這個上層節點，讓迴圈可以繼續處理下一個——跟 agy 呼叫失敗/寫入失敗的既有
        # 容錯策略一致，重跑這支腳本（或事後單獨用 --countries 補跑這個 QID）是安全的。
        print(f"  fetch_subdivision_candidates({parent_qid}) failed: {e}", file=sys.stderr)
        return
    print(f"  candidates: {len(candidates)}")
    for c in candidates:
        print(f"    {c['qid']} | {c['label']} | {c['instance_of']}")
    if not candidates:
        return

    judgment = judge_candidates(parent_qid, parent_name, candidates)
    accepted = judgment.get("accepted", [])
    rejected = judgment.get("rejected", [])
    print(f"  agy accepted: {len(accepted)}, rejected: {len(rejected)}")
    for a in accepted:
        print(f"    accepted {a.get('qid')}: type={a.get('type')}")
    for r in rejected:
        print(f"    rejected {r.get('qid')}: {r.get('reason')}")

    if dry_run:
        return

    by_qid = {c["qid"]: c for c in candidates}
    for item in accepted:
        qid = item.get("qid")
        candidate = by_qid.get(qid)
        if not candidate:
            print(f"    {qid} accepted but not in candidate list, skipped", file=sys.stderr)
            continue

        entity_type = sanitize_type(item.get("type", ""))
        is_error, text = call_tool(endpoint, token, "create_entity", {"name": qid, "type": entity_type})
        if is_error:
            print(f"    {qid} create_entity failed: {text}", file=sys.stderr)
            continue

        is_error, text = call_tool(endpoint, token, "refresh_observations", {"entity_name": qid})
        if is_error:
            print(f"    {qid} refresh_observations failed: {text}", file=sys.stderr)

        is_error, text = call_tool(
            endpoint,
            token,
            "create_relation",
            {"from": qid, "to": parent_qid, "relation_type": "part_of"},
        )
        if is_error:
            print(f"    {qid} create_relation failed: {text}", file=sys.stderr)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true", help="Preview candidates + agy judgment without writing")
    parser.add_argument(
        "--countries",
        help="Comma-separated parent QIDs to limit to (e.g. Q865,Q30). Without --under, filters the "
        "259-country layer-1 list; with --under, filters the --under parent's children instead.",
    )
    parser.add_argument(
        "--under",
        metavar="PARENT_QID",
        help="任意父層 QID：用 read_graph 抓 PARENT_QID 在圖譜裡既有的直屬子節點（part_of 指向它的節點），"
        "把每個子節點當作這一輪要處理的「上層」，各自往下抓一層 P150（用於第二層以上，例如 --under Q142"
        "會對法國第一層的每個行政區各自嘗試匯入第二層）。可搭配 --countries 篩選只處理其中幾個子節點。",
    )
    args = parser.parse_args()

    if args.under:
        # --under 需要真的呼叫 MCP 的 read_graph 才能列出既有子節點，跟純本機 Wikidata SPARQL 的
        # 第一層路徑不同——即使是 --dry-run 也要先解析 token/endpoint 來讀圖（dry-run 只跳過後面的寫入）。
        token = resolve_token()
        endpoint = resolve_endpoint()
        print(f"Fetching children of {args.under} from territory graph...")
        parents = fetch_children(args.under, endpoint, token)
        print(f"Children found: {len(parents)}")
    else:
        print("Fetching countries + QIDs (Wikidata + local countries table)...")
        parents = [{"qid": c["qid"], "name": c["name_en"]} for c in get_countries_with_qid()]
        print(f"Countries available: {len(parents)}")
        token = endpoint = None

    if args.countries:
        wanted = set(args.countries.split(","))
        parents = [p for p in parents if p["qid"] in wanted]
        print(f"Filtered to: {len(parents)} ({', '.join(p['qid'] for p in parents)})")

    if not args.dry_run and token is None:
        token = resolve_token()
        endpoint = resolve_endpoint()
    if not args.dry_run:
        print(f"Writing to: {endpoint}")

    for parent in parents:
        process_parent(parent["qid"], parent["name"], endpoint, token, args.dry_run)

    return 0


if __name__ == "__main__":
    sys.exit(main())
