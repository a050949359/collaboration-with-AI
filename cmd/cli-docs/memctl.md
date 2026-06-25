# memctl — 知識圖譜 MCP CLI（給 Claude 的參考文件）

## 這是什麼

打 `https://ohya.vip/api/mcp/memory` 的精簡 Go CLI client，用來讀寫**跨專案知識圖譜**（entity / relation / observation）。
是 native MCP 與冗長 curl 的替代：用 Bash 呼叫即可，不必開 native MCP 連線常駐（省 context token）。

## 何時用

在**任何專案**裡需要查 / 存**跨機器、跨專案的持久性知識**時用它（專案間依賴、主機環境、整合狀態）。

## 位置 / 呼叫

由 release 的 `install.sh` 裝在 PATH（`~/.local/bin/memctl`），**任何目錄直接執行 `memctl`** 即可。
若要從源碼自行編譯：見 `collaboration-with-AI` repo 的 `cmd/memctl/`（`go build -o memctl .`）。

## Token

解析優先序：**`MCP_MEMORY_TOKEN`（專屬）> `MCP_TOKEN`（共用）> `.vscode/mcp.json`（從當前目錄往上層找）**。
base url 可用 `MCP_BASE_URL` 覆寫（預設 `https://ohya.vip/api/mcp`）。

- 推薦：`export MCP_MEMORY_TOKEN=<key>`（放 `~/.bashrc`），這樣 repo 外也能用。
- 三者皆無時，binary 會明確提示「未設定 API key：請 export MCP_MEMORY_TOKEN…」。

## 用法

> **完整、最新指令一律以 binary 不帶參數印出的 usage 為準**，不需讀 source code：

```bash
memctl           # 印出完整 usage（所有子指令、旗標、--json）
```

大致涵蓋：讀圖譜 / 搜尋節點 / 建刪 entity / 加刪 observation / 建刪 relation。

## 知識圖譜內容規範

> 「儲存時機」（何時要問使用者是否同步到圖譜）見專案 `CLAUDE.md`；此處為內容判斷準則。

**適合存入：**
- 專案間的依賴或整合關係（`linebot → calls_api → collaboration-with-AI`）
- 主機環境資訊（dev-wsl2 的設定、prod-server 的部署狀態）
- 跨專案的整合狀態（share token 待接、wasm build pipeline 狀況）

**不適合存入：**
- 本對話的暫時脈絡（存本機 file memory 即可）
- 已在 CLAUDE.md 記載的架構資訊

**Entity type 慣例**（自由字串，僅供參考）：`project`、`host`、`service`、`integration`

## 注意

- 寫入前先 `search` / `graph` 確認節點是否已存在，避免重複（`entity` 同名會回既有節點，但 relation/observation 仍可能語意重複）。
- observation 的 `id` 取自 `graph` / `search` 回傳。
