<?php

namespace App\Services\AI\Contracts;

interface TextEmbedding
{
    /**
     * Embed a single text into a vector.
     *
     * Provider-neutral: each adapter maps $options into its own request shape
     * (Gemini taskType / outputDimensionality, OpenAI dimensions, …).
     *
     * @param  array{task_type?: string, dimensions?: int}  $options
     * @return array<int, float>
     */
    public function embed(string $text, array $options = []): array;

    /**
     * Embed multiple texts in one call (batch). Order of results matches input.
     *
     * @param  array<int, string>  $texts
     * @param  array{task_type?: string, dimensions?: int}  $options
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts, array $options = []): array;

    /**
     * Vector dimensionality this adapter produces (for storage schema / sanity checks).
     */
    public function dimensions(): int;
}
