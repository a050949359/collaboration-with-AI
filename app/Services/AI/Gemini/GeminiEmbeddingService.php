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
        $this->apiKey = (string) config('services.gemini.api_key', '');
        $this->model = $fixedModel ?? (string) config('services.gemini.embedding_model', 'text-embedding-004');
        $this->dimensions = (int) config('services.gemini.embedding_dimensions', 768);
    }

    /** 單次 batchEmbedContents 的最大筆數（避免 payload 過大 / 觸發上限）。 */
    private const MAX_BATCH = 100;

    public function embed(string $text, array $options = []): array
    {
        return $this->embedBatch([$text], $options)[0] ?? [];
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        if ($this->apiKey === '') {
            throw new AIServiceException('GEMINI_API_KEY is not configured.');
        }

        if ($texts === []) {
            return [];
        }

        $out = [];
        // 分批呼叫 batchEmbedContents（一次最多 MAX_BATCH 筆）
        foreach (array_chunk($texts, self::MAX_BATCH) as $batch) {
            foreach ($this->embedChunk($batch, $options) as $vec) {
                $out[] = $vec;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $texts
     * @param  array{task_type?: string, dimensions?: int}  $options
     * @return array<int, array<int, float>>
     */
    private function embedChunk(array $texts, array $options): array
    {
        $modelPath = 'models/'.$this->model;
        $dims = (int) ($options['dimensions'] ?? $this->dimensions);

        $requests = [];
        foreach ($texts as $text) {
            $req = [
                'model' => $modelPath,
                'content' => ['parts' => [['text' => $text]]],
                'outputDimensionality' => $dims,
            ];
            if (isset($options['task_type'])) {
                $req['taskType'] = $options['task_type'];
            }
            $requests[] = $req;
        }

        $response = Http::withQueryParameters(['key' => $this->apiKey])
            ->acceptJson()
            ->timeout(120)
            ->post($this->endpoint(), ['requests' => $requests]);

        if (! $response->ok()) {
            throw new AIServiceException('Gemini embedding request failed: '.$response->status().' - '.$response->body());
        }

        $embeddings = $response->json('embeddings');

        if (! is_array($embeddings)) {
            throw new AIServiceException('Gemini embedding response missing embeddings.');
        }

        return array_map(
            static fn ($e) => array_map('floatval', $e['values'] ?? []),
            $embeddings,
        );
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    private function endpoint(): string
    {
        return sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:batchEmbedContents',
            $this->model,
        );
    }
}
