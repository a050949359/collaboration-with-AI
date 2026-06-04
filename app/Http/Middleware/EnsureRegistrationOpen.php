<?php

namespace App\Http\Middleware;

use App\Support\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 後台「開放使用者自行註冊」關閉時，擋下 email 註冊請求。
 * （Google OAuth / LINE 首次建帳號另在各自 Controller 內判斷。）
 */
class EnsureRegistrationOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!AppSettings::bool('allow_registration', true)) {
            return response()->json(['message' => '目前暫停開放註冊'], 403);
        }

        return $next($request);
    }
}
