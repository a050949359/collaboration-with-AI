<?php

namespace App\Services\AI\Contracts;

interface SpeechToText
{
    /**
     * 語音轉文：把語音音訊轉錄為文字。
     *
     * @param  string  $audio  原始音訊位元組
     * @param  string  $mimeType  例：audio/wav、audio/mp3、audio/ogg
     * @param  array{model?: string, prompt?: string}  $options
     */
    public function transcribe(string $audio, string $mimeType, array $options = []): string;
}
