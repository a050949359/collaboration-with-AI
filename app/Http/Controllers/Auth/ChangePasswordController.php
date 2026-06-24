<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class ChangePasswordController extends Controller
{
    private const INTERVAL_HOURS = 1;

    public function change(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'confirmed', Rules\Password::defaults()],
            'password_confirmation' => 'required',
        ]);

        /** @var User $user */
        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['errors' => ['current_password' => ['目前密碼不正確']]], 422);
        }

        if ($user->password_changed_at && $user->password_changed_at->diffInHours(now()) < self::INTERVAL_HOURS) {
            $remaining = self::INTERVAL_HOURS - (int) $user->password_changed_at->diffInHours(now());
            return response()->json(['message' => "密碼修改間隔不足，請於 {$remaining} 小時後再試"], 422);
        }

        if ($user->passwordUsedRecently($request->password)) {
            return response()->json(['errors' => ['password' => ['密碼不能與最近 ' . User::PASSWORD_HISTORY_LIMIT . ' 次相同']]], 422);
        }

        $user->password = $request->password;
        $hash = $user->password;
        $user->password_changed_at = now();
        $user->save();

        $user->recordPasswordHistory($hash);

        return response()->json(['message' => '密碼已成功更新']);
    }
}
