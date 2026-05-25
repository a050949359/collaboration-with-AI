<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class VerifyTurnstile
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isLocal()) {
            $cfResponse = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret'   => config('services.turnstile.secret_key'),
                'response' => $request->input('cf_turnstile_response'),
            ]);
            if (! $cfResponse->json('success')) {
                return response()->json(['message' => '機器人驗證失敗，請重新整理頁面後再試一次。'], 401);
            }
        }

        return $next($request);
    }
}
