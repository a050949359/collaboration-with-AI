<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Auth token bridge middleware.
 *
 * 本專案的登入流程不使用 Laravel Sanctum SPA cookie（XSRF-TOKEN），
 * 而是在 login 時將 Sanctum Personal Access Token 寫入 HttpOnly cookie（auth_token）。
 *
 * 這個 middleware 的作用：
 *   當 request 沒有帶 Bearer token 時，從 cookie 讀取 auth_token 並自動補上
 *   Authorization: Bearer <token> header，讓後續的 auth:sanctum guard 可以正常驗證。
 *
 * 掛載位置：bootstrap/app.php → web group（prependToGroup）
 * 這樣 web 路由（Inertia SSR）與 API 路由都能透過 cookie 取得登入身份。
 *
 * @see \App\Http\Controllers\Auth\LoginController::login  寫入 cookie 的位置
 * @see \App\Http\Middleware\HandleInertiaRequests::share   將 auth user 注入前端 props
 */
class AuthTokenFromCookie
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (!$request->bearerToken()) {
            $token = $request->cookie('auth_token');

            if ($token && is_string($token)) {
                $request->headers->set('Authorization', 'Bearer '.$token);
            }
        }

        return $next($request);
    }
}
