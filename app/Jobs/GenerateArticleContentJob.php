<?php

namespace App\Jobs;

use App\Enums\ArticleLanguage;
use App\Enums\ArticleStyle;
use App\Enums\ArticleTopic;
use App\Models\Article\Article;
use App\Services\AI\Contracts\GeneratesArticleContent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateArticleContentJob implements ShouldBeUnique, ShouldQueue
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
    ) {}

    public function uniqueId(): string
    {
        return 'article-content-'.$this->articleId;
    }

    public function handle(GeneratesArticleContent $contentService): void
    {
        $article = Article::find($this->articleId);

        if (! $article) {
            return;
        }

        $result = $contentService->generate(
            $this->buildPrompt($article),
            $this->language->instruction(),
            $this->style->instruction(),
        );

        $article->update([
            'title' => $result['title'],
            'content' => $result['content'],
            'summary' => $result['summary'],
            'tags' => $this->buildGenerationTags($article),
            'content_status' => 'completed',
            'content_error' => null,
            'content_generated_at' => now(),
        ]);

        if ($article->created_via === 'line') {
            DispatchLineArticleReadyWebhookJob::dispatch($article->id);
        }
    }

    public function failed(Throwable $exception): void
    {
        $article = Article::find($this->articleId);

        if (! $article) {
            return;
        }

        $article->update([
            'content_status' => 'failed',
            'content_error' => $exception->getMessage(),
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
