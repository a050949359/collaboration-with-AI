# collaboration-with-AI

🌐 **https://ohya.vip/app**

## 核心特色

### 🔐 RSA-OAEP 加密傳輸
登入與註冊的密碼於瀏覽器以 Server 公鑰 RSA-OAEP 加密後送出，後端以私鑰解密 —— 明文不在前端到後端之間的任何中介層出現。

### 🤖 MCP Server 自行實作
基於 JSON-RPC 2.0 實作 Model Context Protocol endpoint，供 Claude Desktop 等 AI Assistant 以 API Key 呼叫。提供任務管理（Task CRUD + 子項目）共 8 個工具，以及知識圖譜讀寫（Admin）共 8 個工具，是 2024 年後 AI 整合的新興標準。

### 🌐 外部資料整合與清洗
設計 Artisan 指令從 Wikidata SPARQL 自動抓取、清洗、補全多語系航空資料（涵蓋 84,000+ 機場 / 850+ 航空公司），處理重複資料、缺漏 IATA/ICAO 代碼、語言 fallback（zh-tw → zh）等問題，並支援 dry-run 預覽與增量更新。

### ⚡ 自製 Go WebSocket Server
以自行用 Go 實作的 WebSocket Server（ws-lab）驅動多房間即時同步，採 goroutine 各擁房間狀態、channel 序列化存取的併發模型。gacha 抽卡的隨機與發牌在 server 端執行並查詢房間狀態防作弊，結果即時推播給同房間玩家。

### 🧠 多角色 LLM 故事系統
多個 LLM 角色輪流推進故事，維護共享世界狀態與道具系統，並以定時排程驅動劇情進展，展示 LLM 在結構化狀態管理下的應用。

---

## Tech Stack

- Laravel 13.5 / PHP 8.4
- Vue 3 / Vite / Tailwind CSS
- Inertia.js / @vueuse/core / d3
- MySQL、SQLite
- Redis

---

## 主要功能

### 使用者與帳號
- 註冊、登入（RSA-OAEP 加密傳輸）、Google OAuth 綁定
- 信箱驗證、忘記密碼 / 重設密碼
- 帳號設定：改名、修改密碼
- **密碼政策**：複雜度規則（12 碼以上，含大小寫、字母、數字、特殊符號）、**不可與最近 5 次密碼相同**（變更與重設皆檢查）、變更後 1 小時內不可再次變更
- **API Key 管理**：前端產生 RSA 金鑰對，Server 以公鑰加密回傳明文 key（私鑰僅存記憶體，不持久化）

### 文章
- AI 產生（Gemini / Vertex Imagen）、編輯、瀏覽、分類、標籤
- LINE Webhook 推送文章就緒通知

### 航空資料查詢
- 全球 84,000+ 機場（含地球儀視覺化）、850+ 航空公司
- 200+ 國家 / 城市（Wikidata 整合，使用者 UI 搜尋新增）

### 互動模組

站內導覽依性質分為 **CV / AI / WS / MCP / Apps** 幾組（外加航空資料查詢，見上）：

- **CV（Computer Vision）**
  - 邊緣偵測：WASM（OpenCV）即時邊緣偵測，可切 Canny / Laplacian / Sobel / Scharr
  - 手勢辨識：MediaPipe TFLite WASM 手部關鍵點 + 手勢分類（模型部署中）
- **AI**
  - 文章：AI 產生（Gemini / Vertex Imagen）、編輯、分類、標籤（見上）
  - Ask Me：個人技術問答（About 頁）
  - 故事接龍：多角色 LLM 輪流推進，含世界狀態、道具系統、定時排程
- **WS（WebSocket）**
  - ws-lab：自製 Go WebSocket server（多房間架構 + 管理介面 + 即時串流；生產環境需 nginx 將 `/ws-lab` proxy 到 Go binary，本地無 nginx 時無法連線）
  - Gacha 抽卡：多人房間同步抽卡，由 ws-lab 即時推播
- **MCP**
  - Task：站內任務管理 UI（對應自製 MCP task server，Task CRUD + 子項目，共 8 工具）
  - Memory：知識圖譜（對應 MCP memory server，Admin，共 8 工具）
  - agyd daemon：透過 MCP 呼叫本地微型主機上的 Go HTTP daemon（`/api/mcp/agyd`），支援 agy prompt 執行、預定義 script、ZIP 產出推回 VPS
- **Apps**
  - 旅遊 Playground：旅客、行程、訂單、PDF 匯出（Queue Worker 示範）
  - LineBot：LINE Webhook
  - mini-orch：輕量壓測 / 任務排程觀測介面

---

## 文件

### 安裝與操作
- [安裝與操作指南](docs/setup.md) — 安裝啟動、`.env` 金鑰、資料匯入、Artisan 指令、WebSocket（ws-lab）、MCP（Claude Desktop）設定與本機 CLI

### 架構與開發
- [架構與請求串接](docs/architecture.md) — 路由分檔、請求生命週期、middleware 權限、token 系統、enum 注入
- [AI / LLM 抽象層](docs/ai-llm.md) — 多 provider 串接、後台設定、新增用途
- [背景 Job / Queue](docs/jobs.md) — 運作機制、排程、失敗重試、`retry_after` 陷阱
- [新增 MCP tool / server](docs/mcp-tool.md) — Service 契約、scope 把關、動態 schema、Go CLI 客戶端
- [API Key / Scope](docs/api-keys.md) — API key 產生與 scope 設計
- [分享連結 share-token](docs/share-token.md) — share-token middleware
- [Rate Limit](docs/rate-limit.md) — 限流設計
- [RAG](docs/rag.md) — 向量檢索 / 知識庫
- [圖片](docs/images.md) — 圖片生成與上傳

### 前端
- [i18n](docs/frontend/i18n.md) — 多語系
- [路由](docs/frontend/routing.md) — 前端路由（`routes.ts` 單一來源）
- [主題](docs/frontend/theming.md) — 主題系統與 `--binary-*` 色彩變數
