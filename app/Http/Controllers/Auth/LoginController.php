<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\IssuesAuthTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\TwoFactorChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
/**
 * 基礎登入功能
 * 1. token 二次登入確認: 是否過期
 * 2. 帳號確認, 密碼確認
 * 3. 第三方登入（如 Google、Facebook）整合
 */
class LoginController extends Controller
{
    use IssuesAuthTokens;

    private const MAX_ATTEMPTS     = 5;
    private const LOCKOUT_MINUTES  = 15;

    public function login(LoginRequest $request, TwoFactorChallengeService $challenges): JsonResponse
    {
        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        $user = User::where('email', $credentials['email'])->first();

        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            $remaining = (int) now()->diffInMinutes($user->locked_until, false);
            $remaining = max(1, $remaining);
            return response()->json(['message' => "帳號已暫時鎖定，請於 {$remaining} 分鐘後再試"], 429);
        }

        if (!Auth::attempt($credentials, $remember)) {
            // 帳號鎖定計數只在帳號存在時進行；但對外一律回同一句 401，
            // 不洩漏「此 email 是否註冊／還剩幾次」，避免使用者列舉（enumeration）。
            // 跨帳號的暴力嘗試（密碼噴灑、猜不存在的 email）由路由層 throttle:10,1（依 IP）擋。
            if ($user) {
                $user->failed_login_attempts += 1;
                if ($user->failed_login_attempts >= self::MAX_ATTEMPTS) {
                    $user->locked_until = now()->addMinutes(self::LOCKOUT_MINUTES);
                }
                $user->save();
            }

            return response()->json(['message' => '帳號或密碼錯誤'], 401);
        }

        // 2. 取得 User 實例
        $user = Auth::user();
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->save();

        $deviceId   = $request->validated('device_id');
        $deviceName = $request->validated('device_name');

        // 2.5 已啟用 2FA：密碼正確仍不發 token，改發 challenge（限時憑 OTP/備援碼換 token）。
        //     api 群組無 StartSession，Auth::attempt 的 session 只存在記憶體不落地，此回應不含任何可用憑證。
        if ($user->two_factor_enabled) {
            return response()->json([
                'two_factor_required' => true,
                'challenge_token' => $challenges->create($user, $remember, $deviceId, $deviceName),
                'message' => '請輸入兩步驟驗證碼',
            ]);
        }

        // 3. 發 token + cookie（與 2FA challenge 通過後共用同一套發放邏輯）
        return $this->issueToken($user, $remember, $deviceId, $deviceName);
    }

    public function logout(): JsonResponse
    {
        // 1. 驗證使用者是否已登入
        if (Auth::check()) {
            $token = Auth::user()->currentAccessToken();
            if ($token instanceof \App\Models\PersonalAccessToken) {
                $token->delete();
            }

            return response()->json(['message' => '登出成功'])
                ->withoutCookie('auth_token');
        }

        return response()->json(['message' => '未登入'], 401);
    }

    public function me(): JsonResponse
    {
        // 1. 驗證使用者是否已登入
        if (Auth::check()) {
            // 2. 回傳目前使用者的資訊
            return response()->json(Auth::user());
        }

        return response()->json(['message' => '未登入'], 401);
    }
}