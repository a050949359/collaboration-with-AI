<?php

namespace App\Services\AI\Contracts;

use App\Enums\ArticleAspectRatio;

interface GeneratesArticleImage
{
    /**
     * @return array{image_path: string, image_url: string}
     */
    public function generate(string $prompt, string $directory = 'articles', string $aspectRatio = ArticleAspectRatio::R1x1->value): array;
}
