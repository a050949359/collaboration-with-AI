<?php

namespace Tests\Feature;

use App\Models\Story\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CharacterImageGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.image_model' => 'test-image-model',
            'services.gemini.proxy' => null,
        ]);
    }

    /** 生圖端點須登入(auth:sanctum);需授權的案先呼叫此。 */
    private function login(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
    }

    private function fakePngBytes(): string
    {
        $img = imagecreatetruecolor(8, 8);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function fakeGeminiImage(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'predictions' => [
                    [
                        'bytesBase64Encoded' => base64_encode($this->fakePngBytes()),
                        'mimeType' => 'image/png',
                    ],
                ],
            ], 200),
        ]);
    }

    private function makeCharacter(?string $imagePrompt = 'a brave knight, digital art'): Character
    {
        return Character::create([
            'name' => 'Test Hero',
            'persona' => 'brave',
            'image_prompt' => $imagePrompt,
        ]);
    }

    public function test_requires_authentication(): void
    {
        $character = $this->makeCharacter();

        $this->postJson("/api/v1/characters/{$character->id}/image")
            ->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_generates_image_from_stored_prompt_and_persists_paths(): void
    {
        $this->login();
        $this->fakeGeminiImage();
        $character = $this->makeCharacter();

        $response = $this->postJson("/api/v1/characters/{$character->id}/image");

        $response->assertOk()->assertJsonStructure(['image_path', 'image_url']);

        $character->refresh();
        $this->assertNotNull($character->image_path);
        $this->assertNotNull($character->image_url);
        $this->assertStringEndsWith('.webp', $character->image_path);
        Storage::disk('public')->assertExists($character->image_path);
    }

    public function test_body_prompt_overrides_stored_and_is_saved(): void
    {
        $this->login();
        $this->fakeGeminiImage();
        $character = $this->makeCharacter('old prompt');

        $this->postJson("/api/v1/characters/{$character->id}/image", [
            'image_prompt' => 'new override prompt',
        ])->assertOk();

        $this->assertSame('new override prompt', $character->fresh()->image_prompt);
        Http::assertSent(fn ($request) => str_contains((string) ($request['instances'][0]['prompt'] ?? ''), 'new override prompt'));
    }

    public function test_null_image_prompt_falls_back_to_stored(): void
    {
        $this->login();
        $this->fakeGeminiImage();
        $character = $this->makeCharacter('stored hero prompt');

        // {"image_prompt": null} 不可繞過退回已存 prompt 的邏輯。
        $this->postJson("/api/v1/characters/{$character->id}/image", [
            'image_prompt' => null,
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains((string) ($request['instances'][0]['prompt'] ?? ''), 'stored hero prompt'));
    }

    public function test_null_aspect_ratio_uses_portrait_default(): void
    {
        $this->login();
        $this->fakeGeminiImage();
        $character = $this->makeCharacter();

        $this->postJson("/api/v1/characters/{$character->id}/image", [
            'aspect_ratio' => null,
        ])->assertOk();

        // null 應落預設 3:4,而非 Gemini service 的 1:1 fallback。
        Http::assertSent(fn ($request) => ($request['parameters']['aspectRatio'] ?? null) === '3:4');
    }

    public function test_rejects_when_no_prompt_available(): void
    {
        $this->login();
        $character = $this->makeCharacter(null);

        $this->postJson("/api/v1/characters/{$character->id}/image")
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_invalid_aspect_ratio_is_rejected(): void
    {
        $this->login();
        $character = $this->makeCharacter();

        $this->postJson("/api/v1/characters/{$character->id}/image", [
            'aspect_ratio' => 'bogus',
        ])->assertStatus(422);
    }

    public function test_upstream_ai_failure_returns_502(): void
    {
        $this->login();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'bad'], 500),
        ]);
        $character = $this->makeCharacter();

        $this->postJson("/api/v1/characters/{$character->id}/image")
            ->assertStatus(502);

        $this->assertNull($character->fresh()->image_path);
    }

    public function test_connection_failure_is_caught_as_502(): void
    {
        $this->login();
        // 非 AIServiceException(連線失敗)也須被 \Throwable 收斂成 502,而非 500。
        Http::fake([
            'generativelanguage.googleapis.com/*' => fn () => throw new ConnectionException('boom'),
        ]);
        $character = $this->makeCharacter();

        $this->postJson("/api/v1/characters/{$character->id}/image")
            ->assertStatus(502);

        $this->assertNull($character->fresh()->image_path);
    }
}
