<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class LineBotHmac
{
    public function verifyInbound(Request $request): bool
    {
        $timestamp = trim((string) $request->header('X-Timestamp', ''));
        $nonce = trim((string) $request->header('X-Nonce', ''));
        $signature = strtolower(trim((string) $request->header('X-Signature', '')));

        $required = (bool) config('services.line_bot.hmac_required', false);
        $maxSkewSeconds = max(1, (int) config('services.line_bot.hmac_max_skew_seconds', 300));
        $secret = (string) config('services.line_bot.inbound_hmac_secret', '');

        $hasAnyHmacHeader = $timestamp !== '' || $nonce !== '' || $signature !== '';

        if (!$required && !$hasAnyHmacHeader) {
            return true;
        }

        if ($timestamp === '' || $nonce === '' || $signature === '' || $secret === '') {
            return false;
        }

        if (!preg_match('/^\d+$/', $timestamp) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return false;
        }

        $timestampInt = (int) $timestamp;

        if (abs(now()->timestamp - $timestampInt) > $maxSkewSeconds) {
            return false;
        }

        $rawBody = (string) $request->getContent();
        $canonical = $timestamp.'.'.$nonce.'.'.$rawBody;
        $expected = hash_hmac('sha256', $canonical, $secret);

        if (!hash_equals($expected, $signature)) {
            return false;
        }

        // Replay protection: each nonce can only be accepted once within the skew window.
        $nonceKey = 'line:hmac:inbound:nonce:'.sha1($nonce);

        return Cache::add($nonceKey, $timestampInt, $maxSkewSeconds);
    }

    /**
     * @return array<string, string>
     */
    public function buildOutboundHeaders(string $rawBody): array
    {
        $secret = (string) config('services.line_bot.outbound_hmac_secret', '');

        if ($secret === '') {
            return [];
        }

        $timestamp = (string) now()->timestamp;

        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (Throwable) {
            $nonce = Str::lower(Str::random(32));
        }

        $canonical = $timestamp.'.'.$nonce.'.'.$rawBody;
        $signature = hash_hmac('sha256', $canonical, $secret);

        return [
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
        ];
    }
}
