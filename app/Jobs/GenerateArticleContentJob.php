<?php

namespace App\Jobs;

use App\Enums\ArticleLanguage;
use App\Enums\ArticleStyle;
use App\Enums\ArticleTopic;
use App\Models\Article;
use App\Services\AI\Contracts\GeneratesArticleContent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateArticleContentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 300;

    public function __construct(
        public int $articleId,
        public ArticleTopic $topic,
        public ArticleLanguage $language,
        public ArticleStyle $style,
        public ?string $extraPrompt = null,
    ) {
    }

    public function uniqueId(): string
    {
        return 'article-content-'.$this->articleId;
    }

    public function handle(GeneratesArticleContent $contentService): void
    {
        $article = Article::find($this->articleId);

        if (!$article) {
            return;
        }

        $result = $contentService->generate(
            $this->buildPrompt($article),
            $this->language->instruction(),
            $this->style->instruction(),
        );


        $article->update([
            'title'                => $result['title'],
            'content'              => $result['content'],
            'summary'              => $result['summary'],
            'tags'                 => $this->buildGenerationTags($article),
            'content_status'       => 'completed',
            'content_error'        => null,
            'content_generated_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $article = Article::find($this->articleId);

        if (!$article) {
            return;
        }

        $article->update([
            'content_status' => 'failed',
            'content_error'  => $exception->getMessage(),
        ]);
    }

    private function buildPrompt(Article $article): string
    {
        $parts = [];
        $parts[] = 'Topic category: '.$this->topic->label();

        if ($this->extraPrompt !== null && $this->extraPrompt !== '') {
            $parts[] = 'Additional context: '.$this->extraPrompt;
        } elseif ($article->prompt !== null && $article->prompt !== '') {
            $parts[] = 'Additional context: '.$article->prompt;
        }

        if ($article->title !== null && $article->title !== '') {
            $parts[] = 'Suggested title: '.$article->title;
        }

        return implode("\n", $parts);
    }

    private function sanitizeUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);

        if ($converted === false) {
            return '';
        }

        return $converted;
    }

    private function sanitizeForStorage(string $text): string
    {
        $cleaned = $this->sanitizeUtf8($text);

        if ($cleaned === '') {
            return '';
        }

        // Keep \n/\r/\t, remove other ASCII and C1 control characters.
        $cleaned = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}]/u', '', $cleaned);
        // Remove Unicode format/private-use/surrogate characters that often appear as odd glyphs.
        $cleaned = (string) preg_replace('/[\p{Cf}\p{Co}\p{Cs}]/u', '', $cleaned);
        // Remove Unicode replacement character (U+FFFD), often shown as "�".
        $cleaned = str_replace("\u{FFFD}", '', $cleaned);

        return trim($cleaned);
    }

    /**
     * @return array<int, string>
     */
    private function buildGenerationTags(Article $article): array
    {
        $existingTags = [];

        if (is_array($article->tags)) {
            foreach ($article->tags as $tag) {
                if (is_string($tag)) {
                    $trimmed = trim($tag);

                    if ($trimmed !== '') {
                        $existingTags[] = $trimmed;
                    }
                }
            }
        }

        $optionTags = [
            'topic:'.$this->topic->value,
            'topic_label:'.$this->topic->label(),
            'language:'.$this->language->value,
            'language_label:'.$this->language->label(),
            'style:'.$this->style->value,
            'style_label:'.$this->style->label(),
        ];

        return array_values(array_unique([...$existingTags, ...$optionTags]));
    }

    
}
