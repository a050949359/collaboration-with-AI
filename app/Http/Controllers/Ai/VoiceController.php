<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\AI\AIServiceException;
use App\Services\AI\Contracts\MintsLiveToken;
use App\Services\AI\Contracts\SpeechToText;
use App\Services\AI\Contracts\TextToSpeech;
use App\Services\AI\LlmManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * 語音 AI 端點：文轉語音 / 語音轉文 / 即時翻譯 ephemeral token。
 *
 * 三者皆 auth:sanctum + throttle（路由層）。GEMINI_API_KEY 永遠不出後端。
 */
class VoiceController extends Controller
{
    /**
     * 文轉語音。可選 target：先把 text 翻成該語言再念。
     * 回傳 WAV（把 Gemini 的 l16 PCM 包上 WAV header）。
     */
    public function tts(Request $request, TextToSpeech $tts, LlmManager $llm): Response
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:5000'],
            'target' => ['sometimes', 'nullable', 'string', Rule::in($this->languages())],
            'voice' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $text = $validated['text'];

        $options = [];
        if (! empty($validated['voice'])) {
            $options['voice'] = $validated['voice'];
        }

        try {
            // 翻譯也納入 try：LLM 失敗（限流/斷線）統一回 502，不噴 500。
            if (! empty($validated['target'])) {
                $text = $llm->driver('gemini', (string) config('services.gemini.model'))->generate(
                    "Translate the user's text into {$validated['target']}. Output ONLY the translation, no quotes, no explanation.",
                    [['role' => 'user', 'text' => $text]],
                );
            }

            $result = $tts->synthesize($text, $options);
        } catch (AIServiceException $e) {
            return response($e->getMessage(), 502);
        }

        return response($this->pcmToWav($result['audio'], $result['sampleRate']), 200, [
            'Content-Type' => 'audio/wav',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * 語音轉文：上傳音檔，回傳轉錄文字。
     */
    public function stt(Request $request, SpeechToText $stt): JsonResponse
    {
        $request->validate([
            'audio' => ['required', 'file', 'mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/aac,audio/ogg,audio/flac,audio/aiff', 'max:20480'],
            'prompt' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $file = $request->file('audio');
        $options = [];
        if ($request->filled('prompt')) {
            $options['prompt'] = (string) $request->input('prompt');
        }

        $audio = file_get_contents($file->getRealPath());
        if ($audio === false) {
            return response()->json(['message' => '音檔讀取失敗'], 400);
        }

        try {
            $text = $stt->transcribe($audio, (string) $file->getMimeType(), $options);
        } catch (AIServiceException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['text' => $text]);
    }

    /**
     * 鑄造 Live API ephemeral token（即時語音翻譯用）。目標語言鎖在 server 端。
     */
    public function liveToken(Request $request, MintsLiveToken $minter): JsonResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'string', Rule::in($this->languages())],
        ]);

        try {
            $result = $minter->mint($validated['target']);
        } catch (AIServiceException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($result);
    }

    /**
     * @return array<int, string>
     */
    private function languages(): array
    {
        $list = config('services.gemini.translate_languages', []);

        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    /**
     * 把 16-bit mono PCM 包成 WAV（44-byte header）。
     */
    private function pcmToWav(string $pcm, int $sampleRate): string
    {
        $dataLen = strlen($pcm);
        $byteRate = $sampleRate * 2; // mono, 16-bit

        return 'RIFF'
            .pack('V', 36 + $dataLen)
            .'WAVE'
            .'fmt '
            .pack('V', 16)            // fmt chunk size
            .pack('v', 1)             // PCM
            .pack('v', 1)             // mono
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', 2)             // block align
            .pack('v', 16)            // bits per sample
            .'data'
            .pack('V', $dataLen)
            .$pcm;
    }
}
