# codegraphctl — 程式碼結構圖 MCP CLI（給 Claude 的參考文件）

## 這是什麼

打 `https://ohya.vip/api/mcp/codegraph` 的精簡 Go CLI client，用來查**靜態程式碼結構圖**
（誰呼叫我、我呼叫誰、改我影響誰、A 怎麼呼叫到 B）。
是 native MCP 與冗長 curl 的替代：用 Bash 呼叫即可，不必開 native MCP 連線常駐（省 context token）。

圖是**唯讀快照**（由 `cmd/codegraph index` 產生），只含結構（名稱 + 呼叫關係），**無原始碼內容**。

## 何時用

想理解某個專案的**結構關係**時用它，取代反覆 grep + 讀檔：
- 動某個函式/方法前，先 `impact` 看會連帶影響誰
- 查一條依賴鏈「前端這個按鈕最後打到哪個 controller」用 `trace`
- 只知道名字、要對應到精確符號用 `search`

> 這是「**結構關係**」（callers/impact/依賴鏈），與 RAG（語意相似）互補。
> 要讀原文內容的問題它答不了（圖不存原始碼）。

## 位置 / 呼叫

由 release 的 `install.sh` 裝在 PATH（`~/.local/bin/codegraphctl`），**任何目錄直接執行 `codegraphctl`** 即可。
若要從源碼自行編譯：見 `collaboration-with-AI` repo 的 `cmd/codegraphctl/`（`go build -o codegraphctl .`）。

## Token

解析優先序：**`MCP_CODEGRAPH_TOKEN`（專屬）> `MCP_TOKEN`（共用）> `.vscode/mcp.json`（從當前目錄往上層找）**。
base url 可用 `MCP_BASE_URL` 覆寫（預設 `https://ohya.vip/api/mcp`）。

- 推薦：`export MCP_CODEGRAPH_TOKEN=<key>`（放 `~/.bashrc`），這樣 repo 外也能用。
- 三者皆無時，binary 會明確提示「未設定 API key：請 export MCP_CODEGRAPH_TOKEN…」。
- key 需帶 `codegraph:mcp` scope（唯讀、非 admin，任何登入者可自建）。

## 用法

> **完整、最新指令一律以 binary 不帶參數印出的 usage 為準**，不需讀 source code：

```bash
codegraphctl     # 印出完整 usage（所有子指令、旗標、--json）
```

大致涵蓋：`search`（找符號）/ `callers` / `callees` / `impact` / `trace`（兩符號間最短呼叫路徑）/ `node`。

`symbol` 可給簡名、限定名或 id（Go `(*pkg.T).M`、PHP `Ns\Class::method`、TS `relpath:name`、route `VERB /api/...`）。
輸出預設精簡文字（帶語言 + 檔案:行），加 `--json` 印原始 JSON。

## 注意

- 圖是**上次 index 的快照**：查不到/為空時，先確認該 repo 有跑過 `codegraph index`、且不是過舊。
- callers/callees/impact/trace 沿 CALLS + HANDLES（route→controller）+ HTTP_CALLS（前端→route）邊走，
  故**能跨語言**（後端 controller 追得到呼叫它的前端 Vue 函式）。
- Eloquent Model 因用魔術屬性/繼承 CRUD 常是圖中孤點（純靜態分析難解），查 Model 關聯可能為空屬正常。
