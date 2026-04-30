<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAccountController extends Controller
{
    private const SUPPORTED_PROVIDERS = [
        'google',
    ];

    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::SUPPORTED_PROVIDERS, true), 404);

        return Socialite::driver($provider)->stateless()->with(['prompt' => 'select_account'])->redirect();
    }

    public function callback(string $provider): JsonResponse|RedirectResponse
    {
        abort_unless(in_array($provider, self::SUPPORTED_PROVIDERS, true), 404);

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();

            $email = $socialUser->getEmail();

            if (!$email) {
                return response()->json([
                    'message' => ucfirst($provider).' 回傳資料缺少 email，無法建立帳號',
                ], 422);
            }

            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $socialUser->getName() ?: 'Social User']
            );
            
            if ($user->wasRecentlyCreated && is_null($user->email_verified_at)) {
                $user->email_verified_at = now();
                $user->save();
            }

            $user->socialAccounts()->updateOrCreate(
                ['provider' => $provider, 'provider_user_id' => $socialUser->getId()],
                [
                    'provider_email' => $email,
                    'avatar_url' => $socialUser->getAvatar(),
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
                ]
            );

            $token = $user->createToken('auth_token')->plainTextToken;

            $frontendUrl = config('services.social_auth.frontend_url');
            $redirectPath = config('services.social_auth.redirect_path', '/login');

            if (is_string($frontendUrl) && $frontendUrl !== '') {
                $target = rtrim($frontendUrl, '/').'/'.ltrim((string) $redirectPath, '/');
                $query = http_build_query(['provider' => $provider]);

                return redirect()->away($target.'?'.$query)
                    ->cookie('auth_token', $token, 0, '/', null, app()->isProduction(), true, false, 'Lax');
            }

            return response()->json([
                'message' => ucfirst($provider).' 登入成功',
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => ucfirst($provider).' 登入失敗',
            ], 500);
        }
    }
}
