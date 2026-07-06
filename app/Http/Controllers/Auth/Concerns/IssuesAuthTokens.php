<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * 登入成功的 token 發放（LoginController 與 TwoFactorController challenge 共用）：
 * 刪同裝置舊 token → 發新 token（90 天）→ auth_token HttpOnly cookie。
 */
trait IssuesAuthTokens
{
    protected function issueToken(User $user, bool $remember, ?string $deviceId, ?string $deviceName): JsonResponse
    {
        // 刪除同裝置的舊 Token（web 以 name='web' 定位，mobile 以 device_id 定位）
        if ($deviceId) {
            $user->tokens()->where('device_id', $deviceId)->delete();
        } else {
            $user->tokens()->where('name', 'web')->whereNull('device_id')->delete();
        }

        $tokenName = $deviceName ?? ($deviceId ? 'mobile' : 'web');
        $plainText = $user->createToken($tokenName, deviceId: $deviceId)->plainTextToken;
        $minutes = $remember ? 60 * 24 * 7 : 0;

        return response()->json([
            'message' => '登入成功',
            'user' => $user,
            'access_token' => $plainText,
            'token_type' => 'Bearer',
            'redirect' => route('home'),
        ])->cookie('auth_token', $plainText, $minutes, '/', null, app()->isProduction(), true, false, 'Lax');
    }
}
