#!/usr/bin/env python3
"""territory-import-countries.py — 把既有 (孤兒) countries 表灌進 territory MCP 知識圖譜。

用法：
  python3 scripts/territory-import-countries.py [--dry-run]

流程：
  1. 對 Wikidata SPARQL 打一次批次查詢，取得「ISO alpha-2 code -> QID」對照表
     （countries 表本身沒存 QID，只有 code/name/capital 這些衍生欄位）。
  2. 讀本機 database/database.sqlite 的 countries 表（唯讀，不動這張表），只用來取得
     要處理哪些國家的 QID 清單，不會拿表裡的欄位值組 observation。
  3. 對每一列，用 code 查出對應 QID，呼叫遠端 POST /api/mcp/territory：
       create_entity(name=QID, type="country")     — 同步呼叫，firstOrCreate 保護
       refresh_observations(entity_name=QID)        — 非同步：建一筆 job 丟進 Laravel
       queue，由伺服器端重新打一次 Wikidata（label_en/label_zh_tw/iso_code/alpha3/
       numeric/capital/phone_code/population/continent），並用查到的 iso_code 比對
       伺服器本機同一份 countries 表補上 recognized/status/notes，依欄位分別寫入
       observation。這支腳本完全不碰 observation 的實際內容，跟
       territory-import-subdivisions.py 走的是同一套機制（entity/relation 同步、
       observation 全部交給伺服器端非同步處理）。

不使用 Laravel bootstrap：純 stdlib（sqlite3 + urllib），可以在任何裝了 python3 的機器上跑，
只要能連到 Wikidata 與 territory MCP 端點即可。

Token 解析優先序：MCP_TERRITORY_TOKEN > MCP_TOKEN > .vscode/mcp.json（從當前目錄往上層找，
比對 url 以 /mcp/territory 結尾的那個 server entry）。
Base URL 可用 MCP_BASE_URL 覆寫（預設 https://ohya.vip/api/mcp）。
"""

import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from territory_lib import call_tool, get_countries_with_qid, resolve_endpoint, resolve_token  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true", help="Preview without writing")
    args = parser.parse_args()

    print("Fetching countries + QIDs (Wikidata + local countries table)...")
    countries = get_countries_with_qid()
    print(f"Resolved QIDs: {len(countries)}")

    if not args.dry_run:
        token = resolve_token()
        endpoint = resolve_endpoint()
        print(f"Writing to: {endpoint}")

    created = 0
    for country in countries:
        code = country["code"]
        qid = country["qid"]

        if args.dry_run:
            print(f"{code} -> {qid} | {country['name_en']}")
            continue

        is_error, text = call_tool(endpoint, token, "create_entity", {"name": qid, "type": "country"})
        if is_error:
            print(f"  {code} ({qid}) create_entity failed: {text}", file=sys.stderr)
            continue

        is_error, text = call_tool(endpoint, token, "refresh_observations", {"entity_name": qid})
        if is_error:
            print(f"  {code} ({qid}) refresh_observations failed: {text}", file=sys.stderr)

        created += 1

    if not args.dry_run:
        print(f"Done. created/updated: {created}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
