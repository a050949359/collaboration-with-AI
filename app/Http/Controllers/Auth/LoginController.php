<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;


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
        if (!app()->isLocal()) {
            $cfResponse = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret'   => env('TURNSTILE_SECRET_KEY'),
                'response' => $request->input('cf_turnstile_response'),
            ]);
            if (!$cfResponse->json('success')) {
                return response()->json(['message' => '機器人驗證失敗，請重新整理頁面後再試一次。'], 401);
            }
        }

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

        // 3. 刪除舊的 Token (選配：確保同一時間只有一個裝置登入)
        $user->tokens()->delete();

        // 4. 建立新 Token
        $token = $user->createToken('auth_token')->plainTextToken;
        $minutes = $remember ? 60 * 24 * 7 : 0;

        return response()->json([
            'message' => '登入成功',
            'user' => $user,
            'redirect' => route('home'),
        ])->cookie('auth_token', $token, $minutes, '/', null, app()->isProduction(), true, false, 'Lax');
    }

    public function logout(): JsonResponse
    {
        // 1. 驗證使用者是否已登入
        if (Auth::check()) {
            Auth::user()->tokens()->delete();

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