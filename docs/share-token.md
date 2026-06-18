# Share Token（分享連結）

讓**沒有帳號的訪客**透過一條帶 token 的連結，使用 About 頁的「Ask Me」問答。token 帶**用量上限 / 有效期**，用完即失效。

> [!NOTE]
> 這跟 **`UserApiKey`（MCP Bearer key）**是兩套不同東西。本篇只講 **`ShareToken`**（About 分享連結）。對照見 [api-keys.md](api-keys.md)。

## Scope 一覽（`app/Enums/ShareTokenScope.php`）

| Scope | 用途 |
|-------|------|
| `about` | About 頁 Ask Me 問答 |

> 目前只有 `about` 一個 case。（MCP 端點走 `UserApiKey`，不是 ShareToken。）

## Token 生命週期

- raw token = `Str::random(48)`，DB 只存 **`sha256` hash**（`token` 欄，unique）。
- **明文不加密、直接放在 URL `?t=<raw>`**（與 API key 走 RSA-OAEP 不同——這只是低敏感的分享連結，不是帳號憑證）。
- 失效條件（`ShareToken::isValid()`）任一成立即無效：
  - `expires_at` 已過期。
  - `max_uses !== null && uses_count >= max_uses`（`max_uses = null` ＝ 無限次，`1` ＝ 一次性）。
- 每次成功問答 → `incrementUses()`（`uses_count + 1`）。

## 三種發放 / 驗證途徑

```
① Admin 後台手動發        ② LINE bot 自動發           ③ 前端驗證連結是否有效
   session + admin           internal key + HMAC          public（無需登入）
   /api/admin/share-tokens   POST /api/line/about-token   POST /api/share-tokens/check
```

1. **Admin REST**（`ShareTokenController`，掛 `auth:sanctum + EnsureAdmin`）：
   - `GET /api/admin/share-tokens` 列表、`POST` 建立、`DELETE /{id}` 刪除。
   - `store` 回傳 **`raw_token` 一次**（明文），之後 DB 只有 hash，遺失要重建。
2. **LINE 自動發放**（`LineAboutTokenController::issue`）：
   - > [!WARNING]
     > **Laravel 接收端已實作**（commit `4e43898`），但需外部 **linebot 專案**呼叫此端點才算端到端打通；該整合是否上線**不在本 repo 內，尚未驗證**。目前也**無自動化測試**覆蓋。
   - 驗證靠 `X-Line-Bot-Key`（internal key）+ `LineBotHmac`，**非** session。
   - 每個 `line_user_id` 每日上限 `about_token_daily_limit`（預設 2），超過回 **429**。
   - 預設 `max_uses=5`、`expires_days=7`（皆來自 `config('services.line_bot.*')`）。
   - 回傳完整連結 `{app.url}/app/about?t=<raw>`。
3. **驗證**（`POST /api/share-tokens/check`，public，`throttle:3,1`）：
   - 前端 About.vue 收到使用者貼的連結/token 時先打這支確認有效，避免進場才失敗。
   - 無效（找不到 / scope 不符 / `isValid()` false）回 **403** `{valid:false}`。

## 消費端（About 問答）

- 前端 [About.vue](../resources/js/pages/About.vue)：`?t=` 帶入 → 存 `shareToken` ref → 問答時帶 `Authorization: Bearer <raw>`。
- 後端 [AboutController::ask](../app/Http/Controllers/About/AboutController.php)：
  - **已登入** → 直接放行（不耗 token）。
  - **未登入** → 讀 `bearerToken()` → `findByRaw` + 驗 `scope === 'about'` + `isValid()` → 通過才 `incrementUses()`。
  - token 用盡後前端清掉 `shareToken`，顯示「次數已用盡」。

## 注意事項 / 陷阱

- **明文 token 會出現在 URL**：適合低敏感分享，會殘留在瀏覽器歷史 / referer，別拿來放敏感權限。
- **scope 一定要比對**：`findByRaw` 只認 hash，呼叫端必須自行檢查 `scope === 'about'`（`check` 與 `ask` 都有做）。
- **admin 不耗用量**：`ask` 對已登入者直接放行，不檢查 token。

## 新增一個 scope

1. `ShareTokenScope` 加 case。
2. 對應的消費端 controller 比對新 scope（仿 `AboutController::ask` 的 `scope === '...'`）。
3. 視需要在前端建立 / 驗證流程帶上新 scope。

## 相關
- API Key（MCP Bearer）：[api-keys.md](api-keys.md)。
- 流量限制：[rate-limit.md](rate-limit.md)。
