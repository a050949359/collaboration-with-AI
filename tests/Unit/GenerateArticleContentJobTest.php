<?php

namespace Tests\Unit;

use App\Enums\ArticleLanguage;
use App\Enums\ArticleStyle;
use App\Enums\ArticleTopic;
use App\Jobs\GenerateArticleContentJob;
use App\Models\Article;
use App\Models\User;
use App\Services\AI\Contracts\GeneratesArticleContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateArticleContentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_writes_generation_options_into_tags(): void
    {
        $user = User::factory()->create();

        $article = Article::create([
            'user_id' => $user->id,
            'title' => 'Draft',
            'prompt' => 'Topic prompt',
            'tags' => ['custom-tag'],
            'content_status' => 'processing',
        ]);

        $job = new GenerateArticleContentJob(
            $article->id,
            ArticleTopic::Travel,
            ArticleLanguage::TraditionalChinese,
            ArticleStyle::Practical,
            '使用者附加需求',
        );

        $service = new class implements GeneratesArticleContent {
            public function generate(string $prompt, ?string $language = null, ?string $style = null): array
            {
                return [
                    'title' => '測試標題',
                    'content' => '測試內容',
                    'summary' => '測試摘要',
                ];
            }
        };

        $job->handle($service);

        $article->refresh();

        $this->assertSame('completed', $article->content_status);
        $this->assertIsArray($article->tags);
        $this->assertContains('custom-tag', $article->tags);
        $this->assertContains('topic:travel', $article->tags);
        $this->assertContains('topic_label:旅遊', $article->tags);
        $this->assertContains('language:zh-TW', $article->tags);
        $this->assertContains('language_label:繁體中文', $article->tags);
        $this->assertContains('style:practical', $article->tags);
        $this->assertContains('style_label:實用指南', $article->tags);
    }
}
