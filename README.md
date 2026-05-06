# collaboration-with-AI

## Tech Stack

- Laravel 13.5 / PHP 8.4
- Vue 3 / Vite / Tailwind CSS
- Inertia.js / @vueuse/core / d3
- MySQL、SQLite


---


## 安裝與啟動

1. 複製專案並安裝 Composer/NPM 套件：
   ```bash
   git clone <repo-url>
   cd collaboration-with-AI
   composer install
   npm install
   cp .env.example .env
   php artisan key:generate
   ```
2. 啟動本地開發環境：
   ```bash
   php artisan serve
   npm run dev
   ```

---


## 測試

涵蓋 Model、Controller、API 路由、AI 產生流程、權限驗證等單元與功能測試，確保主要功能與安全性。

---

## 重要檔案與目錄

- `.env.example`：環境變數範例

- `phpunit.xml`：測試設定
- `project-status.yml`：專案快照與結構
- `app/`、`routes/`、`resources/`：主要程式碼

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

### 地理資料匯入

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

#### `import:cities`

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

## 聯絡/貢獻

歡迎 PR、issue 或討論！

## 主要功能

- 文章管理（產生、編輯、瀏覽、Webhook 推送）
- 機場資料查詢（附近機場、統計、詳細資訊）
- 使用者註冊、登入、社群帳號綁定
- 管理員設定（顯示、更新）
- AI 內容產生（文章、圖片，Gemini/Vertex 整合）
- Line Bot 相關功能（好友、文章推播）
- 前端採用 Vue 3、Inertia.js、Tailwind CSS
- API 路由已涵蓋查詢、更新、產生等多種操作