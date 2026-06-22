# 架構與請求串接

這份文件說明一個請求從進來到回應，會穿過哪些層、各層負責什麼，以及對外 API 怎麼被認證與使用。目的是讓人能照著它讀懂既有功能的串接方式，並複製出新功能。

- 後端：Laravel 13 / PHP 8.4，API only（無 Blade）
- 前端：Vue 3 + TypeScript + Inertia.js（SPA），細節見 [frontend/](frontend/)
- 認證：Sanctum token，存在 `SameSite:Lax` cookie

---

## 1. 路由分檔

| 檔案 | 用途 | 共同條件 |
|---|---|---|
| [routes/web.php](../routes/web.php) | Inertia 頁面 | 全部掛在 `prefix('app')` 底下，URL 以 `/app/...` 開頭 |
| [routes/api.php](../routes/api.php) | REST API | URL 以 `/api/...` 開頭 |
| [routes/console.php](../routes/console.php) | artisan 指令 | — |
| [routes/channels.php](../routes/channels.php) | broadcast 頻道 | — |

健康檢查在 `/up`。前端**不直接寫死這些路徑**，一律從 [resources/js/lib/routes.ts](../resources/js/lib/routes.ts) 取，詳見 [frontend/routing.md](frontend/routing.md)。

---

## 2. 請求生命週期

middleware 的組裝集中在 [bootstrap/app.php](../bootstrap/app.php)（Laravel 11+ 無 Kernel）。一個請求的順序大致是：

```
TrustProxies (prepend, 全域)
  → group middleware（web 或 api）
    → route / group 上自訂的 middleware
      → Controller
        → （回應）Exception render（統一錯誤格式）
```

### 2.1 web group vs api group

| | web group | api group |
|---|---|---|
| `AuthTokenFromCookie` | ✅（prepend） | ✅（prepend） |
| `HandleInertiaRequests` | ✅（append） | ❌ |
| `AddLinkHeadersForPreloadedAssets` | ✅ | ❌ |

- **`AuthTokenFromCookie`** 兩個 group 都掛：把 `SameSite:Lax` cookie 裡的 token 補成 `Authorization: Bearer`，讓後續 `sanctum` guard / `HandleInertiaRequests` 認得出登入者。
- **`HandleInertiaRequests`** 只在 web：負責注入 Inertia 共享 props（`user`、`name`）與**依路由給 enum values**（見 §5）。

---

## 3. Middleware 權限設計

### 3.1 別名一覽（`bootstrap/app.php` 註冊）

| alias | class | 作用 |
|---|---|---|
| `auth.apikey` | `AuthenticateWithApiKey` | 讀 Bearer token，比對 `UserApiKey`，**認成某個 user**（軟認證，無 token 不擋） |
| `apikey.scope:<scope>` | `CheckApiKeyScope` | 要求上一步已認證、且 key 具備指定 scope，否則 401/403 |
| `share-token:<scope>` | `ShareTokenAuth` | 登入者直接放行；否則需帶有效 `ShareToken`（指定 scope） |
| `turnstile` | `VerifyTurnstile` | Cloudflare Turnstile 驗證（本地 `app()->isLocal()` 跳過） |

未做成別名、直接用 class 名掛的（多用於 `web.php` / `api.php` group）：

| middleware | 作用 |
|---|---|
| `auth:sanctum` | Laravel 內建，要求已登入（token guard） |
| `verified` | 要求 email 已驗證 |
| `EnsureAdmin::class` | 要求 `user()->isAdmin()`，否則 403 |
| `EnsureRegistrationOpen::class` | 註冊開關（`allow_registration`）關閉時擋下 |
| `DecryptPasswordFields::class` | 解密前端 RSA-OAEP 加密的 password 欄位 |

### 3.2 兩套 token 是獨立系統，別混用

| | `UserApiKey`（`auth.apikey` + `apikey.scope`） | `ShareToken`（`share-token`） |
|---|---|---|
| 語意 | 「**以某 user 身分**打 API / MCP」 | 「匿名限量分享連結」 |
| scope enum | `ApiKeyScope`（`task:mcp` / `memory:mcp` / `agyd:mcp` / `rag:mcp`） | `ShareTokenScope`（目前僅 `about`） |
| 傳遞方式 | `Authorization: Bearer`，**永不進 URL** | 明文放 `?t=` query string |
| 額度 | 無（靠 revoke） | `max_uses` / `expires_at` |
| `scopes = null` | **403**（視為無任何權限，非無限制） | — |

