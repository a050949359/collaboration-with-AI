<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 圖片功能總開關。關閉時在「驗證 / Controller 之前」就回 404,
 * 讓功能徹底隱形 —— 避免 FormRequest 驗證先觸發 422 而洩漏端點存在。
 */
class EnsureImageFeatureEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('images.enabled'), 404);

        return $next($request);
    }
}
