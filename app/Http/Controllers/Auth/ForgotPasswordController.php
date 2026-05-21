<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules;

class ForgotPasswordController extends Controller
{
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        Password::sendResetLink($request->only('email'));

        // Always return the same message to prevent email enumeration
        return response()->json(['message' => '如果此信箱已註冊，重設連結已寄出，請查收信件。']);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token'                 => 'required|string',
            'email'                 => 'required|email',
            'password'              => ['required', 'confirmed', Rules\Password::min(8)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            foreach ($user->passwordHistories()->take(5)->get() as $history) {
                if (Hash::check($request->password, $history->password_hash)) {
                    return response()->json(['errors' => ['password' => ['密碼不能與最近 5 次相同']]], 422);
                }
            }
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->password = $password;
                $hash = $user->password;
                $user->password_changed_at = now();
                $user->failed_login_attempts = 0;
                $user->locked_until = null;
                $user->save();

                $user->passwordHistories()->create(['password_hash' => $hash]);
                $ids = $user->passwordHistories()->pluck('id')->skip(5);
                if ($ids->count()) {
                    $user->passwordHistories()->whereIn('id', $ids)->delete();
                }
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'token 無效或已過期，請重新發送重設信件。'], 422);
        }

        return response()->json(['message' => '密碼已重設，請使用新密碼登入。']);
    }
}
