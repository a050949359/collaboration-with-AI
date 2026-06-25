<?php

namespace App\Services\AI\Contracts;

interface TextToSpeech
{
    /**
     * 文轉語音：把文字合成為語音音訊。
     *
     * Provider-neutral：各 adapter 自行把 voice/model 翻成自家請求格式。
     *
     * @param  array{model?: string, voice?: string}  $options
     * @return array{audio: string, mimeType: string, sampleRate: int} audio 為原始位元組（已 base64 解碼）
     */
    public function synthesize(string $text, array $options = []): array;
}
