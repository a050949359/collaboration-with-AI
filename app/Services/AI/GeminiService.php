<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey;
    private string $defaultModel;
    private bool $useRotation;

    /** @var array<int, string> */
    private array $models;

    public function __construct(?string $fixedModel = null)
    {
        $this->apiKey = (string) config('services.gemini.api_key', '');
        $this->defaultModel = (string) config('services.gemini.model', 'gemini-2.5-flash');

        if ($fixedModel !== null) {
            $this->models = [$fixedModel];
            $this->useRotation = false;
        } else {
            $configured = config('services.gemini.models', []);
            $this->models = \is_array($configured)
                ? array_values(array_filter(array_map('strval', $configured)))
                : [];

            if ($this->models === []) {
                $this->models = [$this->defaultModel];
            }

            $this->useRotation = \count($this->models) > 1;
        }
    }

    /**
     * Send a generation request with a system prompt and optional message history.
     *
     * @param  array<int, array{role: string, text: string}>  $messages
     */
    public function generate(string $systemPrompt, array $messages = []): string
    {
        if ($this->apiKey === '') {
            throw new AIServiceException('GEMINI_API_KEY is not configured.');
        }

        $contents = [];

        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => $systemPrompt]],
        ];
        $contents[] = [
            'role'  => 'model',
            'parts' => [['text' => '了解。']],
        ];

        foreach ($messages as $turn) {
            $contents[] = [
                'role'  => $turn['role'],
                'parts' => [['text' => $turn['text']]],
            ];
        }

        $attempted = [];

        foreach ($this->models as $_unused) {
            $model    = $this->nextModel();
            $response = Http::withQueryParameters(['key' => $this->apiKey])
                ->acceptJson()
                ->timeout(30)
                ->post($this->endpointForModel($model), [
                    'contents' => $contents,
                ]);

            Log::debug('GeminiService response', ['status' => $response->status(), 'model' => $model]);

            if ($response->ok()) {
                return $this->extractText($response->json());
            }

            $attempted[] = $model . ':' . $response->status();

            if (!in_array($response->status(), [404, 429], true)) {
                throw new AIServiceException('Gemini request failed: ' . $response->status() . ' (model: ' . $model . ')');
            }
        }

        throw new AIServiceException(
            'Gemini request failed across models [' . implode(', ', $attempted) . '] '
            . '(404: model unavailable, 429: quota/rate limit).'
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
        if (!$this->useRotation) {
            return $this->models[0] ?? $this->defaultModel;
        }

        $cacheKey = 'gemini_model_rotation_index';

        if (!Cache::has($cacheKey)) {
            Cache::forever($cacheKey, 0);
        }

        $next  = (int) Cache::increment($cacheKey);
        $index = ($next - 1) % \count($this->models);

        return $this->models[$index] ?? $this->defaultModel;
    }

    /** @param mixed $payload */
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
