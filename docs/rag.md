# RAG + MCP 設定指南

獨立的 RAG 系統：Laravel 負責 Drive 讀取、切塊、embedding、MCP 工具層；
向量存取交給 `vecgen`（純 Go / chromem-go，非常駐 CLI）。

```
Claude ─MCP /api/mcp/rag─▶ Laravel
                            ├─ DriveReader（SA 讀分享資料夾，抽 T1 純文字）
                            ├─ Chunker（切塊，建置中）
                            ├─ embedding（Google AI Studio：gemini-embedding-001 / -2）
                            └─ exec ─▶ vecgen（chromem-go）upsert/query ─▶ 持久化向量庫
```

向量資料持久在磁碟（`storage/app/rag_db`），vecgen 每次 exec 載入→操作→退出（零常駐）。

---

## 1. 編譯 vecgen（向量庫 CLI）

binary 已被列入 .gitignore，clone 後需自行編譯（同 ws-lab/memctl 慣例）：

```bash
cd cmd/vecgen && go build -o vecgen .
```

確認可執行（直接跑印 usage）：

```bash
cmd/vecgen/vecgen
```

> 需 Go 1.24+（chromem-go 為純 Go、CGO-free，免裝額外擴充）。

---

## 2. 設定 Google Drive 服務帳戶（SA）

文件來源是「分享給專用 SA 的 Drive 資料夾」。SA 是給程式用的 Google 身分，
**讀 Drive 的授權靠「在 Drive 分享資料夾給 SA email」，不在 GCP IAM 給角色。**

### 2-1. 建立 SA 並啟用 Drive API
**Console：**
1. GCP Console → **API 與服務 → 程式庫** → 搜「Google Drive API」→ **啟用**
2. **IAM 與管理 → 服務帳戶 → 建立服務帳戶**（例：`rag-drive`）
   - 不需指派任何 IAM 角色（Drive 權限走分享，不走 IAM）

**或用 gcloud：**
```bash
PROJECT_ID=<你的專案 ID>

# 啟用 Drive API
gcloud services enable drive.googleapis.com --project="$PROJECT_ID"

# 建立 SA（不需給任何 IAM 角色）
gcloud iam service-accounts create rag-drive \
  --display-name="RAG Drive Reader" --project="$PROJECT_ID"
```

### 2-2. 產 JSON 金鑰
**Console：** 點進該 SA → **金鑰** 分頁 → **新增金鑰 → 建立新的金鑰 → JSON**，下載。

**或用 gcloud（直接輸出到目標路徑）：**
```bash
gcloud iam service-accounts keys create storage/app/rag-drive-sa.json \
  --iam-account="rag-drive@${PROJECT_ID}.iam.gserviceaccount.com"
```

金鑰檔放到（`storage/app/.gitignore` 的 `*` 規則會自動忽略，不會 commit）：
```
storage/app/rag-drive-sa.json
```

> 若建立金鑰被擋，可能是組織政策 `iam.disableServiceAccountKeyCreation`，需在組織層解除。

### 2-3. 分享資料夾給 SA
1. 在 **Google Drive 網頁**，對要當語料的資料夾按「分享」
2. 貼上 SA 的 email（`xxx@<專案>.iam.gserviceaccount.com`，在金鑰 JSON 的 `client_email`），設**檢視者**
3. 複製資料夾連結，取出 `/folders/<這串>` 的 ID

---

## 3. 環境變數（.env）

```dotenv
# 既有：Google AI Studio（embedding 與 LLM 共用）
GEMINI_API_KEY=<你的 key>

# RAG：Drive 來源
RAG_DRIVE_FOLDER_ID=<步驟 2-3 取得的資料夾 ID>
RAG_DRIVE_CREDENTIALS_PATH=        # SA 金鑰路徑，留空用預設 storage/app/rag-drive-sa.json

# RAG：embedding 模型（皆可留空用預設）
GEMINI_EMBEDDING_MODEL=            # 預設 gemini-embedding-001；多模態改 gemini-embedding-2
GEMINI_EMBEDDING_DIMENSIONS=       # 預設 768

# RAG：vecgen（皆可留空用預設）
VECGEN_BIN=                        # 預設 base_path(cmd/vecgen/vecgen)
VECGEN_DB=                         # 預設 storage_path(app/rag_db)
```

embedding 模型（`config/services.php` 的 `gemini.embedding_model`，或上面 env 覆寫）：
- 預設 `gemini-embedding-001`（純文字、支援 task_type、768 維）
- 要多模態（文字+圖片同向量空間、可跨模態檢索）改 `gemini-embedding-2`

> ⚠️ embedding 模型決定向量空間，**不同模型/維度的向量不可比**。換模型需重新 embed
> 整個語料（collection 命名會帶模型+維度區分，避免混入）。

---

## 4. 前端資產與 MCP key

```bash
npm run build        # 前端（Rag.vue 等）需編譯資產；開發時可用 npm run dev（HMR）
```

要讓 Claude（Desktop/Code）經 MCP 操作知識庫，需建一把 `rag:mcp` scope 的 API key
（任何登入者可在 UI 自建），Claude 設定帶 `Authorization: Bearer <key>` 打 `/api/mcp/rag`。

> embedding 與 Q&A chat **共用同一把 `GEMINI_API_KEY`**（皆走 Google AI Studio）。

---

## 5. 驗證

設定完後可在 tinker 確認各環節：

```php
// Drive 讀取
$docs = (new App\Services\Rag\DriveReader)->listAndExtract();
// 文字 embedding
$vec = app(App\Services\AI\Contracts\TextEmbedding::class)->embed('測試', ['task_type' => 'RETRIEVAL_QUERY']);
```

vecgen 端到端（存一筆、查一筆）：

```bash
DB=storage/app/rag_db
echo '{"documents":[{"id":"a","content":"hi","embedding":[1,0,0]}]}' | cmd/vecgen/vecgen upsert --db $DB
echo '{"embedding":[1,0,0],"top_k":1}' | cmd/vecgen/vecgen query --db $DB
```

---

## 現況（建置進度）

| 元件 | 狀態 |
|------|------|
| `vecgen` 向量庫 CLI（chromem-go，5 命令 + where/where_document 過濾 + 讀寫鎖） | ✅ |
| `TextEmbedding` / `MultimodalEmbedding`（Gemini，container 綁定） | ✅ |
| `DriveReader`（SA 讀資料夾、T1 純文字抽取） | ✅ |
| `Chunker`（遞迴邊界切：段落>行>句>硬切 + 小塊合併） | ✅ |
| `RagService`（草稿/commit/檢索/Q&A、collection 命名、向量快取） | ✅ |
| `LockService`（per-document 編輯鎖、交接 token） | ✅ |
| MCP `/api/mcp/rag`（列檔/建庫/preview/鎖/宣告式編輯/commit/query/near-dup） | ✅ |
| REST `v1/rag/*` + 前端 `Rag.vue`（總覽 / 編輯器 / 問答） | ✅ |

> T1 = Google 原生檔（Docs/Sheets/Slides，走 export）+ 純文字類上傳檔。
> PDF / Office / 圖片多模態為後續。

**未做（後續）**：probe + 列表掃描閘門、`rag_eval`（量測）、`rag_check`（無上下文 verifier）、
相似度門檻、全文入口 + 結構偵測。MCP 端點尚未 live JSON-RPC 實測。
