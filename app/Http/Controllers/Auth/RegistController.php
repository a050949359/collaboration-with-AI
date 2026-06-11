<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegistRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
class RegistController extends Controller
{
    public function register(RegistRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $deviceId  = $validated['device_id'] ?? null;
        $tokenName = $validated['device_name'] ?? ($deviceId ? 'mobile' : 'web');
        $plainText = $user->createToken($tokenName, deviceId: $deviceId)->plainTextToken;

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message'      => '註冊成功',
            'user'         => $user,
            'access_token' => $plainText,
            'token_type'   => 'Bearer',
            'redirect'     => route('home'),
        ], 201)->cookie('auth_token', $plainText, 0, '/', null, app()->isProduction(), true, false, 'Lax');
    }
}
