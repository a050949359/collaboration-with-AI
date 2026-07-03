# codegraph — 給 LLM 用的靜態程式碼結構分析工具

把程式碼解析成「呼叫圖」，讓 coding agent 用結構查詢（誰呼叫我、改我會影響誰）
取代反覆 grep+讀檔，省 token。**靜態分析**（不執行 code）。

> 靈感來自 `DeusData/codebase-memory-mcp`，但砍掉外殼（158 語言 / UI / Cypher engine / C 單 binary），
> 只留核心，並改用**各語言原生 parser**（非 Tree-Sitter）以拿到型別、做精準 call resolution。

## 現況

- **支援 Go / PHP / TS-Vue 三語言**、CLI + Laravel 視覺化頁 + **MCP `/api/mcp/codegraph`**、獨立 SQLite。
- **每語言用原生 parser**（非 Tree-Sitter，為拿型別做精準 call resolution）：
  - **Go**：`go/packages`+`go/types`，method call 靠 receiver 型別，confidence 1.0、零猜測（in-process）。
  - **PHP**：`nikic/php-parser` + NameResolver，抽 class/method/function（FQN）；邊涵蓋 new/靜態/函式/`$this->method`，並用建構子提升+typed 屬性/參數解析 `$this->service->method()`（Laravel DI）。
  - **TS/Vue**：TS Compiler API（TypeChecker 精準跨檔解析）+ `@vue/compiler-sfc` 抽 `<script setup>`。
- 邊涵蓋 **CALLS**（同語言函式呼叫，stdlib/第三方/builtin 自動濾除）、**HANDLES**（route→controller）、**HTTP_CALLS**（前端 fetch→route，達成 TS/Vue→PHP 跨語言連通）。
- **多來源一次掃**：`index` 給的目錄自動探索所有 Go module + 掃 .php + .ts/.vue，合併成一張圖；節點 id 依語言各自命名不撞名。
- **分層設計**：各語言 extractor（產 `[]Node/[]Edge`）與 ingest（`buildGraph` 合併）拆開，下游儲存/查詢語言無關。
- **只讀原始碼副檔名**（.go/.php/.ts/.vue/.js），排除 node_modules/vendor/public/storage 等；只存結構（名稱+關係），不碰 .env/金鑰、不存原始碼內容、零網路。

## 用法

```bash
go build -o codegraph .

codegraph index   <dir>     [--db path] [--force]     # 索引整個目錄（自動掃 Go module + .php + .ts/.vue）
codegraph callers <symbol>  [--db path]               # 誰呼叫了它
codegraph callees <symbol>  [--db path]               # 它呼叫了誰
codegraph impact  <symbol>  [--db path] [--depth n]   # 改它會連帶影響誰（反向 BFS）
codegraph search  <pattern> [--db path]               # 模糊找符號
```

`symbol` 可給簡名（`Broadcast`）、限定名或 id（`(*ticket-rush.Broadcaster).Broadcast`）。
查詢輸出 JSON（供 LLM 消費）；db 預設 `codegraph.db`。

## MCP `/api/mcp/codegraph`（省 token 的兌現點）

**查詢端零 Go**：圖是普通唯讀 SQLite（Laravel `codegraph` 連線，`CODEGRAPH_DB` 預設 `database/codegraph.db`），
MCP 工具全用 PHP 讀，**只有 index 才 exec 這支 Go binary**。

- Controller / Service：`CodeGraphMcpController`（JSON-RPC）+ `CodeGraphMcpService`（比照 RagMcp）。
- scope：`codegraph:mcp`（唯讀 + repo public，非 admin）；路由掛 `auth.apikey` + `apikey.scope:codegraph:mcp`。
- 6 工具：`codegraph_search` / `callers` / `callees` / `impact` / `trace_call_path` / `get_node`。
  callers/callees/impact/trace 皆沿 CALLS+HANDLES+HTTP_CALLS 邊（含跨語言）。**不開 index 工具**（重操作另循觸發）。
- CLI client：`cmd/codegraphctl`（精簡 Go client，取代 native MCP / curl）；用法見 `~/.claude/cli-docs/codegraphctl.md`。
- ⚠️ MCP 讀的是「上次 index 的快照」，db 沒 populate 或過舊 → 答案空/錯，prod 要先跑過 `index`。

## 統一 JSON 契約（跨語言共用）

extractor 不論語言都吐這個形狀，下游儲存/查詢語言無關：

```
Node = { id, type(func|method|type|route), name, qualified, file, line }
Edge = { from, to, type(CALLS|HANDLES|HTTP_CALLS), confidence, file, line }
```

## 設計 / 架構

- 沿用 `vecgen` 範式：非常駐 CLI（index 是貴操作偶爾跑、query 是便宜 SQL）+ 獨立 SQLite。
- 各語言 extractor（Go in-process、PHP/TS exec 出去吐同一 JSON）各產 `[]Node/[]Edge` 丟進 `buildGraph` 合併，
  下游儲存/查詢/MCP 全語言無關。跨界邊（HANDLES/HTTP_CALLS）由 ingest 後的 linker pass 用共同字串鍵（URL/route）配對。詳見 task #33。

## 待辦

- IMPORTS 邊（目前只做 CALLS/HANDLES/HTTP_CALLS）、per-file 增量（目前為整包 skip-if-unchanged）
- 跨界 exec 邊（`Process::run` → Go binary）、WS 邊（前端 WebSocket → ws-lab）
- PHP `$obj->m()` 跨繼承/trait 解析（現只 `$this`/typed prop/param）；HTTP_CALLS 未配對的 resource route
- re-index 觸發策略（勿進 deploy 關鍵路徑）
