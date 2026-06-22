# 前端路由與導覽

前端所有 URL 的**單一來源**是 [resources/js/lib/routes.ts](../../resources/js/lib/routes.ts)。元件一律從這裡取路徑，**禁止在 Vue 元件裡硬編碼** `/app/...` 或 `/api/...` 字串。

---

## 1. `routes.ts` 結構

匯出兩個物件，每個值都是**回傳字串的函式**（需要參數的就帶參數）：

| 匯出 | 對應 | 前綴 |
|---|---|---|
| `routes` | Inertia 頁面路由 | `/app`（常數 `WEB_PREFIX`） |
| `api` | REST API 路由 | `/api` |

```ts
// routes.ts
const WEB_PREFIX = '/app';

export const routes = {
    home: () => `${WEB_PREFIX}/`,
    articles: {
        index: () => `${WEB_PREFIX}/articles`,
        show: (id: number) => `${WEB_PREFIX}/articles/${id}`,
    },
    // ...
};

export const api = {
    auth: {
        login: () => '/api/auth/login',
        me: () => '/api/auth/me',
    },
    tasks: {
        index: () => '/api/v1/tasks',
        show: (id: number) => `/api/v1/tasks/${id}`,
    },
    // ...
};
```

元件裡這樣用：

```ts
import { routes, api } from '@/lib/routes';

router.visit(routes.articles.show(id));        // 頁面跳轉
const res = await fetch(api.tasks.index());    // 打 API
```

帶路徑參數時用 `encodeURIComponent`（既有寫法已遵循），可參考 `routes.resetPassword(token, email)`。

---

## 2. 與後端路由對齊

`routes.ts` 的值必須對得上後端：

- `routes.*` 對 [routes/web.php](../../routes/web.php)（全掛 `prefix('app')`）
- `api.*` 對 [routes/api.php](../../routes/api.php)（`/api/...`）

新增一條路由時，**兩邊都要動**：後端註冊 route，前端在 `routes.ts` 補對應函式。後端串接細節見 [../architecture.md](../architecture.md)。

> Avatar 路由是個例外陷阱：`routes.assets.avatarDefault(seed)` **不加 `.svg` 副檔名**（nginx 會攔截不存在的副檔名）。

---

## 3. 導覽列（Navbar）

導覽結構定義在 [AppLayout.vue](../../resources/js/layouts/AppLayout.vue) 的 `defaultNavLinks`（一個 `computed`）。

### 3.1 資料結構

每個項目是一個 `NavLink`：

```ts
{
    label: t('articles.nav.home'),   // i18n key，見 ./i18n.md
    href: routes.home(),             // 從 routes.ts 取，勿硬編碼
    icon: 'home',                    // 對應 NavIcon 的 name
    active: path.startsWith(routes.home()),
    children: [ /* 有 children 即為下拉群組 */ ],
}
```

- 頂層群組（如 `aviation`、`CV`、`AI`）通常**自己沒有 `href`**，只有 `children`，`active` 由子項的 path 聯集判斷。
- 手機版 [NavDrawer.vue](../../resources/js/components/NavDrawer.vue) 吃**同一份** `defaultNavLinks` 渲染（有 `children` 就摺疊成 accordion）。**改導覽只需動 `defaultNavLinks` 一處。**

### 3.2 圖示

圖示集中在 [NavIcon.vue](../../resources/js/components/NavIcon.vue)，以 `name` 字串做 `v-if / v-else-if` switch，每個 name 對應一段 inline SVG path。

> ⚠️ 新增群組／頁面時**要補對應 icon**。`name` 沒對到任何分支不會報錯，但會**顯示空白**。

---

## 4. 新增一個前端頁面：checklist

1. **後端** 在 `web.php` 註冊 Inertia route（`prefix('app')` 底下）。
2. **`routes.ts`** 補 `routes.xxx()`。
3. **頁面元件** 放 `resources/js/pages/`（或對應目錄），用 `routes.*` / `api.*` 取 URL。
4. **`defaultNavLinks`** 加入口（若要上導覽列），同時在 **`NavIcon.vue`** 補 icon。
5. **i18n** 補 label 文字，見 [./i18n.md](./i18n.md)。
6. **enum 注入**（若需要）在後端 `pageProps()` 加一筆，見 [../architecture.md](../architecture.md)。
7. **顏色** 一律用 `--binary-*` CSS 變數，見 [./theming.md](./theming.md)。
