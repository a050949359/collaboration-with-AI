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

> Controllers／Models／pages 等清單會頻繁增減，**一律看 repo**，不在此列舉（列了會 drift）。
> 這裡只留少數穩定、且「看 code 不易察覺」的架構指標：

- **URL 唯一來源**：`resources/js/lib/routes.ts`（`routes.*` 頁面 + `api.*` API）。元件一律從這拿，禁止硬編碼路徑。
- **前端 API wrapper**：`lib/auth-api.ts`（用 `api.auth.*`、無 CSRF）、`lib/crypto.ts`（password 走 RSA-OAEP 加密）。
- **關鍵 Middleware**：`DecryptPasswordFields`（解密 password 欄位）、`HandleInertiaRequests`（注入 Inertia 共享 props + `pageProps()` 依路由給 enum values）。
- **頭像**：`Support/AvatarGenerator::defaultFor()` 回傳 URL；`User::avatar()` 列在 `$appends`，前端 `user.avatar` 直接可用。
- **路由分檔**：`routes/web.php` 全掛 `prefix('app')`（Inertia pages）；`routes/api.php` 為 REST（`/api/...`）。
- `composables/useAuth.ts` 提供 `user`、`isAdmin`（來自 Inertia shared props）。
- `layouts/`：`AppLayout.vue`（主框架：Navbar、Matrix Rain、Toast）、`AuthShell.vue`（登入/註冊）。

---

## 核心約定

### URL 管理
- **所有 URL 只在 `resources/js/lib/routes.ts` 定義**
- 前端元件用 `routes.*` 取頁面路由、`api.*` 取 API 路由
- 禁止在 Vue 元件中硬編碼路徑字串

### 導覽結構（Navbar）
- 頂層：`Home · 航空▾ · CV▾ · AI▾ · WS▾ · MCP▾ · Apps▾`（定義在 `AppLayout.vue` 的 `defaultNavLinks`）
- 群組與成員：
  - **CV**：邊緣偵測（`computer-vision`）、手勢辨識（`gesture`）
  - **AI**：文章、About（Ask Me 問答）、Story（admin only）
  - **WS**（WebSocket 縮寫）：ws-lab、Gacha
  - **MCP**：Task（任務 UI，route `task`；原 `mcp`）、Memory（知識圖譜）
  - **Apps**：Tour、LineBot、mini-orch
- 手機版由 `NavDrawer.vue` 吃**同一份** `defaultNavLinks` 渲染（項目有 `children` 即摺疊 accordion），改 nav 只需動 `defaultNavLinks`
- 圖示集中在 `NavIcon.vue`，以 `name` 字串 switch；**新增群組/頁面要補對應 icon**（無對應 name 不會報錯但顯示空白）
- ⚠️ `MCP` 群只是導覽分類；真正的 MCP server endpoint 是 `api.mcp`（`/api/mcp/*`），與 web 的 `task` route 無關

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

### 主題系統（Theme Registry）

- **單一來源**：`resources/js/composables/useTheme.ts` 的 `THEME_REGISTRY`
- 每個主題有兩個欄位：
  - `cardClass`：hover 效果 canvas 的 CSS class **名稱**（不含 `.`）
  - `primaryColor`：主題切換按鈕顯示的顏色（hex）
- `export const themes`：有序陣列，決定切換順序（emerald → amber → ink-zen → ...）
- 主題值存在 `localStorage`，`app.blade.php` inline script 提前讀取並套用 `data-theme`（防 FOUC），以 regex `/^[a-z0-9-]+$/` 驗證避免 XSS

**新增主題時需同步以下五處：**

| 步驟 | 檔案 | 說明 |
|------|------|------|
| 1 | `useTheme.ts` | `THEME_REGISTRY` 加新 key，填 `cardClass` + `primaryColor` |
| 2 | `app.css` | 加 `[data-theme='xxx']` 區塊，覆蓋所有 `--binary-*` CSS 變數 |
| 3 | `AppLayout.vue` | `bgComponents` map 加對應背景元件 |
| 4 | `useCardEffectsXxx.ts` | 實作新 hover 效果 composable |
| 5 | `useThemeCardEffect.ts` | 呼叫新 composable，selector 用 `` `.${THEME_REGISTRY['xxx'].cardClass}` `` |

> ⚠️ **常見陷阱**：`cardClass` 是 class 名稱（無 `.`），傳入 `querySelectorAll` 前必須加 `.` 前綴，否則會被當成 HTML tag selector，選不到任何元素。

**現有主題一覽：**

| 主題 | `data-theme` | 背景元件 | hover 效果 | 風格 |
|------|-------------|----------|-----------|------|
| Emerald | `emerald`（預設） | `MatrixRainBackground` | 3D tilt + glow | 深色，綠色賽博 |
| Amber | `amber` | `BlobBackground` | 邊框流光 | 深色，橙紫漸層 |
| Ink Zen | `ink-zen` | `BirdFlockBackground` | 毛筆筆觸 | 淺色，水墨 |

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

