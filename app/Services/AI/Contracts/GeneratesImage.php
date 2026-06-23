<?php

namespace App\Services\AI\Contracts;

use App\Enums\ArticleAspectRatio;

/**
 * 共用(use-neutral)的圖片生成 contract,供角色立繪等用途依賴。
 *
 * 與 article 那條(GeneratesArticleImage / VertexImageGenerationService)刻意分開、
 * 互不繼承:文章封面有自己既有的 provider 與綁定,本介面是另一條獨立線。
 *
 * $directory 為呼叫端期望的相對目錄語意;走 ImageIngestService 的實作會以統一的
 * webp/uuid 分桶路徑為準(此參數不影響落地)。$aspectRatio 沿用 ArticleAspectRatio
 * 的合法值(該 enum 僅承載比例字串,與用途無關)。
 */
interface GeneratesImage
{
    /**
     * @return array{image_path: string, image_url: string}
     */
    public function generate(string $prompt, string $directory = 'images', string $aspectRatio = ArticleAspectRatio::R1x1->value): array;
}
