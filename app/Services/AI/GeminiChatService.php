<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GeminiChatService
{
    private string $apiKey;
    private string $defaultModel;

    /**
     * @var array<int, string>
     */
    private array $models;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.api_key', '');
        $this->defaultModel = (string) config('services.gemini.model', 'gemini-2.5-flash');

        $configured = config('services.gemini.models', []);
        $this->models = is_array($configured)
            ? array_values(array_filter(array_map('strval', $configured)))
            : [];

        if ($this->models === []) {
            $this->models = [$this->defaultModel];
        }
    }

    /**
     * Send a multi-turn chat message grounded on imported resume context.
     *
     * @param  array<int, array{role: string, text: string}>  $history  Previous turns [['role'=>'user'|'model', 'text'=>'...']]
     * @return string  The model reply text
     */
    public function chat(string $message, array $history = []): string
    {
        if ($this->apiKey === '') {
            throw new AIServiceException('GEMINI_API_KEY is not configured.');
        }

        $contents = [];

        $context = $this->loadContext();
        if ($context === '') {
            return '目前尚未匯入任何履歷背景資料，請先由管理員在 About 頁面匯入 Context。';
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $this->buildSystemPrompt($context)]],
        ];
        $contents[] = [
            'role' => 'model',
            'parts' => [['text' => '了解，我會根據這份背景資料來回答訪客的問題。']],
        ];

        // Append conversation history
        foreach ($history as $turn) {
            $contents[] = [
                'role'  => $turn['role'],
                'parts' => [['text' => $turn['text']]],
            ];
        }

        // Append current message
        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => $message]],
        ];

        $attempted = [];

        foreach ($this->models as $_unused) {
            $model = $this->nextModel();
            $response = Http::withQueryParameters(['key' => $this->apiKey])
                ->acceptJson()
                ->timeout(30)
                ->post($this->endpointForModel($model), [
                    'contents' => $contents,
                ]);

            Log::debug('GeminiChat response', ['status' => $response->status(), 'model' => $model]);

            if ($response->ok()) {
                return $this->extractText($response->json());
            }

            $attempted[] = $model.':'.$response->status();

            // Skip unavailable model (404) or quota/rate-limited model (429) and try next one.
            if (!in_array($response->status(), [404, 429], true)) {
                throw new AIServiceException('Gemini chat request failed: '.$response->status().' (model: '.$model.')');
            }
        }

        throw new AIServiceException(
            'Gemini chat request failed across models ['.implode(', ', $attempted).'] '
            .'(404: model unavailable, 429: quota/rate limit).'
        );
    }

    private function endpointForModel(string $model): string
    {
        return sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $model,
        );
    }

    private function nextModel(): string
    {
        if (count($this->models) <= 1) {
            return $this->models[0] ?? $this->defaultModel;
        }

        $cacheKey = 'gemini_chat_model_rotation_index';

        if (!Cache::has($cacheKey)) {
            Cache::forever($cacheKey, 0);
        }

        $next = (int) Cache::increment($cacheKey);
        $index = ($next - 1) % count($this->models);

        return $this->models[$index] ?? $this->defaultModel;
    }

    /**
     * Save resume context to private storage.
     */
    public function saveContext(string $context): void
    {
        Storage::put('private/resume_context.md', $context);
    }

    /**
     * Load resume context from private storage.
     */
    public function loadContext(): string
    {
        if (!Storage::exists('private/resume_context.md')) {
            return '';
        }

        return (string) Storage::get('private/resume_context.md');
    }

    private function buildSystemPrompt(string $context): string
    {
        return implode("\n\n", [
            '你是一位後端工程師的個人助理，負責代表他回答訪客的問題。',
            '以下是關於這位工程師的背景資料，請只能依據這些資料回答問題。',
            '如果資料中沒有明確資訊，請直接回答：「這部分我的資料裡沒有記載，我不想亂回答。」',
            '禁止推測、禁止腦補、禁止自行添加未提供的經歷、技能、公司、學歷、年份與數字。',
            '若問題要求比較、評價或延伸建議，也只能基於已提供資料進行，不能虛構前提。',
            '請用親切、專業的語氣回答，以第一人稱「我」代表這位工程師說話。',
            '--- 背景資料 ---',
            $context,
            '--- 資料結束 ---',
        ]);
    }

    /**
     * @param mixed $payload
     */
    private function extractText(mixed $payload): string
    {
        if (!is_array($payload)) {
            return '';
        }

        $candidates = $payload['candidates'] ?? [];

        if (!is_array($candidates) || !isset($candidates[0]['content']['parts'])) {
            return '';
        }

        $texts = [];
        foreach ($candidates[0]['content']['parts'] as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $texts[] = trim($part['text']);
            }
        }

        return trim(implode("\n", array_filter($texts)));
    }
}