新頁面所有顏色**必須用 `--binary-*` CSS 變數，禁止 hardcode hex/rgba**。變數定義在 `resources/css/app.css`，`[data-theme='amber']`／`[data-theme='ink-zen']` 區塊自動覆蓋（**確切 hex 要用就查 app.css**，不在此列）。挑變數看語意，別記顏色值：

- **強調色**：`--binary-primary`（主強調／連結／icon／active）、`--binary-primary-container`（按鈕漸變深端）、`--binary-primary-fixed`（較淡版）、`--binary-secondary`（次強調、`.text-gradient-primary` 終點）、`--binary-tertiary`（錯誤/警告字）、`--binary-on-primary-container`（主色按鈕上的字）
- **文字色**：`--binary-text`（內文）、`--binary-text-muted`（次要）、`--binary-outline`（標籤/placeholder/border）、`--binary-outline-variant`（極細 border/分隔線）
- **背景面板**（多為 0.7 透明）：`--binary-background`（頁面底，solid）；面板層級由淺至深 `--binary-surface`／`-dim`／`-low`／`-lowest`／`-high`／`-highest`（一般面板/sidebar/select/input/按鈕card/hover）；`--binary-surface-container`（0.3，inactive 極淡容器）

**常用 class**：`binary-button`（主色漸變鈕）、`binary-ghost-button`（ghost 鈕）、`binary-glass`（含 backdrop-filter 玻璃面板）、`text-gradient-primary`（primary→secondary 漸層字，雙主題自動切）；帶透明度用 Tailwind v4 語法 `text-[var(--binary-primary)]/60`。

**禁止 / 例外**：
- ❌ `style="color:#6bdc9f"` → ✅ `var(--binary-primary)`
- ❌ `bg-[#1d2a22]` → ✅ `bg-[var(--binary-surface-high)]`
- ❌ `rgba(107,220,159,0.1)` → ✅ `color-mix(in srgb, var(--binary-primary) 10%, transparent)`
- ✅ 可保留 hardcode 的例外：品質金 `#d4af37`、D3/Canvas 視覺化特定色

---

## Git 工作流程

> **禁止直接 push 到 main**，所有變更都需要透過 feature branch + PR 流程。

```bash
# 1. 從 main 開新分支
git checkout main && git pull
git checkout -b feat/xxx   # 或 fix/xxx

# 2. 開發、commit（lint 先過）

# 3. Push 分支（首次自動建立遠端分支）
git push origin HEAD

# 4. 開 PR
gh pr create --title "feat(xxx): ..." --body "..."

# 5. PR 合併後，本地同步 main
git checkout main && git pull
```

**分支命名慣例：**`feat/`、`fix/`、`refactor/`、`chore/`

---

## PR 開完後：Antigravity Code Review（agy）

> push + 開 PR 後，可呼叫 Antigravity CLI（`agy`）對該 PR 做自動 code review，結果以 PR comment 發回 GitHub。

- **工具**：`scripts/agy-review.sh <PR_NUMBER> [model]`（預設模型 `Gemini 3.1 Pro (High)`）
- **工作流程**：`gh pr create` 拿到 PR 號 → **背景** 跑 `scripts/agy-review.sh <PR>`（fire-and-forget，**不需等待/觀察執行完畢**）→ 腳本自己會把 comment 貼到 GitHub 並回查 sentinel，報 PASS/FAIL + comment URL（log 寫在暫存檔，事後可撈）
- review **準則內嵌在腳本的 prompt**，刻意**不放進這份 CLAUDE.md**：CLAUDE.md 是給 Claude 看的、且含「叫 agy review」這種 meta 指令，若讓 agy 讀會自我指涉混淆。**agy 不讀 CLAUDE.md。**
- **三層權限防護**（讓 agy 安全地只做 review、碰不到 repo 檔）：
  1. agy `settings.json` 只放行 `command(gh)` → headless 不需 `--dangerously-skip-permissions`
  2. agy 在空目錄 `~/antigravity`（`AGY_WORKDIR`）啟動 → 非互動模式啟動時取得的「資料夾讀寫權限」只落在這個可丟棄空目錄，碰不到 repo
  3. 所有 gh 指令帶 `-R <owner/repo>` → 不在 repo 目錄裡也能操作正確 repo
- **前置需求**：
  - agy 已登入；`~/.gemini/antigravity-cli/settings.json` 含 `{"permissions":{"allow":["command(gh)"]}}`
  - gh token 需 **Pull requests: Read and write**（否則 `gh pr comment` 的 `addComment` 會被 GitHub 擋）

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

### 本機 CLI（cmd/memctl、cmd/taskctl）
打上述兩個 MCP server 的精簡 Go CLI client，取代冗長 curl、也免 native MCP 連線常駐（省 token）。token / URL 自動讀 `.vscode/mcp.json`。

> ⚠️ binary 為 **gitignore**（同 `cmd/ws-lab` 慣例），clone 後沒有執行檔，**需先用 Go 編譯**：
> `cd cmd/memctl && go build -o memctl .`（`taskctl` 同理）。

**確認用法：直接執行 binary（不帶參數）即印出完整說明，不需要讀 source code：**
```bash
cmd/taskctl/taskctl     # 印 usage
cmd/memctl/memctl       # 印 usage
```

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
