# 前端主題與顏色

兩件事：**(A) 顏色一律用 `--binary-*` CSS 變數**，**(B) 整套主題由 Theme Registry 驅動**。新頁面遵守 (A) 就能自動跟著主題切換；要新增一個主題則照 (B) 的步驟。

---

## A. 顏色規範

新頁面所有顏色**必須用 `--binary-*` CSS 變數，禁止 hardcode hex / rgba**。變數定義在 [resources/css/app.css](../../resources/css/app.css)，各主題的 `[data-theme='xxx']` 區塊會自動覆蓋這些變數的值。**挑變數看語意，不要記顏色值**（確切 hex 要查 `app.css`）。

### 常用語意變數

- **強調色**：`--binary-primary`（主強調／連結／icon／active）、`--binary-primary-container`（按鈕漸變深端）、`--binary-secondary`（次強調、漸層字終點）、`--binary-tertiary`（錯誤／警告字）
- **文字色**：`--binary-text`（內文）、`--binary-text-muted`（次要）、`--binary-outline`（標籤／placeholder／border）、`--binary-outline-variant`（極細分隔線）
- **背景面板**（多為半透明）：`--binary-background`（頁底，solid）；面板由淺至深 `--binary-surface` / `-dim` / `-low` / `-lowest` / `-high` / `-highest`

### 常用 class

`binary-button`（主色漸變鈕）、`binary-ghost-button`（ghost 鈕）、`binary-glass`（玻璃面板含 backdrop-filter）、`text-gradient-primary`（primary→secondary 漸層字，會隨主題自動切）。帶透明度用 Tailwind v4 語法：`text-[var(--binary-primary)]/60`。

### 禁止 / 例外

```
❌ style="color:#6bdc9f"           ✅ var(--binary-primary)
❌ bg-[#1d2a22]                     ✅ bg-[var(--binary-surface-high)]
❌ rgba(107,220,159,0.1)            ✅ color-mix(in srgb, var(--binary-primary) 10%, transparent)
```

可保留 hardcode 的例外：品質金 `#d4af37`、D3/Canvas 視覺化的特定色。

---

## B. Theme Registry

主題的單一來源是 [resources/js/composables/useTheme.ts](../../resources/js/composables/useTheme.ts) 的 `THEME_REGISTRY`：

```ts
export const THEME_REGISTRY = {
    emerald: { cardClass: 'js-tilt-card', primaryColor: '#6bdc9f' },
    amber: { cardClass: 'blob-card', primaryColor: '#ffb690' },
    'ink-zen': { cardClass: 'ink-card', primaryColor: '#6b6d6a' },
} satisfies Record<string, { cardClass: string; primaryColor: string }>;

export const themes = Object.keys(THEME_REGISTRY) as Theme[];  // 有序，決定切換順序
```

- `cardClass`：該主題 hover 效果 canvas 的 CSS class **名稱**（不含 `.`）。
- `primaryColor`：主題切換按鈕顯示的顏色（hex）。
- `themes` 陣列順序＝切換循環順序（emerald → amber → ink-zen → 回到 emerald）。
- 主題值存 `localStorage` 的 `theme` key；`app.blade.php` 的 inline script 會在頁面渲染前讀取並套用 `data-theme`（防 FOUC），並以 regex `/^[a-z0-9-]+$/` 驗證避免 XSS。
- `useTheme()` 提供 `initTheme()`、`toggleTheme()`。

### 現有主題

| 主題 | `data-theme` | 背景元件 | hover 效果 | 風格 |
|---|---|---|---|---|
| Emerald | `emerald`（預設） | `MatrixRainBackground` | 3D tilt + glow | 深色，綠色賽博 |
| Amber | `amber` | `BlobBackground` | 邊框流光 | 深色，橙紫漸層 |
| Ink Zen | `ink-zen` | `BirdFlockBackground` | 毛筆筆觸 | 淺色，水墨 |

---

## C. 新增一個主題：五步驟

| 步驟 | 檔案 | 做什麼 |
|---|---|---|
| 1 | `useTheme.ts` | `THEME_REGISTRY` 加新 key，填 `cardClass` + `primaryColor` |
| 2 | `app.css` | 加 `[data-theme='xxx']` 區塊，覆蓋所有 `--binary-*` 變數 |
| 3 | `AppLayout.vue` | `bgComponents` map 加對應背景元件 |
| 4 | `useCardEffectsXxx.ts` | 實作新的 hover 效果 composable |
| 5 | `useThemeCardEffect.ts` | 呼叫新 composable，selector 用 `` `.${THEME_REGISTRY['xxx'].cardClass}` `` |

> ⚠️ **最常見的坑**：`cardClass` 是 class 名稱（無 `.`）。傳進 `querySelectorAll` 前**必須自己加 `.` 前綴**，否則會被當成 HTML tag selector，一個元素都選不到。
