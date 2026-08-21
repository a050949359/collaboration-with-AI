#!/usr/bin/env python3
"""territory_lib.py — territory-import-*.py 共用的 token 解析 / MCP HTTP 呼叫邏輯。

不使用 Laravel bootstrap，純 stdlib（urllib），可以在任何裝了 python3 的機器上跑，
只要能連到 territory MCP 端點即可。

Token 解析優先序：MCP_TERRITORY_TOKEN > MCP_TOKEN > .vscode/mcp.json（從當前目錄往上層找，
比對 url 以 /mcp/territory 結尾的那個 server entry）。
Base URL 可用 MCP_BASE_URL 覆寫（預設 https://ohya.vip/api/mcp）。

add_observation 不是冪等的，行政區層（territory-import-subdivisions.py）批次補觀察時
改呼叫 refresh_observations tool（跟其他 tool 共用同一把 api-key）——這個 tool 只丟一個
job 進 Laravel queue，實際要寫哪些欄位是伺服器端自己重新打一次 Wikidata 決定的，腳本這端
不用組 content、也不用管冪等性。國家層（territory-import-countries.py）目前仍用同步的
add_observation，維持現狀不變。
"""

import json
import os
import sqlite3
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

WIKIDATA_ENDPOINT = "https://query.wikidata.org/sparql"
USER_AGENT = "collaboration-with-AI/1.0 (haroldchen@besttour.com.tw)"

REPO_ROOT = Path(__file__).resolve().parent.parent
DB_PATH = REPO_ROOT / "database" / "database.sqlite"

COUNTRY_QID_SPARQL = """
SELECT ?country ?code
WHERE {
  ?country wdt:P297 ?code .
}
"""


def resolve_token() -> str:
    for env in ("MCP_TERRITORY_TOKEN", "MCP_TOKEN"):
        if os.environ.get(env):
            return os.environ[env]

    cwd = Path.cwd()
    for d in [cwd, *cwd.parents]:
        candidate = d / ".vscode" / "mcp.json"
        if candidate.is_file():
            data = json.loads(candidate.read_text())
            for server in data.get("servers", {}).values():
                if server.get("url", "").endswith("/mcp/territory"):
                    token = server.get("headers", {}).get("Authorization", "")
                    if token.startswith("Bearer "):
                        return token[len("Bearer "):]
            break

    print(
        "ERROR: 找不到 territory MCP token。"
        "請 export MCP_TERRITORY_TOKEN=<key>，或確認 .vscode/mcp.json 有對應 server entry。",
        file=sys.stderr,
    )
    sys.exit(1)


def resolve_endpoint() -> str:
    base = os.environ.get("MCP_BASE_URL", "https://ohya.vip/api/mcp")
    return base.rstrip("/") + "/territory"


def fetch_country_qid_by_code() -> dict:
    """對 Wikidata 批次查詢 ISO alpha-2 code -> QID 對照表（countries 表本身沒存 QID）。"""
    url = WIKIDATA_ENDPOINT + "?" + urllib.parse.urlencode({"query": COUNTRY_QID_SPARQL, "format": "json"})
    req = urllib.request.Request(
        url,
        headers={"Accept": "application/sparql-results+json", "User-Agent": USER_AGENT},
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        data = json.loads(resp.read())

    qid_by_code = {}
    for b in data.get("results", {}).get("bindings", []):
        code = b.get("code", {}).get("value", "").upper()
        qid_uri = b.get("country", {}).get("value", "")
        if len(code) != 2 or not qid_uri:
            continue
        # 一個 code 理論上對應一個 QID；若 Wikidata 資料衝突有多個，取第一個並跳過其餘。
        qid_by_code.setdefault(code, qid_uri.rsplit("/", 1)[-1])
    return qid_by_code


def read_countries() -> list:
    """唯讀讀取本機 countries 表（已退役、僅供 territory 匯入腳本參考）。"""
    conn = sqlite3.connect(f"file:{DB_PATH}?mode=ro", uri=True)
    conn.row_factory = sqlite3.Row
    rows = conn.execute("SELECT * FROM countries ORDER BY code").fetchall()
    conn.close()
    return [dict(r) for r in rows]


def get_countries_with_qid() -> list:
    """回傳 countries 表所有列，各自附上 Wikidata QID（查不到的列會被跳過）。"""
    qid_by_code = fetch_country_qid_by_code()
    result = []
    for country in read_countries():
        qid = qid_by_code.get(country["code"])
        if qid:
            result.append({**country, "qid": qid})
    return result


# countries 表混了「現行主權國家」跟「依附其他國家的屬地／已解體的歷史政權」——
# is_recognized 只分兩類（yes/no），但 no 底下其實還有兩種完全不同的東西：
#   1. 依附其他國家的屬地（香港、Guam、格陵蘭…）：這些之後會在 territory-import-subdivisions.py
#      掃到它們真正的宗主國時，被 agy 判斷成正確的行政區 type、掛上 part_of 關係——
#      但因為 create_entity 是 firstOrCreate，已存在的 entity type 不會被覆寫，
#      所以這裡先補一個 status observation 說明它「其實是 dependency」，跟 type 欄位分開。
#   2. 真正已解體、不再屬於任何現行國家的歷史政權：不會被任何國家的 P150 掃到，
#      需要單獨標記，否則會一直頂著誤導性的 status。
DISSOLVED_CODES = {"DD", "YU", "AN", "PC"}  # 東德、南斯拉夫、荷屬安地列斯、太平洋群島託管地
UNCLAIMED_CODES = {"AQ"}  # 南極洲：國際條約下不屬於任何國家


def country_status(code: str, is_recognized) -> str:
    if code in DISSOLVED_CODES:
        return "dissolved"
    if code in UNCLAIMED_CODES:
        return "unclaimed"
    return "sovereign" if is_recognized else "dependency"


def call_tool(endpoint: str, token: str, name: str, arguments: dict) -> tuple:
    body = json.dumps(
        {"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": name, "arguments": arguments}}
    ).encode()
    req = urllib.request.Request(
        endpoint,
        data=body,
        headers={
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
            "User-Agent": USER_AGENT,
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            data = json.loads(resp.read())
    except urllib.error.HTTPError as e:
        return True, f"HTTP {e.code}: {e.read().decode(errors='replace')}"
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        return True, f"request failed: {e}"
    result = data.get("result", {})
    is_error = result.get("isError", False)
    text = (result.get("content") or [{}])[0].get("text", "")
    return is_error, text
