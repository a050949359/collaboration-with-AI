<?php

namespace App\Services\AI\Ollama;

use App\Services\AI\AIServiceException;
use App\Services\AI\Contracts\ChatCompletion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ollama chat adapter (local, no auth: POST {host}/api/chat).
 *
 * Structured output: Ollama 0.5+ 的 `format` 欄位可直接吃 JSON Schema 物件，
 * 故結構化輸出比 Nvidia 嚴格（由 server 端 constrained decoding 保證）。
 */
class OllamaChatService implements ChatCompletion
{
    private string $baseUrl;
    private string $model;

    public function __construct(?string $model = null)
    {
        $this->baseUrl = rtrim((string) config('services.llm.providers.ollama.base_url', 'http://localhost:11434'), '/');
        $this->model   = $model ?? (string) (config('services.llm.uses.story.model') ?? 'llama3.1');
    }

    public function generate(string $systemPrompt, array $messages = [], array $options = []): string
    {
        $payload = [
            'model'    => $this->model,
            'messages' => $this->buildMessages($systemPrompt, $messages),
            'stream'   => false,
            'options'  => [
                'temperature' => (float) ($options['temperature'] ?? 0.8),
            ],
        ];

        if (isset($options['json_schema']) && \is_array($options['json_schema'])) {
            $payload['format'] = $options['json_schema'];
        }

        $response = Http::acceptJson()
            ->timeout(180)
            ->post($this->baseUrl . '/api/chat', $payload);

        Log::debug('OllamaChatService response', ['status' => $response->status(), 'model' => $this->model]);

        if (!$response->ok()) {
            throw new AIServiceException(
                'Ollama request failed: ' . $response->status() . ' (model: ' . $this->model . ', host: ' . $this->baseUrl . ')'
            );
        }

        return $this->extractText($response->json());
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

        $content = $payload['message']['content'] ?? '';

        return \is_string($content) ? trim($content) : '';
    }
}
