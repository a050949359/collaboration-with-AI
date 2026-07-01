#!/usr/bin/env bash
# codegraph-index.sh — 編譯 codegraph 並索引本 repo，產出唯讀結構圖 SQLite。
#
# 跑在「有全套依賴 + 記憶體夠」的地方（目前是 GitHub-hosted ci job，非 VPS）：
#   composer install(含 dev→nikic) + npm i(node_modules) 之後呼叫本腳本。
# 產物 database/codegraph.db 由 ci 以 artifact 上傳，deploy job 下載落到 VPS 供
# Laravel 的 codegraph 連線（網頁 /app/codegraph、API /api/codegraph/graph、
# MCP /api/mcp/codegraph）唯讀讀取。VPS 完全不 index、不吃記憶體。
#
# 需求：go、php + vendor(nikic/php-parser)、node + node_modules(typescript/@vue/compiler-sfc)。
# 任一語言依賴缺席時，該 extractor 只 warn 不中斷（產出部分圖）；NODE_PATH 由 codegraph 自帶。
set -euo pipefail

# 一律以 repo 根為工作目錄（相對路徑 . 與 database/ 才正確，且不受呼叫端 cwd 影響）
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DB="${CODEGRAPH_DB:-database/codegraph.db}"

echo "▶ 編譯 codegraph…"
( cd cmd/codegraph && go build -o codegraph . )

echo "▶ 索引 $ROOT → $DB"
cmd/codegraph/codegraph index . --db "$DB"

echo "✓ codegraph 索引完成：$DB"
