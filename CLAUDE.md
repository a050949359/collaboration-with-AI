# CLAUDE.md — 專案架構參考

> 此檔案供 AI 助手在每次對話時快速理解專案結構，減少探索性指令執行。

## Tech Stack

- Laravel 13 / PHP 8.4（API only，無 Blade template）
- Vue 3 + TypeScript + Vite + Tailwind CSS
- Inertia.js（SPA 橋接，前端頁面以 Inertia component 掛載）
- Laravel Sanctum（token-based auth，SameSite:Lax cookie）
- Laravel Reverb（WebSocket，開發中）
- MySQL/SQLite（主要資料）、Redis（Queue/Cache）

---

## 目錄結構要點

### 後端

```
app/
  Http/
    Controllers/
      Auth/           # 登入、註冊、Google OAuth、信箱驗證
      Admin/          # 後台設定
      Article/        # 文章 CRUD、AI 產生
      Aviation/       # 機場、航空公司、國家、城市
      Travel/         # 旅遊資料（行程、旅客、訂單、匯出）
      Line/           # LINE Bot webhook
      AvatarController.php  # 頭像產生（Multiavatar 函式庫）
    Middleware/
      DecryptPasswordFields.php  # RSA-OAEP 解密 password 欄位
      AuthTokenFromCookie.php
      EnsureAdmin.php
      HandleInertiaRequests.php  # 共享 props（user、name）+ pageProps() 依路由注入 enum 值
    Requests/         # Form Request 驗證
  Models/
    User.php          # avatar() Attribute 在 $appends
    Aviation/         # Airport、Airline、Country、City
    Travel/           # Tour、Booking、Passenger 等
  Support/
    AvatarGenerator.php  # defaultFor() 產生頭像 URL
  Events/Gacha/       # WebSocket 廣播事件
routes/
  web.php    # 全部掛在 prefix('app') 下，Inertia pages
  api.php    # REST API（/api/...）
```

### 前端

```
resources/js/
  pages/            # Inertia pages（對應路由）
    Auth/           # Login.vue、Register.vue、VerifyResult.vue
    Articles/       # Index.vue、Show.vue、Edit.vue、Generate.vue
    Airports.vue
    Airlines.vue
    Countries.vue   # 城市模組主頁（含選國家 + 城市列表 + 新增城市子頁籤）
    TourPlayground.vue
    CitySearch.vue
  layouts/
    AppLayout.vue   # 主 layout（Navbar、Matrix Rain 動畫、Toast）
    AuthShell.vue   # 登入/註冊 layout（左右兩欄，max-w-5xl）
  lib/
    routes.ts       # ★ 所有 URL 唯一定義處（routes.* + api.*）
    auth-api.ts     # login/register/logout wrapper，使用 api.auth.*
    crypto.ts       # RSA-OAEP 加密（Web Crypto API）
    admin-api.ts
    articles-api.ts
  composables/
    useAuth.ts      # user、isAdmin computed（from Inertia shared props）
  components/
    NavIcon.vue
    airports/AirportGlobe.vue  # D3 地球儀
    welcome/        # 首頁各 section
  i18n/locales/zh-tw.ts、en.ts
  types/
    auth.ts         # User type
    index.ts        # 共用 types
```

---

## 核心約定

### URL 管理
- **所有 URL 只在 `resources/js/lib/routes.ts` 定義**
- 前端元件用 `routes.*` 取頁面路由、`api.*` 取 API 路由
- 禁止在 Vue 元件中硬編碼路徑字串

### Auth 流程
- 登入/註冊：前端以 RSA-OAEP（SHA-1）加密 password 再送出
- 後端：`DecryptPasswordFields` middleware 解密，key 路徑由 `RSA_PRIVATE_KEY_PATH` 指定
- Public key endpoint：`GET /api/auth/key`（`PublicKeyController`）
- Token 存在 SameSite:Lax cookie，**不需要** CSRF cookie round-trip
- `auth-api.ts` 直接用 `api.auth.*`，無 CSRF 邏輯

### Cloudflare Turnstile
- 本地：`VITE_TURNSTILE_ENABLED=false` 隱藏 widget
- 後端：`app()->isLocal()` 跳過驗證（`LoginController`、`RegistController`）

