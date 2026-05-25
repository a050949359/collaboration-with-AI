# collaboration-with-AI

## Tech Stack

- Laravel 13.5 / PHP 8.4
- Vue 3 / Vite / Tailwind CSS
- Inertia.js / @vueuse/core / d3
- MySQL、SQLite
- Redis

---

## 主要功能

### 使用者與帳號
- 註冊、登入（RSA-OAEP 加密傳輸）、Google OAuth 綁定
- 信箱驗證、忘記密碼 / 重設密碼
- 帳號設定：改名、修改密碼
- **API Key 管理**：前端產生 RSA 金鑰對，Server 以公鑰加密回傳明文 key（私鑰僅存記憶體，不持久化）

### 文章
- AI 產生（Gemini / Vertex Imagen）、編輯、瀏覽、分類、標籤
- LINE Webhook 推送文章就緒通知

### 航空資料查詢
- 全球 84,000+ 機場（含地球儀視覺化）、850+ 航空公司
- 200+ 國家 / 城市（Wikidata 整合，使用者 UI 搜尋新增）

### Lab
- **MCP（Model Context Protocol）**：JSON-RPC 2.0 endpoint，供 AI assistant（Claude Desktop 等）以 API Key 呼叫；以任務管理（Task CRUD + 子項目）為操作目標，共 8 個工具
- **ws-lab**：Go WebSocket server 管理介面，多房間架構，含即時串流
- **Computer Vision**：WASM 邊緣偵測（OpenCV）
- **mini-orch**：輕量壓測 / 任務排程觀測介面
- **故事接龍**：多角色 LLM 輪流推進，含世界狀態、道具系統、定時排程
- **Gacha 抽卡**：多人房間同步，WebSocket 廣播動畫（Laravel Reverb）
- **旅遊 Playground**：旅客、行程、訂單、PDF 匯出（Queue Worker 示範）

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

3. 產生 RSA 金鑰（用於登入／註冊密碼加密傳輸, 需確認檔案權限）：
   ```bash
   mkdir -p storage/app/private
   openssl genrsa -out storage/app/private/private.pem 4096
   openssl rsa -in storage/app/private/private.pem -pubout -out storage/app/private/public.pem
   ```

3. 資料匯入 (可選):
   航空資料, 中大型機場補全, 國家資料, 國家資料補全
   
4. 啟動本地開發環境：
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

#### `import:cities` (棄用中)

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

## WebSocket 即時功能（Laravel Reverb）(未完成)

### 安裝

```bash
php artisan install:broadcasting
```

此指令會自動安裝 `laravel/reverb`、`laravel-echo`、`pusher-js`，並設定 `.env` 與 broadcasting config。

### 啟動 Reverb Server

```bash
php artisan reverb:start
```

開發時需同時執行：

```bash
php artisan serve
php artisan reverb:start
php artisan queue:work
npm run dev
```

### 功能模組

#### 抽卡同步（Gacha Room）

多人房間內同步抽卡結果，所有玩家即時看到彼此的抽卡動畫。

```bash
# 建立房間
POST /api/gacha/rooms

# 加入房間
POST /api/gacha/rooms/{code}/join

# 抽卡（server-side 隨機，結果廣播給全房間）
POST /api/gacha/rooms/{room}/draw
```

- Presence Channel：`room.{roomId}`（追蹤在線玩家）
- Broadcast Event：`CardDrawn`（推播抽卡結果與動畫）
- 隨機邏輯在 server 執行，防止客戶端作弊

---

## MCP 設定（Claude Desktop）

在帳號設定頁面產生 API Key 後，將以下設定加入 Claude Desktop 的 `claude_desktop_config.json`：

```json
{
  "mcpServers": {
    "collaboration-with-ai": {
      "type": "http",
      "url": "https://your-domain.com/api/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_API_KEY"
      }
    }
  }
}
```

本地開發時將 `url` 換成 `http://localhost:8000/api/mcp`。

### 可用工具

| 工具 | 說明 |
|------|------|
| `list_tasks` | 列出所有任務（可用 `status` 篩選：`todo` / `in_progress` / `done`） |
| `get_task` | 取得單一任務詳情（含子項目） |
| `create_task` | 新增任務（需 `title`，可選 `description`、`status`、`sort`） |
| `update_task` | 更新任務標題、描述或狀態 |
| `delete_task` | 刪除任務（含所有子項目） |
| `add_task_item` | 新增子項目到指定任務 |
| `update_task_item` | 更新子項目內容或完成狀態 |
| `delete_task_item` | 刪除子項目 |

