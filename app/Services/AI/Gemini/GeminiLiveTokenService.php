<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\AIServiceException;
use App\Services\AI\Contracts\MintsLiveToken;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Http;

/**
 * 鑄造 Gemini Live API 的 ephemeral token（authTokens.create）。
 *
 * REST shape 已實機驗證（2026-06，tmp/live-token-smoke.html 的 Raw REST 鑄票鈕）：
 *   POST /v1alpha/auth_tokens（header x-goog-api-key）
 *   body：uses / expireTime / newSessionExpireTime / bidiGenerateContentSetup{model, systemInstruction, generationConfig}
 *   回傳：{ name } 即 token。
 *
 * 目標語言鎖在 systemInstruction，前端無法竄改（拿到 token 也只能用這個語言）。
 *
 * model：admin_settings('live') 優先 → 退回 config('services.gemini.live_model')。
 */
class GeminiLiveTokenService implements MintsLiveToken
{
    // ⚠️ 待驗證的端點。
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1alpha/auth_tokens';

    private string $apiKey;

    private string $defaultModel;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.api_key', '');

        $settings = AppSettings::get('live', []);
        $settings = is_array($settings) ? $settings : [];

        $this->defaultModel = ((string) ($settings['model'] ?? '')) ?: (string) config('services.gemini.live_model');
    }

    public function mint(string $targetLanguage, array $options = []): array
    {
        if ($this->apiKey === '') {
            throw new AIServiceException('GEMINI_API_KEY is not configured.');
        }

        $model = ((string) ($options['model'] ?? '')) ?: $this->defaultModel;

        // token 與單一 session 的有效期（保守：30 分鐘可新開 session，1 分鐘後不可再鑄新 session）。
        // 抓一次 now（CarbonImmutable，addMinutes 回新實例，$now 不變），避免兩次呼叫時間不一致。
        $now = now();
        $expireTime = $now->addMinutes(30)->toIso8601String();
        $newSessionExpireTime = $now->addMinute()->toIso8601String();

        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->acceptJson()
            ->when(config('services.gemini.proxy'), fn ($req, $proxy) => $req->withOptions(['proxy' => $proxy]))
            ->timeout(20)
            ->post(self::ENDPOINT, [
                'uses' => 1,
                'expireTime' => $expireTime,
                'newSessionExpireTime' => $newSessionExpireTime,
                'bidiGenerateContentSetup' => [
                    'model' => 'models/'.$model,
                    'systemInstruction' => [
                        'parts' => [['text' => "Translate the spoken input into {$targetLanguage}. Only output the translation."]],
                    ],
                    'generationConfig' => ['responseModalities' => ['AUDIO']],
                ],
            ]);

        if (! $response->ok()) {
            throw new AIServiceException('Gemini Live token mint failed: '.$response->status());
        }

        $payload = $response->json();
        $name = is_array($payload) ? ($payload['name'] ?? null) : null;

        if (! is_string($name) || $name === '') {
            throw new AIServiceException('Gemini Live token response missing "name".');
        }

        return ['token' => $name, 'expiresAt' => $expireTime];
    }
}
