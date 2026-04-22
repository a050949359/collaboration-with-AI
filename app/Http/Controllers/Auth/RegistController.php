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

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => '註冊成功',
            'user' => $user,
            'redirect' => route('home'),
        ], 201)->cookie('auth_token', $token, 0, '/', null, app()->isProduction(), true, false, 'Lax');
    }
}
