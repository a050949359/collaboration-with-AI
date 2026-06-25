# memctl — 知識圖譜 MCP CLI（給 Claude 的使用說明）

`memctl` 是打 `https://ohya.vip/api/mcp/memory` 的精簡 Go CLI，用來讀寫**跨專案知識圖譜**（entity / relation / observation）。
適合記錄跨機器、跨專案的持久性知識（專案間依賴、主機環境、整合狀態）。在任何專案裡需要查 / 存這類知識時，用 Bash 呼叫即可，毋須開 native MCP，省 context。

## 前置需求

1. `memctl` 在 PATH 上（release 內含 binary；或 `cd cmd/memctl && go build -o memctl .` 後 symlink 到 `~/.local/bin`）。
2. token：`export MCP_MEMORY_TOKEN=<key>`（或共用 `MCP_TOKEN`）。未設且當前目錄樹無 `.vscode/mcp.json` 時，binary 會提示「未設定 API key」。
   - 解析優先序：`MCP_MEMORY_TOKEN` > `MCP_TOKEN` > `.vscode/mcp.json`（從當前目錄往上層找）。

> **完整、最新用法以 `memctl`（不帶參數）印出的 usage 為準**；下表為速查。

## 指令速查

| 指令 | 說明 |
|------|------|
| `memctl graph [entity]` | 讀圖譜；給 entity 則只回該節點子圖，不給回整張圖 |
| `memctl search <query>` | 關鍵字搜尋節點（比對名稱 / type / 所有 observation） |
| `memctl entity <name> <type>` | 建立節點；name 全域唯一，type 慣例 `project`/`host`/`service`/`integration` |
| `memctl rment <name>` | 刪除節點，cascade 刪其 observation 與 relation（不可復原） |
| `memctl add <entity> <content...>` | 在節點上新增一條 observation |
| `memctl rmobs <id>` | 以 id 刪除單條 observation |
| `memctl rel <from> <type> <to>` | 建立有向關係 from→to；type 自由字串（`calls_api`/`depends_on`/`deployed_on`…） |
| `memctl rmrel <from> <type> <to>` | 刪除指定有向關係（三者需齊全） |

任意指令加 `--json` 印原始 JSON（預設為精簡文字輸出）。

## 範例

```bash
memctl search linebot                          # 找相關節點
memctl graph collaboration-with-AI             # 只看單一節點的子圖
memctl entity prod-server host                 # 建主機節點
memctl add prod-server "ZeroTier IP 10.147.20.5"   # 加一條觀察
memctl rel linebot calls_api collaboration-with-AI  # 建跨專案關係
```

## 注意

- 寫入前先 `search` / `graph` 確認節點是否已存在，避免重複建立（`entity` 同名會回既有節點，但 relation/observation 仍可能重複語意）。
- observation 的 `id` 取自 `graph` / `search` 回傳。
- 只存**跨專案 / 跨主機**的持久知識；單一專案的暫時脈絡不要進圖譜。
