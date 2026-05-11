<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegistRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class RegistController extends Controller
{
    public function register(RegistRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (!app()->isLocal()) {
            $cfResponse = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret'   => env('TURNSTILE_SECRET_KEY'),
                'response' => $request->input('cf_turnstile_response'),
            ]);
            if (!$cfResponse->json('success')) {
                return response()->json(['message' => '機器人驗證失敗，請重新整理頁面後再試一次。'], 401);
            }
        }

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => '註冊成功',
            'user' => $user,
            'redirect' => route('home'),
        ], 201)->cookie('auth_token', $token, 0, '/', null, app()->isProduction(), true, false, 'Lax');
    }
}
