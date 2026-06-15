<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\AIServiceException;
use App\Services\AI\Contracts\MultimodalEmbedding;
use Illuminate\Support\Facades\Http;

class GeminiMultimodalEmbeddingService implements MultimodalEmbedding
{
    private string $apiKey;

    private string $model;

    private int $dimensions;

    public function __construct(?string $fixedModel = null)
    {
        $this->apiKey = (string) config('services.gemini.api_key', '');
        // 與文字端共用同一 model（services.gemini.embedding_model）→ 同向量空間。
        // 多模態需多模態模型；若該 config 為純文字模型（如預設的 gemini-embedding-001），
        // 對圖片呼叫會由 API 回錯 —— 啟用多模態前需先把 embedding_model 切到 gemini-embedding-2。
        $this->model = $fixedModel ?? (string) config('services.gemini.embedding_model', 'gemini-embedding-001');
        $this->dimensions = (int) config('services.gemini.embedding_dimensions', 768);
    }

    public function embedData(string $bytes, string $mimeType, array $options = []): array
    {
        if ($this->apiKey === '') {
            throw new AIServiceException('GEMINI_API_KEY is not configured.');
        }

        $body = [
            'model' => 'models/'.$this->model,
            'content' => ['parts' => [[
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data' => base64_encode($bytes),
                ],
            ]]],
            'outputDimensionality' => (int) ($options['dimensions'] ?? $this->dimensions),
        ];

        $response = Http::withQueryParameters(['key' => $this->apiKey])
            ->acceptJson()
            ->timeout(120)
            ->post($this->endpoint(), $body);

        if (! $response->ok()) {
            throw new AIServiceException('Gemini multimodal embedding request failed: '.$response->status().' - '.$response->body());
        }

        $values = $response->json('embedding.values');

        if (! is_array($values)) {
            throw new AIServiceException('Gemini multimodal embedding response missing values.');
        }

        return array_map('floatval', $values);
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
