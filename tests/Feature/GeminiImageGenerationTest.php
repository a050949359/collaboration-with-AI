<?php

namespace Tests\Feature;

use App\Services\AI\AIServiceException;
use App\Services\AI\Gemini\GeminiImageGenerationService;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeminiImageGenerationTest extends TestCase
{
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

    /** 產一張 GD 可解碼的小 PNG bytes（模擬 Gemini 回傳的圖片）。 */
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

    private function fakeGeminiImageResponse(string $key = 'inlineData'): void
    {
        $base64 = base64_encode($this->fakePngBytes());

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            $key => ['mimeType' => 'image/png', 'data' => $base64],
                        ]],
                    ],
                ]],
            ], 200),
        ]);
    }

    public function test_generates_image_and_stores_as_webp_on_public_disk(): void
    {
        $this->fakeGeminiImageResponse();

        $result = app(GeminiImageGenerationService::class)->generate('a serene mountain lake', 'articles/1', '16:9');

        $this->assertArrayHasKey('image_path', $result);
        $this->assertArrayHasKey('image_url', $result);
        $this->assertStringEndsWith('.webp', $result['image_path']);
        $this->assertStringStartsWith('images/', $result['image_path']);
        Storage::disk('public')->assertExists($result['image_path']);

        // 真的送到 generateContent endpoint、帶 key 與 IMAGE modality。
        Http::assertSent(function ($request) {
            return str_contains($request->url(), ':generateContent')
                && str_contains($request->url(), 'key=test-key')
                && in_array('IMAGE', $request['generationConfig']['responseModalities'] ?? [], true);
        });
    }

    public function test_accepts_snake_case_inline_data_key(): void
    {
        $this->fakeGeminiImageResponse('inline_data');

        $result = app(GeminiImageGenerationService::class)->generate('prompt', 'articles/1', '1:1');

        Storage::disk('public')->assertExists($result['image_path']);
    }

    public function test_invalid_aspect_ratio_falls_back_to_default(): void
    {
        $this->fakeGeminiImageResponse();

        app(GeminiImageGenerationService::class)->generate('prompt', 'articles/1', 'bogus');

        Http::assertSent(function ($request) {
            return ($request['generationConfig']['imageConfig']['aspectRatio'] ?? null) === '1:1';
        });
    }

    public function test_throws_when_api_key_missing(): void
    {
        config(['services.gemini.api_key' => '']);
        $this->expectException(AIServiceException::class);

        app(GeminiImageGenerationService::class)->generate('prompt');
    }

    public function test_throws_when_image_model_missing(): void
    {
        config(['services.gemini.image_model' => '']);
        $this->expectException(AIServiceException::class);

        app(GeminiImageGenerationService::class)->generate('prompt');
    }

    public function test_admin_settings_image_model_overrides_env(): void
    {
        $this->fakeGeminiImageResponse();
        // env 預設是 test-image-model；runtime 設定指定別的 → 應打到 runtime 的 model。
        Cache::forever(AppSettings::CACHE_KEY, ['image' => ['model' => 'runtime-model']]);

        app(GeminiImageGenerationService::class)->generate('prompt');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/models/runtime-model:generateContent'));
    }

    public function test_falls_back_to_env_model_when_admin_setting_blank(): void
    {
        $this->fakeGeminiImageResponse();
        // runtime 設定留空 → 退回 env 預設 test-image-model。
        Cache::forever(AppSettings::CACHE_KEY, ['image' => ['model' => '']]);

        app(GeminiImageGenerationService::class)->generate('prompt');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/models/test-image-model:generateContent'));
    }

    public function test_throws_when_response_has_no_image(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'no image here']]]]],
            ], 200),
        ]);

        $this->expectException(AIServiceException::class);
        app(GeminiImageGenerationService::class)->generate('prompt');
    }

    public function test_throws_on_http_error(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'bad'], 500),
        ]);

        $this->expectException(AIServiceException::class);
        app(GeminiImageGenerationService::class)->generate('prompt');
    }
}
