<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
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
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        if (!Auth::attempt($credentials, $remember)) {
            return response()->json(['message' => '登入失敗'], 401);
        }

        // 2. 取得 User 實例
        $user = Auth::user();

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