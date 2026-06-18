<?php

namespace Tests\Feature;

use App\Models\ShareToken;
use App\Models\User;
use App\Services\About\ResumeChatService;
use App\Services\AI\AIServiceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutShareTokenTest extends TestCase
{
    use RefreshDatabase;

    /** 建一把有效的 about 分享連結，回傳明文 raw token */
    private function makeToken(array $overrides = []): string
    {
        $raw = 'rawtoken-'.bin2hex(random_bytes(8));

        ShareToken::create(array_merge([
            'token' => hash('sha256', $raw),
            'scope' => 'about',
            'max_uses' => 5,
            'uses_count' => 0,
        ], $overrides));

        return $raw;
    }

    /** chat 成功回固定字串 */
    private function fakeChatReturns(string $reply = 'mocked reply'): void
    {
        $this->mock(ResumeChatService::class, function ($mock) use ($reply) {
            $mock->shouldReceive('chat')->andReturn($reply);
        });
    }

    public function test_guest_without_token_gets_401(): void
    {
        $this->postJson('/api/about/ask', ['message' => 'hi'])
            ->assertStatus(401);
    }

    public function test_guest_with_invalid_token_gets_403(): void
    {
        $this->withToken('does-not-exist')
            ->postJson('/api/about/ask', ['message' => 'hi'])
            ->assertStatus(403);
    }

    public function test_guest_with_valid_token_can_ask_and_increments_once(): void
    {
        $this->fakeChatReturns('hello');
        $raw = $this->makeToken();

        $this->withToken($raw)
            ->postJson('/api/about/ask', ['message' => 'hi'])
            ->assertOk()
            ->assertJsonPath('reply', 'hello');

        $this->assertSame(1, ShareToken::firstWhere('token', hash('sha256', $raw))->uses_count);
    }

    public function test_failed_ai_does_not_consume_a_use(): void
    {
        $this->mock(ResumeChatService::class, function ($mock) {
            $mock->shouldReceive('chat')->andThrow(new AIServiceException('AI down'));
        });
        $raw = $this->makeToken();

        $this->withToken($raw)
            ->postJson('/api/about/ask', ['message' => 'hi'])
            ->assertStatus(503);

        // 失敗不扣次數
        $this->assertSame(0, ShareToken::firstWhere('token', hash('sha256', $raw))->uses_count);
    }

    public function test_exhausted_token_gets_403(): void
    {
        $raw = $this->makeToken(['max_uses' => 1, 'uses_count' => 1]);

        $this->withToken($raw)
            ->postJson('/api/about/ask', ['message' => 'hi'])
            ->assertStatus(403);
    }

    public function test_logged_in_user_needs_no_token(): void
    {
        $this->fakeChatReturns();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/about/ask', ['message' => 'hi'])
            ->assertOk();
    }

    public function test_check_endpoint_does_not_consume_a_use(): void
    {
        $raw = $this->makeToken();

        $this->postJson('/api/share-tokens/check', ['token' => $raw, 'scope' => 'about'])
            ->assertOk()
            ->assertJsonPath('valid', true);

        // 開鎖（驗證連結）不扣次數
        $this->assertSame(0, ShareToken::firstWhere('token', hash('sha256', $raw))->uses_count);
    }
}
