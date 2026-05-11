<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PublicKeyController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $path = env('RSA_PUBLIC_KEY_PATH');
        $pem  = ($path && file_exists(base_path($path))) ? file_get_contents(base_path($path)) : null;

        return response()->json(['key' => $pem ?: null]);
    }
}
