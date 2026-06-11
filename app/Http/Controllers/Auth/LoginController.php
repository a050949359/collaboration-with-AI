<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
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
    private const MAX_ATTEMPTS     = 5;
    private const LOCKOUT_MINUTES  = 15;

    public function login(LoginRequest $request): JsonResponse
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
            if ($user) {
                $user->failed_login_attempts += 1;
                if ($user->failed_login_attempts >= self::MAX_ATTEMPTS) {
                    $user->locked_until = now()->addMinutes(self::LOCKOUT_MINUTES);
                    $user->save();
                    return response()->json(['message' => "登入失敗次數過多，帳號已鎖定 " . self::LOCKOUT_MINUTES . " 分鐘"], 429);
                }
                $user->save();
                $remaining = self::MAX_ATTEMPTS - $user->failed_login_attempts;
                return response()->json(['message' => "登入失敗，還剩 {$remaining} 次機會"], 401);
            }
            return response()->json(['message' => '登入失敗'], 401);
        }

        // 2. 取得 User 實例
        $user = Auth::user();
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->save();

        $deviceId   = $request->input('device_id');
        $deviceName = $request->input('device_name');

        // 3. 刪除同裝置的舊 Token（web 以 name='web' 定位，mobile 以 device_id 定位）
        if ($deviceId) {
            $user->tokens()->where('device_id', $deviceId)->delete();
        } else {
            $user->tokens()->where('name', 'web')->whereNull('device_id')->delete();
        }

        // 4. 建立新 Token（90 天有效期）
        $tokenName = $deviceName ?? ($deviceId ? 'mobile' : 'web');
        $plainText = $user->createToken($tokenName, deviceId: $deviceId)->plainTextToken;
        $minutes   = $remember ? 60 * 24 * 7 : 0;

        return response()->json([
            'message'      => '登入成功',
            'user'         => $user,
            'access_token' => $plainText,
            'token_type'   => 'Bearer',
            'redirect'     => route('home'),
        ])->cookie('auth_token', $plainText, $minutes, '/', null, app()->isProduction(), true, false, 'Lax');
    }

    public function logout(): JsonResponse
    {
        // 1. 驗證使用者是否已登入
        if (Auth::check()) {
            Auth::user()->currentAccessToken()->delete();

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