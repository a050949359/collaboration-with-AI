<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

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
            'password'              => ['required', 'string', 'confirmed', Rules\Password::defaults()],
            'password_confirmation' => 'required',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                // 歷史檢查放在 token 驗證之後（callback 內），避免未驗證 token 就跑 bcrypt
                // 而透過回應延遲洩漏 email 是否存在（timing-based user enumeration）。
                if ($user->passwordUsedRecently($password)) {
                    throw ValidationException::withMessages([
                        'password' => ['密碼不能與最近 ' . User::PASSWORD_HISTORY_LIMIT . ' 次相同'],
                    ]);
                }

                $user->password = $password;
                $hash = $user->password;
                $user->password_changed_at = now();
                $user->failed_login_attempts = 0;
                $user->locked_until = null;
                $user->save();

                $user->recordPasswordHistory($hash);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'token 無效或已過期，請重新發送重設信件。'], 422);
        }

        return response()->json(['message' => '密碼已重設，請使用新密碼登入。']);
    }
}
