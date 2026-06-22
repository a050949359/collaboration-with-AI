# API / 功能流量限制整理（安全版）

這份文件記錄**目前程式碼中可確認的限制與冷卻機制**，重點在於說明系統的保護方式與設定值。

整理目標：

- 分清楚「**路由層 throttle**」與「**程式碼內部自訂限流**」
- 保留實際設定值

---

## 1. 路由層的明確限制

### 1.1 `throttle:X,1` 的含義

Laravel 的 `throttle:X,1` 表示：**每 1 分鐘最多 X 次**。

### 1.2 已確認的限制群組

| 類型 | 主要範圍 | 限制方式 |
|---|---|---|
| About / 分享連結 | `POST /api/about/*`、`POST /api/share-tokens/*` | 每分鐘固定次數限制 |
| 帳號與驗證流程 | `POST /api/auth/login`（10/分）、`/register`（5/分）、`/forgot-password`（5/分） | 每 IP 每分鐘固定次數限制 |
| 留言相關 | 文章留言與留言管理 | 每分鐘固定次數限制 |
| 地區與資料查詢 | 航空、城市、國家等公開查詢 | 每分鐘固定次數限制 |
| LINE 互動 | LINE 相關 webhook / token / quick-generate | 每分鐘固定次數限制 |
| 角色 / 故事 / 抽卡 | 內容生成與互動入口 | 每分鐘固定次數限制 |
| AGYD 回調 | 下載結果回傳入口 | 每分鐘固定次數限制 |

> 這裡不會逐條列出所有端點細節；重點是說明這些功能屬於「有固定速率限制」的類別。

---

## 2. 程式碼內部的自訂限流

### 2.1 文章生成

文章內容生成與圖片生成不是只靠路由層 throttle，而是由控制器內部使用 `RateLimiter` 做冷卻保護。

### 2.2 實際設定值

- 冷卻時間來源：`config('services.vertex_ai.rate_limit_seconds')`
- 預設值：`3600` 秒
- 也就是：**每位使用者在同一類型生成上，預設每 3600 秒只能執行一次**

限制是依照使用者與生成類型分開管理，而不是單純依照固定每分鐘次數。

### 2.3 登入防護（兩層）

登入採「IP throttle」+「帳號鎖定」雙層，兩者互補：

| 層級 | 機制 | 擋的攻擊 |
|---|---|---|
| 路由層（§1） | `throttle:10,1`（依 IP） | 密碼噴灑、猜不存在的 email 等**跨帳號**嘗試 |
| 程式碼層（[LoginController](../app/Http/Controllers/Auth/LoginController.php)） | 同帳號連續失敗 `5` 次 → 鎖 `15` 分鐘（`locked_until`），回 429 | 對**單一帳號**的密碼暴力 |

> 登入失敗一律回同一句 `帳號或密碼錯誤`（401），不透露 email 是否註冊或剩餘次數，避免使用者列舉。

---

## 3. 其他冷卻設定

### 3.1 密碼重設流程

在 [config/auth.php](../config/auth.php) 中，密碼重設 token 有一個冷卻設定：

- `throttle => 60`

表示在再次申請前，需要等待 60 秒。

---

## 4. LINE 分享連結每日簽發上限

LINE bot 會替使用者自動簽發 About 分享連結（`ShareToken`，`scope = about`）。簽發端點 [LineAboutTokenController](../app/Http/Controllers/Line/LineAboutTokenController.php) 屬內部端點，走內部 key + HMAC 雙重把關，**非公開**。

防濫用分兩個不同層級：

### 4.1 每日簽發上限（防同一人狂產連結）

同一 `line_user_id` 每天最多簽發固定把數，超過回 `429`（回應含 `next_reset`，隔日 00:00 歸零）。

- 來源：`config('services.line_bot.about_token_daily_limit')`
- 預設值：`2`

### 4.2 每把 token 自身的額度（限制連結本身）

每把簽發出去的連結，額度與效期是 token 自帶的（沿用 `ShareToken` 既有機制，非路由 throttle）：

| 設定 | config | 預設值 | 含義 |
|---|---|---|---|
| 使用次數 | `services.line_bot.about_token_max_uses` | `5` | 單把連結最多被使用幾次 |
| 有效天數 | `services.line_bot.about_token_expires_days` | `7` | 簽發後幾天過期 |

> 「每日簽發次數」管的是**產出連結的頻率**；「max_uses / expires」管的是**每條連結本身能被用多久、多少次**——兩者是獨立的兩層。

---

## 5. 總結

- 路由層用 Laravel 的 `throttle` 做固定頻率限制（§1）。
- 部分功能改用程式碼內部邏輯做自訂冷卻（文章生成 §2、LINE 簽發 §4）。
- 密碼重設另有獨立冷卻設定（§3）。

這份文件的定位是**系統保護方式與設定值的總覽**，讓開發者知道哪些功能有速率保護、後續維護時哪裡可能需要補強；刻意不寫成「逐一列出可被濫用入口」的攻擊面分析報告。