### Avatar
- Route：`GET /app/avatar/default/{seed}`（**無 .svg 副檔名**）
- nginx 會攔截不存在的副檔名，因此路由 URL 不加 `.svg`
- Controller：`AvatarController::default()`，使用 Multiavatar 函式庫
- `AvatarGenerator::defaultFor()` 回傳完整 URL，seed 優先序：name → email → id

### Enum 前後端分工
- **後端（單一來源）**：`app/Enums/` 定義 PHP enum，是所有合法值的唯一定義
  - Model cast：`'field' => MyEnum::class`
  - Controller 驗證：`Rule::enum(MyEnum::class)`（取代 `'in:a,b,c'`）
  - MCP schema：`array_column(MyEnum::cases(), 'value')`（動態，不 hardcode）
- **傳入前端**：`HandleInertiaRequests::pageProps()` 依路由注入 enum values（`array_column(cases, 'value')`）
  - 前端透過 `usePage().props` 取得，用於 select options 預設值、動態計算
- **前端職責**：i18n 顯示文字（`statusLabels`）和 CSS class（`statusColors`）保留在前端，因為這是純顯示邏輯
- **新增 enum case 時**：後端加 case → 前端補 label + color，各自職責清楚，不需要重複定義合法值

### Countries.vue 架構
- mainTab：`cities` | `jobs`
- cities tab 右側：選國家後顯示城市 grid
  - 已驗證用戶另有 sub-tab `list` / `add`
  - `add` sub-tab：輸入城市名搜尋，country_code 來自 `selectedCountry.value.code`（不需使用者再選國家）
- jobs tab：全寬，顯示所有 SearchCityJob，輪詢直到完成

---

## .env 重要變數

| 變數 | 說明 |
|------|------|
| `RSA_PRIVATE_KEY_PATH` | storage/app/private/private.pem |
| `RSA_PUBLIC_KEY_PATH` | storage/app/private/public.pem |
| `VITE_TURNSTILE_ENABLED` | false（本地），true（生產） |
| `VITE_API_BASE_URL` | 跨域部署時設定，通常留空 |
| `GOOGLE_CLIENT_ID/SECRET` | Google OAuth |
| `GEMINI_API_KEY` | AI 文章產生 |

---

## 常見指令速查

```bash
# 機場/航空資料補全
php artisan airports:enrich
php artisan airlines:enrich

# 國家資料匯入
php artisan import:countries --fetch   # 抓 Wikidata
php artisan import:countries           # 寫入 DB

# ★ import:cities 已棄用（城市改由使用者 UI 搜尋加入）
```

---

## 前端主題色規範

新頁面開發時，所有顏色**必須使用下列 CSS 變數**，禁止 hardcode hex/rgba 值。變數在 `resources/css/app.css` 定義，`[data-theme='amber']` 自動覆蓋。

### 主色系

| 變數 | Emerald | Amber | 用途 |
|------|---------|-------|------|
| `--binary-primary` | `#6bdc9f` | `#ffb690` | 主要強調色、連結、icon、active 狀態 |
| `--binary-primary-container` | `#2ca46d` | `#7a4527` | 按鈕漸變深色端、次要強調 |
| `--binary-primary-fixed` | `#85e7b0` | `#ffdbca` | 較淡版主色 |
| `--binary-secondary` | `#a5d1b4` | `#ddb7ff` | 次要強調（`.text-gradient-primary` 終點色）|
| `--binary-tertiary` | `#ffb3b2` | `#ffb4ab` | 錯誤/警告提示文字 |
| `--binary-on-primary-container` | `#07160e` | `#1a0800` | 主色按鈕上的文字色 |

### 文字色

| 變數 | 用途 |
|------|------|
| `--binary-text` | 主要內文 |
| `--binary-text-muted` | 次要說明文字 |
| `--binary-outline` | 標籤、placeholder、border（帶透明度）|
| `--binary-outline-variant` | 極細 border、分隔線 |

### 背景與面板

