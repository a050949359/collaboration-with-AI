<?php

namespace Tests\Feature;

use App\Http\Middleware\DecryptPasswordFields;
use App\Http\Middleware\VerifyTurnstile;
use App\Models\User;
use App\Services\Auth\RecoveryCodeService;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    private TotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = app(TotpService::class);
        // 測試環境非 local：turnstile 會真打 Cloudflare、RSA 無 pem，兩者皆非本測試對象；
        // throttle（challenge 5/min）也 bypass，連錯作廢由 challenge 自身的 max_attempts 把關
        $this->withoutMiddleware([
            DecryptPasswordFields::class,
            VerifyTurnstile::class,
            ThrottleRequests::class,
        ]);
    }

    /** @return array{0: User, 1: string, 2: array<int, string>} user / secret / recovery codes */
    private function userWithTwoFactor(): array
    {
        $user = User::factory()->create();
        $secret = $this->totp->generateSecret();
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->save();
        $codes = app(RecoveryCodeService::class)->generateFor($user);

        return [$user->fresh(), $secret, $codes];
    }

    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => false,
            'cf_turnstile_response' => 'test-token',
        ]);
    }

    // ── 第一段：login 分岔 ────────────────────────────────────────

    public function test_login_with_two_factor_returns_challenge_without_credentials(): void
    {
        [$user] = $this->userWithTwoFactor();

        $response = $this->login($user);

        $response->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonStructure(['challenge_token'])
            ->assertJsonMissingPath('access_token')
            ->assertJsonMissingPath('user');

        $this->assertNull($response->headers->getCookies()[0] ?? null, 'challenge 回應不得種任何 cookie');
        $this->assertSame(0, $user->tokens()->count(), 'challenge 階段不得產生 token');
    }

    public function test_login_without_two_factor_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this->login($user);

        $response->assertOk()
            ->assertJsonStructure(['access_token', 'user', 'redirect'])
            ->assertJsonMissingPath('two_factor_required')
            ->assertCookie('auth_token');
    }

    public function test_wrong_password_still_rejected_for_two_factor_user(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'remember' => false,
            'cf_turnstile_response' => 'test-token',
        ])->assertUnauthorized();
    }

    // ── 第二段：challenge 換 token ────────────────────────────────

    public function test_challenge_with_valid_totp_issues_token(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $token = $this->login($user)->json('challenge_token');

        $response = $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $token,
            'code' => $this->totp->code($secret),
        ]);

        $response->assertOk()
            ->assertJsonStructure(['access_token', 'user', 'redirect'])
            ->assertCookie('auth_token');
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_challenge_token_is_single_use(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $token = $this->login($user)->json('challenge_token');

        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $token,
            'code' => $this->totp->code($secret),
        ])->assertOk();

        // 同一 challenge token 再用一次（即使碼正確）→ 401
        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $token,
            'code' => $this->totp->code($secret),
        ])->assertUnauthorized();
    }

    public function test_challenge_with_wrong_code_fails_and_invalidates_after_max_attempts(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $token = $this->login($user)->json('challenge_token');

        // 前 4 次錯誤 → 422 停留在 challenge
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/2fa/challenge', [
                'challenge_token' => $token,
                'code' => '000000',
            ])->assertStatus(422)->assertJsonStructure(['errors' => ['code']]);
        }

        // 第 5 次錯誤 → challenge 作廢
        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $token,
            'code' => '000000',
        ])->assertUnauthorized();

        // 作廢後即使碼正確也進不來
        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $token,
            'code' => $this->totp->code($secret),
        ])->assertUnauthorized();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_challenge_with_unknown_token_fails(): void
    {
        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => str_repeat('ab', 32),
            'code' => '123456',
        ])->assertUnauthorized();
    }

    // ── Replay 防護 ──────────────────────────────────────────────

    public function test_same_totp_code_cannot_be_replayed(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $code = $this->totp->code($secret);

        $firstToken = $this->login($user)->json('challenge_token');
        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $firstToken,
            'code' => $code,
        ])->assertOk();

        // 攔截到同一組 6 碼再登入一次 → 被 replay 防護擋下
        $secondToken = $this->login($user)->json('challenge_token');
        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $secondToken,
            'code' => $code,
        ])->assertStatus(422);
    }

    // ── 備援碼路徑 ────────────────────────────────────────────────

    public function test_challenge_with_recovery_code_issues_token_and_consumes_it(): void
    {
        [$user, , $codes] = $this->userWithTwoFactor();

        $token = $this->login($user)->json('challenge_token');
        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $token,
            'code' => $codes[0],
        ])->assertOk()->assertCookie('auth_token');

        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);

        // 同一組備援碼第二次 → 失敗
        $token2 = $this->login($user)->json('challenge_token');
        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $token2,
            'code' => $codes[0],
        ])->assertStatus(422);
    }

    // ── 邊界 ─────────────────────────────────────────────────────

    public function test_challenge_passes_if_two_factor_disabled_meanwhile(): void
    {
        [$user] = $this->userWithTwoFactor();
        $token = $this->login($user)->json('challenge_token');

        // challenge 期間停用 2FA（密碼已驗過，視同通過）
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        $this->postJson('/api/auth/2fa/challenge', [
            'challenge_token' => $token,
            'code' => '000000',
        ])->assertOk()->assertCookie('auth_token');
    }

    public function test_challenge_requires_token_and_code(): void
    {
        $this->postJson('/api/auth/2fa/challenge', [])->assertStatus(422);
    }
}
