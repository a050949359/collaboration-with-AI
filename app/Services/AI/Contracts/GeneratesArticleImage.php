<?php

namespace App\Services\AI\Contracts;

interface GeneratesArticleImage
{
    /**
     * @return array{image_path: string, image_url: string}
     */
    public function generate(string $prompt, string $directory = 'articles', string $aspectRatio = '1:1'): array;
}
