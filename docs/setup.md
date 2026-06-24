# 安裝與操作指南

本專案的安裝、本地啟動、資料匯入、Artisan 指令、WebSocket（ws-lab）與 MCP（Claude Desktop）設定。
功能總覽見 [README](../README.md)。

---

## 安裝與啟動

1. 複製專案並安裝 Composer/NPM 套件：
   ```bash
   git clone <repo-url>
   cd collaboration-with-AI
   composer install
   npm install
   cp .env.example .env (.env 內容需自行補完)
   php artisan key:generate
   ```

2. 填入必要的第三方金鑰（`.env`）：

   | 金鑰 | 取得來源 | 必填 |
   |------|----------|------|
   | `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | [Google Cloud Console](https://console.cloud.google.com/) → OAuth 2.0 | Google 登入 |
   | `VITE_TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` | [Cloudflare Turnstile](https://dash.cloudflare.com/) | 機器人驗證（本地可設 `VITE_TURNSTILE_ENABLED=false`） |
   | `GEMINI_API_KEY` | [Google AI Studio](https://aistudio.google.com/) | LLM 對話 |
   | `GCP_PROJECT_ID` / `VERTEX_APPLICATION_CREDENTIALS` | Google Cloud → Service Account | AI 圖片產生（Vertex） |
   | `AGYD_URL` / `AGYD_SECRET` | agyd daemon ZeroTier URL + 共享 secret | agyd daemon 整合（選填）|

3. 產生 RSA 金鑰（用於登入／註冊密碼加密傳輸，需確認檔案權限）：
   ```bash
   mkdir -p storage/app/private
   openssl genrsa -out storage/app/private/private.pem 4096
   openssl rsa -in storage/app/private/private.pem -pubout -out storage/app/private/public.pem
   ```

4. 資料匯入（可選）：航空資料、中大型機場補全、國家資料、國家資料補全（見下方 [Artisan 指令](#artisan-指令)）。

5. 啟動本地開發環境：
   ```bash
   php artisan serve
   npm run dev
   ```

---

## Artisan 指令

### 航空資料補全

資料補全指令從 **Wikidata SPARQL** 抓取，需要網路連線，首次執行即可，之後只在需要更新時重新執行。

#### `airports:enrich`

補全中大型機場的中文名稱、缺少的 IATA / ICAO 代碼。

```bash
# 預覽（不寫入）
php artisan airports:enrich --dry-run

# 正式執行
php artisan airports:enrich
```

- 只處理 `large_airport` / `medium_airport`（約 5,000 筆）
- 中文名優先使用 zh-tw label，無則 fallback 到 zh（簡體）

#### `airlines:enrich`

補全航空公司中文名稱，並新增 DB 中缺少的航空公司。

```bash
# 預覽（不寫入）
php artisan airlines:enrich --dry-run

# 正式執行
php artisan airlines:enrich
```

- 更新現有記錄的 `name_zh_tw`
- 新增 Wikidata 有但 DB 沒有的航空公司（需有英文名稱才會新增）
- 新增的記錄包含 IATA、ICAO（若有）、英文名、中文名

### 國家資料匯入

#### `import:countries`

從 Wikidata 匯入國家資料（259 筆），包含 ISO 代碼、多語系名稱、首都、電話區碼。

```bash
# 從 Wikidata 抓取並存到本地快取（storage/app/private/wikidata_countries.json）
php artisan import:countries --fetch

# 預覽（不寫入，使用快取）
php artisan import:countries --dry-run

# 正式寫入
php artisan import:countries
```

- 重複的首都或電話區碼會保留第一筆，其餘存入 `notes`
- 快取存在時不重複打 API，加 `--fetch` 強制重抓

#### `import:cities`（棄用中）

從 Wikidata 匯入城市資料（約 4 萬筆），依 Q515（city）所有子類分批透過 Queue 匯入。

```bash
# 步驟一：查詢子類清單並將所有批次派入 Queue
php artisan import:cities

