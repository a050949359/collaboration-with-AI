# codegraph — 給 LLM 用的靜態程式碼結構分析工具

把程式碼解析成「呼叫圖」，讓 coding agent 用結構查詢（誰呼叫我、改我會影響誰）
取代反覆 grep+讀檔，省 token。**靜態分析**（不執行 code）。

> 靈感來自 `DeusData/codebase-memory-mcp`，但砍掉外殼（158 語言 / UI / Cypher engine / C 單 binary），
> 只留核心，並改用**各語言原生 parser**（非 Tree-Sitter）以拿到型別、做精準 call resolution。

## 現況（第一刀）

- **只支援 Go**、純 CLI、獨立 SQLite、尚未接 MCP。
- Go extractor 用 `go/packages`+`go/types`：method call 靠 receiver 型別精準解析，**confidence 1.0、零猜測**。
- 只記專案內部的 CALLS 邊（stdlib/第三方/builtin 自動濾除）。
- **多 module 一次掃**：`index` 給的目錄若含多個 go.mod（如 `cmd/`），會自動探索全部、合併成一張圖（節點 id 依 module 限定、檔案路徑相對掃描根，不撞名）。
- **分層設計**：extractor（產 `[]Node/[]Edge`）與 ingest（`buildGraph` 合併多來源）拆開 → 之後 PHP/TS extractor 只要吐同樣的東西丟進 `buildGraph` 即可，這層不改。

## 用法

```bash
go build -o codegraph .

codegraph index   <dir>     [--db path] [--force]     # 索引 Go module（需 go.mod）
codegraph callers <symbol>  [--db path]               # 誰呼叫了它
codegraph callees <symbol>  [--db path]               # 它呼叫了誰
codegraph impact  <symbol>  [--db path] [--depth n]   # 改它會連帶影響誰（反向 BFS）
codegraph search  <pattern> [--db path]               # 模糊找符號
```

`symbol` 可給簡名（`Broadcast`）、限定名或 id（`(*sse-lab.Broadcaster).Broadcast`）。
查詢輸出 JSON（供 LLM 消費）；db 預設 `codegraph.db`。

## 統一 JSON 契約（跨語言共用）

extractor 不論語言都吐這個形狀，下游儲存/查詢語言無關：

```
Node = { id, type(func|method|type), name, qualified, file, line }
Edge = { from, to, type(CALLS|IMPORTS), confidence, file, line }
```

## 設計 / 架構

- 沿用 `vecgen` 範式：非常駐 CLI（index 是貴操作偶爾跑、query 是便宜 SQL）+ 獨立 SQLite。
- 之後：PHP（nikic/php-parser，exec）、TS/Vue（TS Compiler API，exec）各接一個 extractor 吐同一 JSON；
  再包 Laravel MCP `/api/mcp/codegraph`。詳見 task #33。

## 待辦

- IMPORTS 邊（目前只做 CALLS）、per-file 增量（目前為整包 skip-if-unchanged）
- PHP / TS-Vue extractor
- 接 MCP
