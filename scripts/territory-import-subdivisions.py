#!/usr/bin/env python3
"""territory-import-subdivisions.py — 對每個已匯入的國家，抓 Wikidata 候選行政區，
用 agy 過濾可信度，把通過的寫進 territory MCP（entity + part_of relation）。

用法：
  python3 scripts/territory-import-subdivisions.py --dry-run --countries Q865
  python3 scripts/territory-import-subdivisions.py --countries Q865,Q30
  python3 scripts/territory-import-subdivisions.py            # 全部 259 個國家

流程（每個國家一輪）：
  1. SPARQL 查 wdt:P150（"contains administrative territorial entity"，國家 -> 直屬行政區）
     取得候選清單：QID + label(en/zh-tw) + description + P31 instance-of label，純粹給 agy
     判斷用，不會拿來寫入 observation（見第 4 點）。
  2. 把候選清單整段放進 prompt，呼叫本機 agy CLI（純文字問答，不給任何工具權限，
     agy 不需要自己查資料或執行任何指令，只需要根據 prompt 裡的資料做判斷）。
  3. 解析 agy 回傳的 JSON（{"accepted": [{"qid","type"}, ...], "rejected": [{"qid","reason"}, ...]}）。
  4. 對 accepted 的每個 QID：
       create_entity(name=QID, type=<agy 判斷選出的 type>)     — 同步呼叫，firstOrCreate 保護，
       create_relation(from=QID, to=國家QID, relation_type="part_of")  — 同步呼叫，triple 唯一性保護
       refresh_observations(entity_name=QID) — 非同步：建一筆 job 丟進 Laravel queue，
       由伺服器端「自己重新打一次 Wikidata」撈 label/description/instance_of/座標/人口/面積，
       依欄位分別寫入 observation。這支腳本完全不碰 observation 的實際內容，
       第 1 步撈的候選資料只用於餵給 agy 判斷 accept/reject，不會被拿去寫入。
     entity/relation/refresh_observations 三個呼叫都是冪等或可安全重複觸發，
     就算中途被中斷，重跑也不會產生重複資料。

agy 呼叫失敗、JSON 解析失敗、單一寫入呼叫失敗都只印錯誤、跳過該國家或該候選，
不會讓整支腳本中斷。

依賴 scripts/territory_lib.py（token 解析、call_tool）與本機 `agy` CLI（需在 PATH 上）。
"""

import argparse
import json
import re
import subprocess
import sys
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
是不是「{country_name}（{country_qid}）」目前真實有效的第一層行政區（省/州/直轄市這類，
不是村里等更細的層級，也不是山脈河流這類非行政區地理實體）。

## 重要限制
你不需要、也沒有被授權執行任何指令或工具——所有需要的資料都已經在下面的候選清單裡，
直接根據這份資料做判斷即可，不要嘗試查證、不要嘗試執行任何 script 或指令。

## 候選清單（JSON）
{candidates_json}

## 判斷原則
1. 只根據上面提供的 label / description / instance_of 判斷，不要腦補清單以外的資訊。
2. 濾掉：已廢除／歷史行政區（description 提到 "former"、"abolished"、"historical" 等）、
   跟其他候選明顯是同一個地方的重複實體、非行政區性質的地理實體（山脈、河流、地區泛稱等
   誤入候選清單的雜訊）。
3. 不確定的候選（描述不清楚、看不出是不是現行行政區）一律歸類到 rejected，
   reason 寫「需人工確認：<原因>」，不要因為想幫忙而放行有疑慮的候選。
4. 這是目前唯一一層（只抓國家直屬的第一層），不用判斷更深的子行政區。
5. 對每個 accepted 的候選，從它的 instance_of 清單裡挑一個**最能代表其行政區性質**的詞當 type
   （例如 instance_of 是 "city, big city, special municipality" 時，"special municipality" 比
   "city" 更能代表這是第一層行政區，優先選它；沒有明顯行政區性質詞彙時才退回用最基本的 "city"/"state"
   之類）。type 只用小寫、單字或用底線連接的簡短英文（例如 "special_municipality"、"state"、
   "province"），不要整句話。

## 輸出格式
只輸出一個 JSON 物件，不要有其他文字、不要用 markdown code fence 包起來：
{{"accepted": [{{"qid": "Q...", "type": "special_municipality"}}, ...], "rejected": [{{"qid": "Q...", "reason": "..."}}, ...]}}
"""


def fetch_subdivision_candidates(country_qid: str) -> list:
    query = SUBDIVISION_SPARQL_TEMPLATE.format(qid=country_qid)
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


def judge_candidates(country_qid: str, country_name: str, candidates: list) -> dict:
    prompt = JUDGE_PROMPT_TEMPLATE.format(
        country_name=country_name,
        country_qid=country_qid,
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
    cleaned = re.sub(r"[^a-zA-Z0-9]+", "_", (raw_type or "").strip()).strip("_").lower()
    return cleaned or "subdivision"


def process_country(country_qid: str, country_name: str, endpoint: str, token: str, dry_run: bool) -> None:
    print(f"=== {country_name} ({country_qid}) ===")
    candidates = fetch_subdivision_candidates(country_qid)
    print(f"  candidates: {len(candidates)}")
    for c in candidates:
        print(f"    {c['qid']} | {c['label']} | {c['instance_of']}")
    if not candidates:
        return

    judgment = judge_candidates(country_qid, country_name, candidates)
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
            {"from": qid, "to": country_qid, "relation_type": "part_of"},
        )
        if is_error:
            print(f"    {qid} create_relation failed: {text}", file=sys.stderr)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true", help="Preview candidates + agy judgment without writing")
    parser.add_argument("--countries", help="Comma-separated country QIDs to limit to (e.g. Q865,Q30)")
    args = parser.parse_args()

    print("Fetching countries + QIDs (Wikidata + local countries table)...")
    countries = get_countries_with_qid()
    print(f"Countries available: {len(countries)}")

    if args.countries:
        wanted = set(args.countries.split(","))
        countries = [c for c in countries if c["qid"] in wanted]
        print(f"Filtered to: {len(countries)} ({', '.join(c['qid'] for c in countries)})")

    if not args.dry_run:
        token = resolve_token()
        endpoint = resolve_endpoint()
        print(f"Writing to: {endpoint}")
    else:
        token = endpoint = None

    for country in countries:
        process_country(country["qid"], country["name_en"], endpoint, token, args.dry_run)

    return 0


if __name__ == "__main__":
    sys.exit(main())
