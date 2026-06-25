<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\AIServiceException;
use App\Services\AI\Contracts\TextToSpeech;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Http;

/**
 * 文轉語音：走 Gemini 的「interactions」API（2026 統一端點）。
 *
 * 實機驗證（2026-06）回傳結構：
 *   { status, steps:[ { content:[ { mime_type:"audio/l16", data:"<base64 PCM 24k mono 16-bit>" } ] } ] }
 * （官方文件寫的 output_audio.data 與實際不符，以實機為準。）
 *
 * model / voice：admin_settings('tts') 優先 → 退回 config('services.gemini.tts_*')。
 */
class GeminiTextToSpeechService implements TextToSpeech
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    private string $apiKey;

    private string $defaultModel;

    private string $defaultVoice;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.api_key', '');

        $settings = AppSettings::get('tts', []);
        $settings = is_array($settings) ? $settings : [];

        $this->defaultModel = ((string) ($settings['model'] ?? '')) ?: (string) config('services.gemini.tts_model');
        $this->defaultVoice = ((string) ($settings['voice'] ?? '')) ?: (string) config('services.gemini.tts_voice', 'Kore');
    }

    public function synthesize(string $text, array $options = []): array
    {
        if ($this->apiKey === '') {
            throw new AIServiceException('GEMINI_API_KEY is not configured.');
        }

        $model = ((string) ($options['model'] ?? '')) ?: $this->defaultModel;
        $voice = ((string) ($options['voice'] ?? '')) ?: $this->defaultVoice;

        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->acceptJson()
            ->when(config('services.gemini.proxy'), fn ($req, $proxy) => $req->withOptions(['proxy' => $proxy]))
            ->timeout(120)
            ->post(self::ENDPOINT, [
                'model' => $model,
                'input' => $text,
                'response_format' => ['type' => 'audio'],
                'generation_config' => ['speech_config' => [['voice' => $voice]]],
            ]);

        if (! $response->ok()) {
            throw new AIServiceException('Gemini TTS failed: '.$response->status().' (model: '.$model.')');
        }

        return $this->extractAudio($response->json(), $model);
    }

    /**
     * @return array{audio: string, mimeType: string, sampleRate: int}
     */
    private function extractAudio(mixed $payload, string $model): array
    {
        $steps = is_array($payload) ? ($payload['steps'] ?? []) : [];

        foreach (is_array($steps) ? $steps : [] as $step) {
            foreach (($step['content'] ?? []) as $part) {
                $mime = is_array($part) ? ($part['mime_type'] ?? '') : '';

                if (is_string($mime) && str_starts_with($mime, 'audio/') && isset($part['data']) && is_string($part['data'])) {
                    return [
                        'audio' => (string) base64_decode($part['data'], true),
                        // l16 不帶取樣率；Gemini TTS 固定 24kHz mono 16-bit。
                        'mimeType' => $mime,
                        'sampleRate' => 24000,
                    ];
                }
            }
        }

        throw new AIServiceException('Gemini TTS returned no audio (model: '.$model.').');
    }
}
