# taskctl — 任務 MCP CLI（給 Claude 的使用說明）

`taskctl` 是打 `https://ohya.vip/api/mcp/task` 的精簡 Go CLI，用來讀寫**跨專案任務清單**（task + checklist 子項）。
在任何專案裡，當需要記錄 / 查詢 / 更新待辦事項時，優先用它（用 Bash 呼叫即可，毋須開 native MCP，省 context）。

## 前置需求

1. `taskctl` 在 PATH 上（release 內含 binary；或 `cd cmd/taskctl && go build -o taskctl .` 後 symlink 到 `~/.local/bin`）。
2. token：`export MCP_TASK_TOKEN=<key>`（或共用 `MCP_TOKEN`）。未設且當前目錄樹無 `.vscode/mcp.json` 時，binary 會提示「未設定 API key」。
   - 解析優先序：`MCP_TASK_TOKEN` > `MCP_TOKEN` > `.vscode/mcp.json`（從當前目錄往上層找）。

> **完整、最新用法以 `taskctl`（不帶參數）印出的 usage 為準**；下表為速查。

## 指令速查

| 指令 | 說明 |
|------|------|
| `taskctl ls [--status s] [--project p]` | 列出所有任務（含子項）；可依 status(`todo`/`in_progress`/`done`) 或 project 篩選 |
| `taskctl get <id>` | 取單一任務及其所有子項 |
| `taskctl add [--project p] [--desc d] [--status s] <title...>` | 建立任務 |
| `taskctl set <id> [--title t] [--status s] [--project p] [--desc d] [--sort n]` | 更新任務（只送有帶的旗標） |
| `taskctl rm <id>` | 刪除任務及其所有子項（不可復原） |
| `taskctl iadd <task_id> <content...>` | 在任務下新增 checklist 子項 |
| `taskctl iset <id> [--content c] [--done] [--sort n]` | 更新子項文字 / 完成狀態 |
| `taskctl irm <id>` | 刪除子項 |

任意指令加 `--json` 印原始 JSON（預設為精簡文字輸出）。

## 範例

```bash
taskctl ls --status in_progress              # 進行中的任務
taskctl add --project linebot "接 webhook"   # 在 linebot 專案下開任務
taskctl iadd 12 "寫單元測試"                  # 在任務 #12 下加子項
taskctl iset 34 --done                       # 把子項 #34 標記完成
taskctl set 12 --status done                 # 任務 #12 收尾
```

## 注意

- `id` 取自 `ls` / `get` 回傳，別自己猜。
- `rm` 會連同子項一起刪、不可復原，刪除前先 `get` 確認。
- `--project` 是自由字串，用來跨專案歸類同一台機器上的多個 repo。
