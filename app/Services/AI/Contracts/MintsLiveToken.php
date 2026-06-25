<?php

namespace App\Services\AI\Contracts;

interface MintsLiveToken
{
    /**
     * 鑄造一個短期、受限的 Live API ephemeral token，供前端直連即時語音串流用。
     *
     * 目標語言在 server 端寫進 token 的 setup 約束（前端無法竄改）。
     *
     * @param  array{model?: string}  $options
     * @return array{token: string, expiresAt: string} token 為憑證字串，expiresAt 為 ISO 8601 時間
     */
    public function mint(string $targetLanguage, array $options = []): array;
}
