<?php

namespace App\Services\AI\Nvidia;

use App\Services\AI\AIServiceException;
use App\Services\AI\Contracts\ChatCompletion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NVIDIA NIM chat adapter (OpenAI-compatible: /v1/chat/completions).
 *
 * Structured output: NIM 各模型對 response_format=json_schema 支援不一，
 * 故採穩健策略 —— response_format=json_object + 把 schema 以文字附加到 system prompt，
 * 由 caller 端的防禦性 json_decode 收尾。
 */
class NvidiaChatService implements ChatCompletion
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;

    public function __construct(?string $model = null)
    {
        $this->apiKey  = (string) config('services.llm.providers.nvidia.api_key', '');
        $this->baseUrl = rtrim((string) config('services.llm.providers.nvidia.base_url', 'https://integrate.api.nvidia.com/v1'), '/');
        $this->model   = $model ?? (string) (config('services.llm.uses.story.model') ?? 'meta/llama-3.3-70b-instruct');
    }

    public function generate(string $systemPrompt, array $messages = [], array $options = []): string
    {
        if ($this->apiKey === '') {
            throw new AIServiceException('NVIDIA_API_KEY is not configured.');
        }

        $wantsJson = isset($options['json_schema']) && \is_array($options['json_schema']);

        if ($wantsJson) {
            $systemPrompt .= "\n\n請只輸出符合以下 JSON Schema 的有效 JSON，不要加任何說明文字或 markdown 圍欄：\n"
                . json_encode($options['json_schema'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $payload = [
            'model'       => $this->model,
            'messages'    => $this->buildMessages($systemPrompt, $messages),
            'temperature' => (float) ($options['temperature'] ?? 1.0),
            'top_p'       => 0.95,
            'max_tokens'  => (int) ($options['max_tokens'] ?? 8192),
            'stream'      => false,
        ];

        if ($wantsJson) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $lastStatus = 0;
        $lastBody   = '';

        // NIM 免費額度常見 429/503（過載/冷啟動），做有限次重試 + backoff。
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout(180)
                ->post($this->baseUrl . '/chat/completions', $payload);

            if ($response->ok()) {
                return $this->extractText($response->json());
            }

            $lastStatus = $response->status();
            $lastBody   = $response->body();

            Log::debug('NvidiaChatService retry', [
                'status'  => $lastStatus,
                'model'   => $this->model,
                'attempt' => $attempt,
            ]);

            if (!\in_array($lastStatus, [429, 500, 503], true)) {
                break; // 非暫時性錯誤（如 400/401/404），重試也沒用
            }

            if ($attempt < 3) {
                usleep(500_000 * $attempt); // 0.5s、1s 遞增退避
            }
        }

        throw new AIServiceException(
            'Nvidia request failed: ' . $lastStatus . ' (model: ' . $this->model . ') '
            . mb_substr($lastBody, 0, 300)
        );
    }

    /**
     * @param  array<int, array{role: string, text: string}>  $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(string $systemPrompt, array $messages): array
    {
        $out = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($messages as $turn) {
            $out[] = [
                'role'    => in_array($turn['role'], ['assistant', 'model'], true) ? 'assistant' : 'user',
                'content' => $turn['text'],
            ];
        }

        return $out;
    }

    /** @param mixed $payload */
    private function extractText(mixed $payload): string
    {
        if (!\is_array($payload)) {
            return '';
        }

        $content = $payload['choices'][0]['message']['content'] ?? '';

        return \is_string($content) ? trim($content) : '';
    }
}
