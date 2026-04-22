<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_list_only_returns_completed_articles(): void
    {
        Article::factory()->create(['content_status' => 'completed']);
        Article::factory()->create(['content_status' => 'pending']);

        $response = $this->getJson('/api/v1/articles');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'data');
    }

    public function test_authenticated_user_can_filter_mine_in_paginated_list(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Article::factory()->create([
            'user_id' => $owner->id,
            'content_status' => 'pending',
        ]);
        Article::factory()->create([
            'user_id' => $other->id,
            'content_status' => 'completed',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/articles?scope=mine');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $owner->id);
    }

    public function test_public_show_hides_non_completed_articles(): void
    {
        $article = Article::factory()->create([
            'content_status' => 'pending',
        ]);

        $response = $this->getJson("/api/v1/articles/{$article->id}");

        $response->assertNotFound();
    }
}
