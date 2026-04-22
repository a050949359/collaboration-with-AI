<?php

namespace App\Services\AI\Contracts;

interface GeneratesArticleContent
{
    /**
     * @return array{title: string, content: string, summary: string}
     */
    public function generate(string $prompt, ?string $language = null, ?string $style = null): array;
}
