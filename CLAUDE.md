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
      HandleInertiaRequests.php  # 共享 props（user、name）
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
# 開發環境（需同時啟動）
php artisan serve
php artisan reverb:start
php artisan queue:work
npm run dev

# 機場/航空資料補全
php artisan airports:enrich
php artisan airlines:enrich

# 國家資料匯入
php artisan import:countries --fetch   # 抓 Wikidata
php artisan import:countries           # 寫入 DB

# ★ import:cities 已棄用（城市改由使用者 UI 搜尋加入）
```

---

## 注意事項

- **不要**在 Vue 元件中硬編碼 `/api/...` 或 `/app/...` 字串，使用 `routes.ts`
- **不要**在 auth-api.ts 加 CSRF 邏輯（Sanctum cookie 不需要）
- **不要**在 AvatarGenerator 或 routes.ts 的 avatarDefault 加 `.svg` 副檔名
- Model `User::avatar()` Attribute 加在 `$appends`，前端 `user.avatar` 直接可用
- Inertia shared props 在 `HandleInertiaRequests::share()`（包含 `user`、`name`）
- `useAuth()` composable 提供 `user`、`isAdmin`（從 Inertia page props 取得）
