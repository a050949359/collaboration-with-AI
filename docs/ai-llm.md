# AI / LLM 抽象層

> [!IMPORTANT]
> 🎯 **管理者可在後台給每個已註冊用途單獨設定 provider/model,即時生效**(免改 code、免重啟)。

統一 LLM / embedding 的呼叫入口。消費端只認「**用途(use)**」,不綁某家 API。

**用途的單一來源 = `app/Enums/LlmUse.php`**(enum)。它的 `value` 同時是 config key、後台儲存 key、`for()` 的用途名;`label()` 是後台顯示文字。現有:`Story` / `StoryState` / `Character` / `Resume`(About 履歷對話)/ `Rag`(RAG 問答)。**每個用途獨立**,互不影響。

## 串接(消費端怎麼用)

- **chat 類**(注入 `LlmManager`,`for(LlmUse::X)->generate(...)`;provider/model 由後台/config 決定,消費端不需知道是哪家)。完整範例見下方〈新增一個用途〉。
  ```php
  $this->llm->for(LlmUse::Rag)->generate($systemPrompt, $messages, $options); // 回字串
  ```
- **embedding**(container 固定綁定,換實作改 `AppServiceProvider`):
  ```php
  app(App\Services\AI\Contracts\TextEmbedding::class)->embed($text, ['task_type' => 'RETRIEVAL_QUERY']);
  ```

## 設定(後台)

**Admin → System → AI 模型**:每個已註冊用途各自選 provider + model,「儲存」即時生效。
- 寫入 Redis(`admin_settings`),**優先於 `.env`/config**。
- 可按「測試」實打驗證該 provider/model 通不通。

## 新增一個用途(以 `rag` 為例)

**只有第 1 步是必須的**;前端後台會自動出現(由 enum 經 Inertia props 產生),消費端型別安全。

1. **`app/Enums/LlmUse.php` 加一個 case + label**(用途的單一來源):
   ```php
   case Rag = 'rag';
   // label(): self::Rag => 'RAG 問答',
   ```
2. 消費端注入 `LlmManager`,用 `->for(LlmUse::Rag)` 呼叫:
   ```php
   use App\Enums\LlmUse;
   use App\Services\AI\LlmManager;

   class RagService
   {
       public function __construct(private readonly LlmManager $llm) {}

       public function answer(string $question, string $context): string
       {
           // system prompt:把依據/規則塞進去
           $system = implode("\n\n", [
               '你是知識庫問答助理,只能依據以下參考資料回答。',
               '--- 參考資料 ---', $context, '--- 參考資料結束 ---',
           ]);

           // messages:單輪一則 user;多輪可帶歷史 [{role:user|assistant, text}]
           $messages = [['role' => 'user', 'text' => $question]];

           return $this->llm->for(LlmUse::Rag)->generate($system, $messages, [
               'temperature' => 0.3,        // 中性選項,各 provider 自行翻譯
               // 'json_schema' => [...],   // 要結構化(JSON)輸出時給
               // 'max_tokens'  => 1024,
           ]);
       }
   }
   ```
   > `generate()` 回**字串**;要結構化輸出傳 `json_schema`(provider 轉成自家格式)。
3. **(可選)** 要 env 覆蓋預設 provider/model 時,`config/services.php` 的 `llm.uses` 加(key 綁 enum value):
   ```php
   LlmUse::Rag->value => ['provider' => env('LLM_RAG_PROVIDER', 'gemini'), 'model' => env('LLM_RAG_MODEL', 'gemini-2.5-flash')],
   ```
   不加則自動退回 `gemini` + 預設 model。

> **不用再手動改前端**:`Admin/System.vue` 的用途列表由 `LlmUse::options()` 經 `HandleInertiaRequests` 注入 `llmUses` 自動產生。

## 注意

- **後台覆蓋 `.env`**:改 `.env` 沒效時,去 System 頁改、或清 `admin_settings`。
- provider 設成端點不通的家(如 nvidia/ollama 沒跑)會卡到 timeout → 同步請求可能 **504**。
- gemini 走 AI Studio 有**地區限制**,主機在不支援地區設 `GEMINI_PROXY`(支援地區的出口中繼)。
- model 名稱以 [Gemini API models](https://ai.google.dev/gemini-api/docs/models?hl=zh-tw) 為準(preview 模型含 `-preview` 後綴,打錯會逾時)。
