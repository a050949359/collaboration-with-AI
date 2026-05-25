<?php

namespace App\Http\Middleware;

use App\Models\ApiKey\UserApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticateWithApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if ($token) {
            $hash = hash('sha256', $token);
            $apiKey = UserApiKey::where('api_key_hash', $hash)
                ->whereNull('revoked_at')
                ->with('user')
                ->first();

            if ($apiKey?->user) {
                Auth::setUser($apiKey->user);
                $request->attributes->set('api_key_authed', true);
            }
        }

        return $next($request);
    }
}
