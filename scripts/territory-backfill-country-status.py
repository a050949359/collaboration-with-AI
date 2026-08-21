#!/usr/bin/env python3
"""territory-backfill-country-status.py — 一次性 backfill：補齊「status 欄位加入
territory-import-countries.py 之前」就已匯入 territory MCP 的國家 entity，各補一條
status observation（sovereign / dependency / dissolved / unclaimed）。

背景：territory-import-countries.py 最初只寫了 recognized: yes/no（布林值），沒有區分
「依附其他國家的屬地」跟「已解體的歷史政權」這兩種完全不同的 is_recognized=no；
後來 build_observations() 已經補上 status 欄位（見該腳本），所以新匯入的國家不需要
這支腳本。這支腳本只用來補那批舊資料的 status，不會重跑 create_entity 或其他既有欄位
（避免重複寫入——add_observation 不是冪等的，重跑這支腳本會產生重複 status，
所以只該執行一次；如果真的需要重跑，先用 remove_observation 清掉舊的 status）。

用法：
  python3 scripts/territory-backfill-country-status.py --dry-run
  python3 scripts/territory-backfill-country-status.py
"""

import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from territory_lib import call_tool, country_status, get_countries_with_qid, resolve_endpoint, resolve_token  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true", help="Preview without writing")
    args = parser.parse_args()

    print("Fetching countries + QIDs (Wikidata + local countries table)...")
    countries = get_countries_with_qid()
    print(f"Countries: {len(countries)}")

    if not args.dry_run:
        token = resolve_token()
        endpoint = resolve_endpoint()
        print(f"Writing to: {endpoint}")

    counts = {}
    for country in countries:
        status = country_status(country["code"], country.get("is_recognized"))
        counts[status] = counts.get(status, 0) + 1

        if args.dry_run:
            print(f"  {country['code']} ({country['qid']}) | {country['name_en']} -> status: {status}")
            continue

        is_error, text = call_tool(
            endpoint, token, "add_observation", {"entity_name": country["qid"], "type": "status", "content": status}
        )
        if is_error:
            print(f"  {country['code']} ({country['qid']}) add_observation failed: {text}", file=sys.stderr)

    print("Breakdown:", counts)
    return 0


if __name__ == "__main__":
    sys.exit(main())
