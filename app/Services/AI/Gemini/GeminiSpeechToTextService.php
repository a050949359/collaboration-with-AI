<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\AIServiceException;
use App\Services\AI\Contracts\SpeechToText;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Http;

/**
 * 語音轉文：走 Gemini 的「interactions」API，input 帶 text 指令 + inline audio。
 *
 * 端點與 TTS 同（已驗證）；STT 的輸出文字位置「以實機回傳為準」——
 * 目前依 interactions 慣例從 steps[].content[] 取 text，並相容舊 candidates 結構。
 * 第一次跑真實音檔時請核對 log，必要時調整 extractText()。
 *
 * 上限：inline 請求總大小 20MB（含 prompt + 音檔）。
 *
 * model：admin_settings('stt') 優先 → 退回 config('services.gemini.stt_model')。
 */
class GeminiSpeechToTextService implements SpeechToText
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    private string $apiKey;

    private string $defaultModel;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.api_key', '');

        $settings = AppSettings::get('stt', []);
        $settings = is_array($settings) ? $settings : [];

        $this->defaultModel = ((string) ($settings['model'] ?? '')) ?: (string) config('services.gemini.stt_model');
    }

    public function transcribe(string $audio, string $mimeType, array $options = []): string
    {
        if ($this->apiKey === '') {
            throw new AIServiceException('GEMINI_API_KEY is not configured.');
        }

        $model = ((string) ($options['model'] ?? '')) ?: $this->defaultModel;
        $prompt = ((string) ($options['prompt'] ?? '')) ?: 'Generate a transcript of the speech.';

        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->acceptJson()
            ->when(config('services.gemini.proxy'), fn ($req, $proxy) => $req->withOptions(['proxy' => $proxy]))
            ->timeout(180)
            ->post(self::ENDPOINT, [
                'model' => $model,
                'input' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'audio', 'data' => base64_encode($audio), 'mime_type' => $mimeType],
                ],
            ]);

        if (! $response->ok()) {
            throw new AIServiceException('Gemini STT failed: '.$response->status().' (model: '.$model.')');
        }

        return $this->extractText($response->json());
    }

    private function extractText(mixed $payload): string
    {
        if (! is_array($payload)) {
            return '';
        }

        $texts = [];

        // interactions：steps[].content[] 內的 text part。
        foreach (($payload['steps'] ?? []) as $step) {
            if (! is_array($step)) {
                continue;
            }

            foreach (($step['content'] ?? []) as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $texts[] = trim($part['text']);
                }
            }
        }

        // 相容舊 generateContent 結構（candidates[].content.parts[].text）。
        if ($texts === []) {
            foreach (($payload['candidates'][0]['content']['parts'] ?? []) as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $texts[] = trim($part['text']);
                }
            }
        }

        return trim(implode("\n", array_filter($texts)));
    }
}
