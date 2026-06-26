<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        // scoped（非 View::share）：per-request 綁定，Octane/Swoole 等常駐環境每請求自動清，不殘留。
        app()->scoped('cspNonce', fn () => $nonce);

        $response = $next($request);

        // 只給 HTML 文件加 CSP；JSON / 圖片(如 avatar)/檔案下載加了多餘。
        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            // 邊緣偵測(OpenCV WASM) / 手勢辨識(MediaPipe TFLite WASM) 需 WASM + eval，
            // 只對這兩頁放寬 unsafe-eval，其餘頁(登入/admin/文章…)維持嚴格。
            $allowEval = $request->routeIs('computer-vision', 'gesture');
            $response->headers->set(self::HEADER, $this->policy($nonce, $allowEval));
        }

        return $response;
    }

    private function policy(string $nonce, bool $allowEval = false): string
    {
        $scriptSrc = "script-src 'self' 'nonce-{$nonce}' https://challenges.cloudflare.com";

        if ($allowEval) {
            $scriptSrc .= " 'unsafe-eval'";
        }

        return implode('; ', [
            "default-src 'self'",
            // inline theme script 走 nonce；Turnstile 載入腳本。（CV 頁另含 unsafe-eval）
            $scriptSrc,
            // Vue :style 產生 inline style 屬性，只能 unsafe-inline；Google Fonts 樣式表。
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            // self + data:；Google 登入頭像走 *.googleusercontent.com。
            "img-src 'self' data: https://*.googleusercontent.com",
            // 同源 ws-lab/gacha 由 'self' 涵蓋；Gemini Live wss；地球儀的 world-atlas JSON。
            "connect-src 'self' wss://generativelanguage.googleapis.com https://cdn.jsdelivr.net",
            // 'self'：mini-orch 嵌自己的 dashboard iframe（同源）；Turnstile widget iframe。
            "frame-src 'self' https://challenges.cloudflare.com",
            // 防 clickjacking：只允許自家頁面 iframe 本站，擋外部嵌入。
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
