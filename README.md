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