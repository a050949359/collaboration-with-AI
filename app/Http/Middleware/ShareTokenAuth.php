<?php

namespace App\Http\Middleware;

use App\Models\ShareToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 分享連結把關：登入者直接放行；否則需帶有效的 ShareToken（Bearer）。
 * 解析出的 token 塞進 request attributes（'share_token'），交給 controller
 * 在「成功處理後」才呼叫 incrementUses()。
 */
class ShareTokenAuth
{
    public function handle(Request $request, Closure $next, string $scope)
    {
        if (Auth::check()) {
            return $next($request);
        }

        $raw = $request->bearerToken();
        if (! $raw) {
            return response()->json(['message' => '需要登入或有效的分享連結'], 401);
        }

        $shareToken = ShareToken::findByRaw($raw);
        if (! $shareToken || $shareToken->scope !== $scope || ! $shareToken->isValid()) {
            return response()->json(['message' => '分享連結無效或次數已用盡'], 403);
        }

        $request->attributes->set('share_token', $shareToken);

        return $next($request);
    }
}