| 變數 | 透明度 | 用途 |
|------|--------|------|
| `--binary-background` | solid | 頁面底色（solid 版） |
| `--binary-surface-dim` | 0.7 | 最暗面板（aside sidebar 等） |
| `--binary-surface` | 0.7 | 一般面板 |
| `--binary-surface-lowest` | 0.7 | 最深 input/section 背景 |
| `--binary-surface-low` | 0.7 | select/dropdown 背景 |
| `--binary-surface-container` | 0.3 | 極淡容器（inactive 狀態）|
| `--binary-surface-high` | 0.7 | 按鈕、card 背景 |
| `--binary-surface-highest` | 0.7 | toggle track inactive、hover 深一層 |

### 常用 CSS class

```html
<!-- 主色文字 -->
<span class="text-[var(--binary-primary)]" />

<!-- 帶透明度（Tailwind v4 支援） -->
<span class="text-[var(--binary-primary)]/60" />

<!-- 主色漸變按鈕 -->
<button class="binary-button" />

<!-- Glass 面板（含 backdrop-filter） -->
<div class="binary-glass" />

<!-- 文字漸變（primary → secondary，雙主題自動切換） -->
<h1 class="text-gradient-primary" />

<!-- Ghost 按鈕 -->
<button class="binary-ghost-button" />
```

### 禁止事項

- ❌ `style="color: #6bdc9f"` → ✅ `style="color: var(--binary-primary)"`
- ❌ `bg-[#1d2a22]` → ✅ `bg-[var(--binary-surface-high)]`
- ❌ `rgba(107,220,159,0.1)` → ✅ `color-mix(in srgb, var(--binary-primary) 10%, transparent)`
- ✅ 品質色（`#d4af37` 傳奇金）、D3/Canvas 視覺化特定色 → 可保留 hardcode

---

## 前端 Commit 規範

**凡涉及前端檔案（`resources/js/`、`resources/css/`）的 commit，提交前必須執行：**

```bash
npm run lint   # ESLint --fix + Prettier write
```

若只想確認不自動修改：

```bash
npm run lint:check   # ESLint + Prettier check（不寫入）
```

---

## 注意事項

- **不要**在 Vue 元件中硬編碼 `/api/...` 或 `/app/...` 字串，使用 `routes.ts`
- **不要**在 auth-api.ts 加 CSRF 邏輯（Sanctum cookie 不需要）
- **不要**在 AvatarGenerator 或 routes.ts 的 avatarDefault 加 `.svg` 副檔名
- Model `User::avatar()` Attribute 加在 `$appends`，前端 `user.avatar` 直接可用
- Inertia shared props 在 `HandleInertiaRequests::share()`（包含 `user`、`name`）
- `useAuth()` composable 提供 `user`、`isAdmin`（從 Inertia page props 取得）

---

## MCP Memory 使用規範

### Endpoints
- `POST /api/mcp/task` — Task 工具（需要 `task:mcp` scope key，任何登入者可自行建立）
- `POST /api/mcp/memory` — 知識圖譜工具（需要 admin 建立的 `memory:mcp` scope key，讀寫皆同）

### 跨專案知識圖譜
知識圖譜用於記錄**跨機器、跨專案**的持久性知識（entity/relation/observation）。

**儲存時機：當你決定儲存記憶時，詢問使用者：**
> 「這個要同步到 MCP 知識圖譜嗎？適合記錄跨專案關係或主機環境資訊。」

**適合存入圖譜的內容：**
- 專案間的依賴或整合關係（`linebot → calls_api → collaboration-with-AI`）
- 主機環境資訊（dev-wsl2 的設定、prod-server 的部署狀態）
- 跨專案的整合狀態（share token 待接、wasm build pipeline 狀況）

**不適合存入圖譜的內容：**
- 本對話的暫時脈絡（存本機 file memory 即可）
- 已在 CLAUDE.md 記載的架構資訊

### Entity type 慣例（自由字串，以下僅供參考）
- `project`、`host`、`service`、`integration`

### Claude Desktop 設定範例
```json
{
  "mcpServers": {
    "collab-tasks": {
      "url": "https://your-domain.com/api/mcp/task",
      "headers": { "Authorization": "Bearer <task-key>" }
    },
    "collab-memory": {
      "url": "https://your-domain.com/api/mcp/memory",
      "headers": { "Authorization": "Bearer <memory-mcp-key>" }
    }
  }
}
```
