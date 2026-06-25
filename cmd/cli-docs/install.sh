#!/usr/bin/env bash
# install.sh — 安裝 memctl / taskctl 到本機，並讓 Claude 全域認得這些工具。
#
# 冪等：可重複執行（重跑只更新自己寫的區段，不動你其他內容）。
# 從解壓後的套件根目錄執行，同層需有 bin/ 與 cli-docs/。
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
BIN_DIR="${HOME}/.local/bin"
DOCS_DIR="${HOME}/.claude/cli-docs"
GLOBAL_MD="${HOME}/.claude/CLAUDE.md"

# 需從解壓後的套件根目錄執行（同層要有 bin/ 與 cli-docs/）。
# 直接在 repo 的 cmd/cli-docs/ 執行會找不到，給明確錯誤而非靜默失敗。
if [ ! -d "$HERE/bin" ] || [ ! -d "$HERE/cli-docs" ]; then
	echo "✗ 找不到 $HERE/bin 或 $HERE/cli-docs" >&2
	echo "  請從解壓後的 release 套件根目錄執行此腳本。" >&2
	exit 1
fi

# 1. binaries → PATH
mkdir -p "$BIN_DIR"
for b in memctl taskctl; do
	if [ -f "$HERE/bin/$b" ]; then
		install -m 755 "$HERE/bin/$b" "$BIN_DIR/$b"
		echo "✓ binary → $BIN_DIR/$b"
	fi
done

# 2. docs → ~/.claude/cli-docs
mkdir -p "$DOCS_DIR"
cp "$HERE/cli-docs/"*.md "$DOCS_DIR/"
echo "✓ docs   → $DOCS_DIR/"

# 3. 全域 CLAUDE.md 加冪等指標段（帶標記，重跑只替換該段；無檔則建）
mkdir -p "$(dirname "$GLOBAL_MD")"
touch "$GLOBAL_MD"
MARK_START="<!-- >>> mcp-cli (memctl/taskctl) >>> -->"
MARK_END="<!-- <<< mcp-cli (memctl/taskctl) <<< -->"

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
# 移除舊區段（含標記），並去掉尾端多餘空白行——否則重跑時前置空行會逐次累積
awk -v s="$MARK_START" -v e="$MARK_END" '
	$0==s {skip=1}
	skip && $0==e {skip=0; next}
	!skip {print}
' "$GLOBAL_MD" | awk 'NF{last=NR} {line[NR]=$0} END{for(i=1;i<=last;i++) print line[i]}' >"$tmp"

# 再附上最新區段（與既有內容間僅留一行空行；檔案原本為空則不留前導空行）
{
	if [ -s "$tmp" ]; then
		cat "$tmp"
		printf '\n'
	fi
	printf '%s\n' "$MARK_START"
	cat <<'EOF'
## 本機 MCP CLI（memctl / taskctl）

本機裝有 `memctl`（跨專案知識圖譜）與 `taskctl`（跨專案任務）兩個 CLI，已在 PATH。
任何專案需要讀寫**跨專案**任務或知識圖譜時優先用它們，毋須開 native MCP（省 context）。

- 完整說明：`~/.claude/cli-docs/memctl.md`、`~/.claude/cli-docs/taskctl.md`
- 用法：直接執行 `memctl` / `taskctl`（不帶參數）即印完整 usage
- token：需 `export MCP_MEMORY_TOKEN` / `MCP_TASK_TOKEN`（或共用 `MCP_TOKEN`）
EOF
	printf '%s\n' "$MARK_END"
} >"$GLOBAL_MD"
echo "✓ 指標 → $GLOBAL_MD"

# 4. 下一步提醒
cat <<'EOF'

── 下一步 ──
1. 若尚未設定 token，加進 ~/.bashrc：
     export MCP_TASK_TOKEN=<task-key>
     export MCP_MEMORY_TOKEN=<memory-key>
2. 確認 ~/.local/bin 在 PATH 上（多數發行版預設已是）。
3. 新開 shell 後執行 `taskctl`（不帶參數）驗證。
EOF
