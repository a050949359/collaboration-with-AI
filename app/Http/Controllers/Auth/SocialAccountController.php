<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorChallengeService;
use App\Support\AppSettings;
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

    public function callback(string $provider, TwoFactorChallengeService $challenges): JsonResponse|RedirectResponse
    {
        abort_unless(in_array($provider, self::SUPPORTED_PROVIDERS, true), 404);

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();

            $email = $socialUser->getEmail();

            if (! $email) {
                return response()->json([
                    'message' => ucfirst($provider).' 回傳資料缺少 email，無法建立帳號',
                ], 422);
            }

            $user = User::firstOrNew(['email' => $email]);

            // 帳號不存在 = 首次登入即「自行註冊」；後台關閉註冊時擋下，僅放行既有帳號。
            if (! $user->exists) {
                if (! AppSettings::bool('allow_registration', true)) {
                    return $this->denyRegistration();
                }

                $user->name = $socialUser->getName() ?: 'Social User';
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

            // 已啟用 2FA：不發 token，帶 challenge token 跳回前端，由登入抽屜完成二階段
            if ($user->two_factor_enabled) {
                return $this->redirectToTwoFactorChallenge($provider, $challenges->create($user));
            }

            $user->tokens()->where('name', 'web')->whereNull('device_id')->delete();
            $token = $user->createToken('web')->plainTextToken;

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

    /**
     * OAuth 帳號開了 2FA：帶 two_factor_challenge 跳回前端，
     * AppLayout 讀到後開登入抽屜、LoginForm 直接進入 OTP 輸入階段。
     * token 走 hash fragment（#）而非 query：fragment 不會被送到伺服器，
     * 不進 access log、不進 Referer，瀏覽器歷史外洩面也較小。
     */
    private function redirectToTwoFactorChallenge(string $provider, string $challengeToken): RedirectResponse
    {
        $suffix = '?'.http_build_query(['provider' => $provider])
            .'#'.http_build_query(['two_factor_challenge' => $challengeToken]);
        $frontendUrl = config('services.social_auth.frontend_url');
        $redirectPath = config('services.social_auth.redirect_path', '/login');

        if (is_string($frontendUrl) && $frontendUrl !== '') {
            $target = rtrim($frontendUrl, '/').'/'.ltrim((string) $redirectPath, '/');

            return redirect()->away($target.$suffix);
        }

        return redirect()->to(route('home').$suffix);
    }

    /**
     * 關閉註冊時，OAuth 新帳號被擋：不發 token，跳回首頁並帶 auth_error，
     * 前端 AppLayout 讀到後顯示「暫停開放註冊」toast。
     */
    private function denyRegistration(): RedirectResponse
    {
        $query = http_build_query(['auth_error' => 'registration_closed']);
        $frontendUrl = config('services.social_auth.frontend_url');

        if (is_string($frontendUrl) && $frontendUrl !== '') {
            return redirect()->away(rtrim($frontendUrl, '/').'/?'.$query);
        }

        return redirect()->to(route('home').'?'.$query);
    }
}
