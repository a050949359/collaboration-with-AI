# codegraph — 給 LLM 用的靜態程式碼結構分析工具

把程式碼解析成「呼叫圖」，讓 coding agent 用結構查詢（誰呼叫我、改我會影響誰）
取代反覆 grep+讀檔，省 token。**靜態分析**（不執行 code）。

> 靈感來自 `DeusData/codebase-memory-mcp`，但砍掉外殼（158 語言 / UI / Cypher engine / C 單 binary），
> 只留核心，並改用**各語言原生 parser**（非 Tree-Sitter）以拿到型別、做精準 call resolution。

## 現況

- **支援 Go / PHP / TS-Vue 三語言**、純 CLI + 內建視覺化 serve、獨立 SQLite、尚未接 MCP。
- **每語言用原生 parser**（非 Tree-Sitter，為拿型別做精準 call resolution）：
  - **Go**：`go/packages`+`go/types`，method call 靠 receiver 型別，confidence 1.0、零猜測（in-process）。
  - **PHP**：`nikic/php-parser` + NameResolver，抽 class/method/function（FQN）；邊涵蓋 new/靜態/函式/`$this->method`，並用建構子提升+typed 屬性/參數解析 `$this->service->method()`（Laravel DI）。
  - **TS/Vue**：TS Compiler API（TypeChecker 精準跨檔解析）+ `@vue/compiler-sfc` 抽 `<script setup>`。
- 只記專案內部的 CALLS 邊（stdlib/第三方/builtin 自動濾除）。
- **多來源一次掃**：`index` 給的目錄自動探索所有 Go module + 掃 .php + .ts/.vue，合併成一張圖；節點 id 依語言各自命名不撞名。
- **分層設計**：各語言 extractor（產 `[]Node/[]Edge`）與 ingest（`buildGraph` 合併）拆開，下游儲存/查詢語言無關。
- **只讀原始碼副檔名**（.go/.php/.ts/.vue/.js），排除 node_modules/vendor/public/storage 等；只存結構（名稱+關係），不碰 .env/金鑰、不存原始碼內容、零網路。

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
