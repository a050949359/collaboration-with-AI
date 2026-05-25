<?php

namespace App\Http\Controllers\Line;

use App\Http\Controllers\Controller;
use App\Models\ShareToken;
use App\Support\LineBotHmac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LineAboutTokenController extends Controller
{
    public function issue(Request $request): JsonResponse
    {
        if ($authError = $this->authorizeInternalRequest($request)) {
            return $authError;
        }

        $payload = $request->validate([
            'line_user_id' => ['required', 'string', 'max:64'],
        ]);

        $lineUserId  = $payload['line_user_id'];
        $dailyLimit  = (int) config('services.line_bot.about_token_daily_limit', 2);
        $maxUses     = (int) config('services.line_bot.about_token_max_uses', 5);
        $expiresDays = (int) config('services.line_bot.about_token_expires_days', 7);

        $usesToday = ShareToken::where('line_user_id', $lineUserId)
            ->where('scope', 'about')
            ->whereDate('created_at', today())
            ->count();

        if ($usesToday >= $dailyLimit) {
            return response()->json([
                'message'    => '今日已達上限',
                'daily_limit' => $dailyLimit,
                'next_reset' => today()->addDay()->startOfDay()->toIso8601String(),
            ], 429);
        }

        $raw    = Str::random(48);
        $expiresAt = now()->addDays($expiresDays);

        $token = ShareToken::create([
            'token'        => hash('sha256', $raw),
            'scope'        => 'about',
            'max_uses'     => $maxUses,
            'note'         => 'LINE auto-issued',
            'expires_at'   => $expiresAt,
            'line_user_id' => $lineUserId,
            'created_by'   => null,
        ]);

        $url = rtrim((string) config('app.url'), '/') . '/app/about?t=' . $raw;

        return response()->json([
            'url'         => $url,
            'expires_at'  => $expiresAt->toIso8601String(),
            'max_uses'    => $token->max_uses,
            'uses_today'  => $usesToday + 1,
            'daily_limit' => $dailyLimit,
        ], 201);
    }

    private function authorizeInternalRequest(Request $request): ?JsonResponse
    {
        $expected = (string) config('services.line_bot.internal_api_key', '');
        $provided = (string) $request->header('X-Line-Bot-Key', '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized internal request'], 401);
        }

        $hmac = app(LineBotHmac::class);

        if (! $hmac->verifyInbound($request)) {
            return response()->json(['message' => 'Unauthorized internal request'], 401);
        }

        return null;
    }
}