分界線＝**「token 是否認證成某個 user」**：具名（會變成某 user）⇒ 必須加密回傳、禁進 URL。
詳細用法見 [api-keys.md](api-keys.md) 與 [share-token.md](share-token.md)。

> ⚠️ 本專案登入走 token-in-cookie，預設 web(session) guard 認不到登入者。在**沒掛** `auth:sanctum` 的路由內若要判斷登入狀態，要用 `Auth::guard('sanctum')->check()`，不能只用 `Auth::check()`。`ShareTokenAuth` 就是這樣寫的。

---

## 4. API 怎麼被認證與使用

對外呼叫 API 有三種身分來源：

1. **瀏覽器 session（cookie）**：登入後 token 存在 `SameSite:Lax` cookie，前端 fetch 自動帶上，`AuthTokenFromCookie` 補成 Bearer。**不需要 CSRF round-trip**（Sanctum cookie 模式）。
2. **`UserApiKey`（Bearer）**：具名 API key，主要給 MCP server。呼叫帶 `Authorization: Bearer <key>`，經 `auth.apikey` 認成該 user、`apikey.scope:<scope>` 檢查權限。
3. **`ShareToken`（`?t=`）**：匿名分享連結，帶在 query string，由 `share-token:<scope>` 把關並扣次數。

### 4.1 統一錯誤格式

驗證失敗在 `bootstrap/app.php` 的 `withExceptions` 統一成 JSON：

| 情境 | HTTP | `code` |
|---|---|---|
| 一般驗證失敗 | 422 | `VALIDATION_ERROR` |
| 疑似惡意輸入（`NoMaliciousPattern` 規則命中） | 422 | `UNSAFE_INPUT` |

回應結構：`{ status: 'error', code, message, errors }`。權限類錯誤則由各 middleware 直接回 401 / 403。

---

## 5. 後端 → 前端的 enum 注入

合法值的**單一來源是後端 `app/Enums/` 的 PHP enum**。要把某頁需要的 enum values 丟給前端時，在 [HandleInertiaRequests::pageProps()](../app/Http/Middleware/HandleInertiaRequests.php) 依**路由名**注入：

```php
// HandleInertiaRequests::pageProps()
return match (true) {
    $request->routeIs('task') => [
        'taskStatuses' => array_column(TaskStatus::cases(), 'value'),
    ],
    // ...其他路由
    default => [],
};
```

前端透過 `usePage().props` 取得這些 values（用於 select options、動態計算）。**顯示文字（label）與顏色（CSS class）留在前端**，因為那是純顯示邏輯。新增 enum case 時：後端加 case → 前端補 label/color，各司其職。

---

## 6. 新增一個功能：後端骨架

從零加一個 REST 功能，建議順序（前端整合見 [frontend/](frontend/)）：

1. **Enum（若有固定選項）** — 在 `app/Enums/` 定義，作為合法值唯一來源。
   - Model cast：`'field' => MyEnum::class`
   - 驗證：`Rule::enum(MyEnum::class)`（取代 `in:a,b,c`）
2. **FormRequest** — 把驗證從 controller 抽出。範例 [AskRequest](../app/Http/Requests/About/AskRequest.php)：

   ```php
   class AskRequest extends FormRequest
   {
       public function authorize(): bool { return true; }

       public function rules(): array
       {
           return [
               'message' => ['required', 'string', 'max:500'],
               'history' => ['nullable', 'array', 'max:20'],
           ];
       }
   }
   ```
3. **Controller** — 注入 FormRequest，回傳 JSON（API）或 Inertia render（頁面）。
4. **Route** — 在 `api.php` / `web.php` 掛上，按需要加 middleware（見 §3）。對外 API 記得加 `throttle`（見 [rate-limit.md](rate-limit.md)）。
5. **前端 URL** — 在 `routes.ts` 補 `routes.*`（頁面）或 `api.*`（API），元件從這裡取，禁止硬編碼。詳見 [frontend/routing.md](frontend/routing.md)。
6. **enum 注入**（若前端要用）— 在 `pageProps()` 對應路由加一筆（見 §5）。

新增 MCP 工具是另一條路徑，見 [mcp-tool.md](mcp-tool.md)。
