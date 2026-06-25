# taskctl — 任務 MCP CLI（給 Claude 的參考文件）

## 這是什麼

打 `https://ohya.vip/api/mcp/task` 的精簡 Go CLI client，用來讀寫**跨專案任務清單**（task + checklist 子項）。
是 native MCP 與冗長 curl 的替代：用 Bash 呼叫即可，不必開 native MCP 連線常駐（省 context token）。

## 何時用

在**任何專案**裡需要記錄 / 查詢 / 更新待辦事項時優先用它，特別是想跨 repo 追蹤同一台機器上多個專案的任務（用 `--project` 歸類）。

## 工具位置

- **source**：repo 內 `cmd/taskctl/`（Go module）。binary 已列入 `.gitignore`，clone 後沒有執行檔。
- **build**：`cd cmd/taskctl && go build -o taskctl .`
- **安裝**：build 後 symlink 到 PATH（如 `~/.local/bin/taskctl`），即可在任何目錄呼叫。
- 隨 release 發布時通常已附 prebuilt binary，放上 PATH 即可。

## Token

解析優先序：**`MCP_TASK_TOKEN`（專屬）> `MCP_TOKEN`（共用）> `.vscode/mcp.json`（從當前目錄往上層找）**。
base url 可用 `MCP_BASE_URL` 覆寫（預設 `https://ohya.vip/api/mcp`）。

- 推薦：`export MCP_TASK_TOKEN=<key>`（放 `~/.bashrc`），這樣 repo 外也能用。
- 三者皆無時，binary 會明確提示「未設定 API key：請 export MCP_TASK_TOKEN…」。

## 用法

> **完整、最新指令一律以 binary 不帶參數印出的 usage 為準**，不需讀 source code：

```bash
taskctl          # 印出完整 usage（所有子指令、旗標、--json）
```

大致涵蓋：列出 / 查詢 / 新增 / 更新 / 刪除任務與其 checklist 子項。

## 注意

- `id` 一律取自 `ls` / `get` 回傳，別自己猜。
- 刪除類指令不可復原，動手前先 `get` 確認。
- `--project` 是自由字串，用來跨專案歸類同機多個 repo。
