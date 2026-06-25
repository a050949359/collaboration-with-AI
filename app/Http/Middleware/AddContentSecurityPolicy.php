<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * 為 web（Inertia HTML）回應加上 Content-Security-Policy。
 *
 * - 每請求產生 nonce，share 給 app.blade.php 的 inline theme script（其餘 inline script 一律擋）。
 * - 目前用 Report-Only：瀏覽器只回報違規、不真的擋；各頁確認 console 乾淨後，
 *   把 HEADER 常數改成 'Content-Security-Policy' 即正式啟用。
 * - 本地（dev）跳過：Vite dev server 的 HMR WebSocket / inline script 會與 CSP 打架。
 */
class AddContentSecurityPolicy
{
    /** 先 Report-Only；驗證無誤後改成 'Content-Security-Policy'。 */
    private const HEADER = 'Content-Security-Policy-Report-Only';

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isLocal()) {
            return $next($request);
        }

        $nonce = base64_encode(random_bytes(16));
        View::share('cspNonce', $nonce);

        $response = $next($request);

        $response->headers->set(self::HEADER, $this->policy($nonce));

        return $response;
    }

    private function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            // inline theme script 走 nonce；Turnstile 載入腳本。
            "script-src 'self' 'nonce-{$nonce}' https://challenges.cloudflare.com",
            // Vue :style 產生 inline style 屬性，只能 unsafe-inline；Google Fonts 樣式表。
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data:",
            // 同源 ws-lab/gacha 由 'self' 涵蓋；Gemini Live wss；地球儀的 world-atlas JSON。
            "connect-src 'self' wss://generativelanguage.googleapis.com https://cdn.jsdelivr.net",
            // Turnstile widget iframe。
            'frame-src https://challenges.cloudflare.com',
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
