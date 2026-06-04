<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\AIServiceException;
use App\Services\AI\Contracts\ChatCompletion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiChatService implements ChatCompletion
{
    private string $apiKey;
    private string $defaultModel;
    private bool $useRotation;

    /** @var array<int, string> */
    private array $models;

    public function __construct(?string $fixedModel = null)
    {
        $this->apiKey = (string) config('services.gemini.api_key', '');
        if ($fixedModel !== null) {
            $this->defaultModel = $fixedModel;
            $this->models = [$fixedModel];
            $this->useRotation = false;
        } else {
            $this->defaultModel = (string) config('services.gemini.model');
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

    public function generate(string $systemPrompt, array $messages = [], array $options = []): string
    {
        if ($this->apiKey === '') {
            throw new AIServiceException('GEMINI_API_KEY is not configured.');
        }

        $contents = [];

        foreach ($messages as $turn) {
            $contents[] = [
                'role'  => $turn['role'] === 'assistant' ? 'model' : $turn['role'],
                'parts' => [['text' => $turn['text']]],
            ];
        }

        $generationConfig = $this->buildGenerationConfig($options);
        $attempted        = [];

        foreach ($this->models as $_unused) {
            $model = $this->nextModel();
            $body  = [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents'           => $contents,
            ];
            if ($generationConfig !== []) {
                $body['generationConfig'] = $generationConfig;
            }

            $response = Http::withQueryParameters(['key' => $this->apiKey])
                ->acceptJson()
                ->timeout(180)
                ->post($this->endpointForModel($model), $body);

            Log::debug('GeminiChatService response', [
                'status'   => $response->status(),
                'model'    => $model,
                'endpoint' => $this->endpointForModel($model),
            ]);

            if ($response->ok()) {
                return $this->extractText($response->json());
            }

            $attempted[] = $model . ':' . $response->status();

            if (!in_array($response->status(), [404, 429, 500, 503], true)) {
                throw new AIServiceException('Gemini request failed: ' . $response->status() . ' (model: ' . $model . ')');
            }
        }

        throw new AIServiceException(
            'Gemini request failed across models [' . implode(', ', $attempted) . '] '
            . '(404: model unavailable, 429: quota/rate limit).'
        );
    }

    /**
     * Translate neutral options into Gemini generationConfig.
     *
     * @param  array{json_schema?: array<string, mixed>, temperature?: float, max_tokens?: int}  $options
     * @return array<string, mixed>
     */
    private function buildGenerationConfig(array $options): array
    {
        $config = [];

        if (isset($options['json_schema']) && is_array($options['json_schema'])) {
            $config['responseMimeType'] = 'application/json';
            $config['responseSchema']   = $options['json_schema'];
        }

        if (isset($options['temperature'])) {
            $config['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $config['maxOutputTokens'] = (int) $options['max_tokens'];
        }

        return $config;
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
        $fallback = $this->models[0] ?? $this->defaultModel;

        if (!$this->useRotation) {
            return $fallback;
        }

        // Redis 掛掉時退化成不輪替（用第一個 model），不讓 cache 例外打斷生成。
        return rescue(function () use ($fallback) {
            $cacheKey = 'gemini_model_rotation_index';

            if (!Cache::has($cacheKey)) {
                Cache::forever($cacheKey, 0);
            }

            $next  = (int) Cache::increment($cacheKey);
            $index = ($next - 1) % \count($this->models);

            return $this->models[$index] ?? $fallback;
        }, $fallback);
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
