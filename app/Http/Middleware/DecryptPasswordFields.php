<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DecryptPasswordFields
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = env('RSA_PRIVATE_KEY_PATH');

        if (!$path || !file_exists(base_path($path))) {
            return response()->json(['message' => '伺服器加密設定錯誤，請聯絡管理員。'], 500);
        }

        $privateKey = openssl_pkey_get_private(file_get_contents(base_path($path)));

        if (!$privateKey) {
            return response()->json(['message' => '伺服器金鑰設定錯誤。'], 500);
        }

        foreach (['password', 'password_confirmation', 'current_password'] as $field) {
            if (!$request->filled($field)) continue;

            $encrypted = base64_decode($request->input($field), strict: true);

            if (!$encrypted || !openssl_private_decrypt($encrypted, $decrypted, $privateKey, OPENSSL_PKCS1_OAEP_PADDING)) {
                return response()->json(['message' => '密碼解密失敗，請重新整理後再試。'], 422);
            }

            $request->merge([$field => $decrypted]);
        }

        return $next($request);
    }
}
