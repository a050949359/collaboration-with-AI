<?php

namespace App\Services\AI\Contracts;

interface MultimodalEmbedding
{
    /**
     * Embed binary content (image / PDF / audio …) into a vector.
     *
     * 與 TextEmbedding 共用同一個多模態模型（gemini-embedding-2）時，產出的向量
     * 與文字向量位於同一空間 → 可跨模態檢索（用文字查詢撈回相關圖片，反之亦然）。
     *
     * @param  string  $bytes  原始二進位內容
     * @param  string  $mimeType  例如 image/png、image/jpeg、application/pdf
     * @param  array{dimensions?: int}  $options
     * @return array<int, float>
     */
    public function embedData(string $bytes, string $mimeType, array $options = []): array;

    /**
     * Vector dimensionality this adapter produces（須與文字端一致才能同空間比對）。
     */
    public function dimensions(): int;
}
