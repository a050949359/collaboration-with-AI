<?php

namespace App\Http\Middleware;

use App\Models\ShareToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 分享連結把關：登入者直接放行；否則需帶有效的 ShareToken（Bearer）。
 *
 * 通過後「先扣次數」並把 token 塞進 request attributes（'share_token'）：
 * 預扣可把並行繞過額度的 race window 從「AI 呼叫的數秒」縮到「DB 寫入的微秒」；
 * 若後續處理失敗，由 controller 於 catch 退回（decrement）。
 */
class ShareTokenAuth
{
    public function handle(Request $request, Closure $next, string $scope)
    {
        // 本專案登入走 token-in-cookie，預設 web(session) guard 認不到；
        // 須明確問 sanctum guard（AuthTokenFromCookie 會把 cookie 補成 Bearer）。
        if (Auth::guard('sanctum')->check() || Auth::check()) {
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

        $shareToken->incrementUses();
        $request->attributes->set('share_token', $shareToken);

        return $next($request);
    }
}
