<?php

namespace Tests\Feature;

use App\Jobs\GenerateArticleContentJob;
use App\Jobs\GenerateArticleImageJob;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticleGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_create_article_draft(): void
    {
        $response = $this->postJson('/api/articles', [
            'title' => 'Demo',
            'prompt' => 'Write a short article',
        ]);

        $response->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_article_draft(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/articles', [
                'title' => 'Draft Title',
                'prompt' => 'Prompt Text',
            ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.title', 'Draft Title')
            ->assertJsonPath('data.prompt', 'Prompt Text')
            ->assertJsonPath('data.content_status', 'pending')
            ->assertJsonPath('data.image_status', 'pending');

        $this->assertDatabaseHas('articles', [
            'user_id' => $user->id,
            'title' => 'Draft Title',
            'prompt' => 'Prompt Text',
        ]);
    }

    public function test_content_generation_is_rate_limited_per_user(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $article = Article::create([
            'user_id' => $user->id,
            'title' => 'Draft Title',
            'prompt' => 'Topic prompt',
        ]);

        $first = $this->actingAs($user, 'sanctum')
            ->postJson("/api/articles/{$article->id}/generate-content", [
                'topic'    => 'travel',
                'language' => 'zh-TW',
                'style'    => 'practical',
            ]);

        $first->assertStatus(202)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.content_status', 'processing');

        Queue::assertPushed(GenerateArticleContentJob::class);

        $second = $this->actingAs($user, 'sanctum')
            ->postJson("/api/articles/{$article->id}/generate-content", [
                'topic'    => 'travel',
                'language' => 'zh-TW',
                'style'    => 'practical',
            ]);

        $second->assertStatus(429)
            ->assertJsonPath('status', 'error');
    }

    public function test_image_generation_updates_article_image_fields(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $article = Article::create([
            'user_id' => $user->id,
            'title' => 'A Good Topic',
            'prompt' => 'Topic prompt',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/articles/{$article->id}/generate-image", [
                'aspect_ratio' => '1:1',
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.image_status', 'processing');

        Queue::assertPushed(GenerateArticleImageJob::class);

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'image_status' => 'processing',
        ]);
    }
}
