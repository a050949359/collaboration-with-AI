<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\AIServiceException;
use App\Services\AI\Contracts\TextEmbedding;
use Illuminate\Support\Facades\Http;

class GeminiEmbeddingService implements TextEmbedding
{
    private string $apiKey;
    private string $model;
    private int $dimensions;

    public function __construct(?string $fixedModel = null)
    {
        $this->apiKey     = (string) config('services.gemini.api_key', '');
        $this->model      = $fixedModel ?? (string) config('services.gemini.embedding_model', 'text-embedding-004');
        $this->dimensions = (int) config('services.gemini.embedding_dimensions', 768);
    }

    public function embed(string $text, array $options = []): array
    {
        if ($this->apiKey === '') {
            throw new AIServiceException('GEMINI_API_KEY is not configured.');
        }

        $body = [
            'model'                => 'models/' . $this->model,
            'content'              => ['parts' => [['text' => $text]]],
            'outputDimensionality' => (int) ($options['dimensions'] ?? $this->dimensions),
        ];
        if (isset($options['task_type'])) {
            $body['taskType'] = $options['task_type'];
        }

        $response = Http::withQueryParameters(['key' => $this->apiKey])
            ->acceptJson()
            ->timeout(60)
            ->post($this->endpoint(), $body);

        if (!$response->ok()) {
            throw new AIServiceException('Gemini embedding request failed: ' . $response->status());
        }

        $values = $response->json('embedding.values');

        if (!is_array($values)) {
            throw new AIServiceException('Gemini embedding response missing values.');
        }

        return array_map('floatval', $values);
    }

    /**
     * gemini-embedding-001 僅支援單筆 embedContent，無同步 batch；此處逐筆呼叫。
     * 小語料足夠；大量 ingest 時需注意 RPM 限制（之後可加節流 / async batch）。
     */
    public function embedBatch(array $texts, array $options = []): array
    {
        return array_map(fn (string $t) => $this->embed($t, $options), $texts);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    private function endpoint(): string
    {
        return sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent',
            $this->model,
        );
    }
}