# 步驟二：啟動 Queue Worker 執行匯入
php artisan queue:work --timeout=110
```

- 使用 cursor-based pagination（以 QID 為游標），避免 SPARQL OFFSET 效能問題
- 每批 1000 筆，批次間 sleep 10 秒以避免 Wikidata 限速
- 每批跑完自動鏈接下一批，無需手動干預
- 失敗批次可用 `php artisan queue:retry all` 重跑，或重新執行 `import:cities` 從頭派發

---

## WebSocket 即時功能（自製 Go Server：ws-lab）

多房間即時同步由 `cmd/ws-lab`（Go）提供，採 goroutine 各擁房間狀態、channel 序列化存取的併發模型。內部設計見 [cmd/ws-lab/README.md](../cmd/ws-lab/README.md)。

### 編譯與啟動

```bash
cd cmd/ws-lab
go build -o ws-lab .
./ws-lab --mgmt-addr 127.0.0.1:9002
```

> binary 已被列入 .gitignore，clone 後需自行編譯。生產環境由 nginx 將 `/ws-lab` proxy 到本 binary（本地無 nginx 時前端無法連線）。

### 抽卡房間（Gacha Room）

抽卡的隨機與發牌在 server 端執行並查詢房間狀態防作弊，結果即時推播給同房間玩家。REST 進入點：

```bash
POST /api/v1/gacha/rooms              # 建立房間
POST /api/v1/gacha/rooms/{code}/join  # 加入房間
POST /api/v1/gacha/rooms/{code}/draw  # 抽卡（server-side 隨機）
```

---

## MCP 設定（Claude Desktop）

本專案提供兩個獨立的 MCP Server，各自需要不同 scope 的 API Key。在 Profile 頁面產生對應的 Key 後，加入 Claude Desktop 的 `claude_desktop_config.json`：

```json
{
  "mcpServers": {
    "collab-tasks": {
      "type": "http",
      "url": "https://your-domain.com/api/mcp/task",
      "headers": {
        "Authorization": "Bearer YOUR_TASK_MCP_KEY"
      }
    },
    "collab-memory": {
      "type": "http",
      "url": "https://your-domain.com/api/mcp/memory",
      "headers": {
        "Authorization": "Bearer YOUR_MEMORY_MCP_KEY"
      }
    }
  }
}
```

本地開發時將 `your-domain.com` 換成 `localhost:8000`。

> 開發者視角「如何新增一個 MCP tool / server」見 [docs/mcp-tool.md](mcp-tool.md)。

### API Key Scope

| Scope | 對應 Server | 誰可建立 |
|---|---|---|
| `task:mcp` | collab-tasks | 所有登入者 |
| `memory:mcp` | collab-memory | Admin only |

### collab-tasks 工具（`task:mcp` key）

| 工具 | 說明 |
|------|------|
| `list_tasks` | 列出所有任務（含子項目）。可依 `status`、`project` 篩選 |
| `get_task` | 以 ID 取得單一任務及其所有子項目 |
| `create_task` | 建立新任務。可指定 `project` 歸屬方便跨專案追蹤 |
| `update_task` | 更新任務的標題、描述、project、狀態或排序 |
| `delete_task` | 刪除指定任務及其所有子項目（不可復原） |
| `add_task_item` | 在指定任務下新增 checklist 子項目 |
| `update_task_item` | 更新子項目的文字內容或完成狀態 |
| `delete_task_item` | 刪除指定子項目 |

### collab-memory 工具（`memory:mcp` admin key）

| 工具 | 說明 |
|------|------|
| `read_graph` | 讀取知識圖譜（entities + observations + relations）。可指定 `entity_name` 取子圖 |
| `search_nodes` | 以關鍵字搜尋節點，比對名稱、type 及 observation 內容 |
| `create_entity` | 建立節點（name 唯一，已存在則直接回傳） |
| `delete_entity` | 刪除節點及其所有 observations 和 relations（不可復原） |
| `add_observation` | 對節點附加一條文字觀察 |
| `remove_observation` | 以 ID 刪除單條觀察 |
| `create_relation` | 建立有向關係（from → relation_type → to），相同三元組不重複 |
| `delete_relation` | 刪除指定有向關係 |

### 本機 CLI（memctl / taskctl）

`cmd/memctl`、`cmd/taskctl` 是上述兩個 MCP server 的精簡 Go CLI client，供本機直接操作（取代冗長 curl，亦免 native MCP 連線常駐）。token / URL 自動從 `.vscode/mcp.json` 讀取。

```bash
# binary 已被列入 .gitignore，clone 後需先以 Go 編譯一次
cd cmd/memctl  && go build -o memctl  .
cd cmd/taskctl && go build -o taskctl .

./memctl graph linebot        # 讀知識圖譜
./taskctl ls --status todo    # 列任務
```

詳細指令與語意見各自 `--help`。
