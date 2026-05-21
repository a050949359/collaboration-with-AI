<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class ChangePasswordController extends Controller
{
    private const INTERVAL_HOURS = 0;
    private const HISTORY_LIMIT  = 5;

    public function change(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'confirmed', Rules\Password::min(8)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => 'required',
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['errors' => ['current_password' => ['目前密碼不正確']]], 422);
        }

        if ($user->password_changed_at && $user->password_changed_at->diffInHours(now()) < self::INTERVAL_HOURS) {
            $remaining = self::INTERVAL_HOURS - (int) $user->password_changed_at->diffInHours(now());
            return response()->json(['message' => "密碼修改間隔不足，請於 {$remaining} 小時後再試"], 422);
        }

        foreach ($user->passwordHistories()->take(self::HISTORY_LIMIT)->get() as $history) {
            if (Hash::check($request->password, $history->password_hash)) {
                return response()->json(['errors' => ['password' => ['密碼不能與最近 ' . self::HISTORY_LIMIT . ' 次相同']]], 422);
            }
        }

        $user->password = $request->password;
        $hash = $user->password;
        $user->password_changed_at = now();
        $user->save();

        $user->passwordHistories()->create(['password_hash' => $hash]);
        $ids = $user->passwordHistories()->pluck('id')->skip(self::HISTORY_LIMIT);
        if ($ids->count()) {
            $user->passwordHistories()->whereIn('id', $ids)->delete();
        }

        return response()->json(['message' => '密碼已成功更新']);
    }
}
