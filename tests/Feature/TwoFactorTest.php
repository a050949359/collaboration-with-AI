<?php

namespace Tests\Feature;

use App\Http\Middleware\DecryptPasswordFields;
use App\Models\User;
use App\Services\Auth\RecoveryCodeService;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private TotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = app(TotpService::class);
        // CI 無 RSA pem 檔，且 RSA 解密非本測試對象；密碼直接送明文驗 Hash::check 路徑
        $this->withoutMiddleware(DecryptPasswordFields::class);
    }

    /** 建立已確認啟用 2FA 的使用者，回傳 [user, secret]。 */
    private function userWithTwoFactor(): array
    {
        $user = User::factory()->create();
        $secret = $this->totp->generateSecret();
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->save();
        app(RecoveryCodeService::class)->generateFor($user);

        return [$user->fresh(), $secret];
    }

    public function test_guests_cannot_access_two_factor_endpoints(): void
    {
        foreach (['enable', 'confirm', 'disable', 'recovery-codes'] as $path) {
            $this->postJson("/api/auth/2fa/{$path}")->assertUnauthorized();
        }
    }

    public function test_enable_creates_pending_secret(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/auth/2fa/enable');

        $response->assertOk()->assertJsonStructure(['secret', 'otpauth_uri']);
        $this->assertStringStartsWith('otpauth://totp/', $response->json('otpauth_uri'));
        $this->assertStringContainsString('secret='.$response->json('secret'), $response->json('otpauth_uri'));

        $user->refresh();
        $this->assertTrue($user->hasPendingTwoFactor());
        $this->assertFalse($user->two_factor_enabled);
    }

    public function test_enable_twice_replaces_pending_secret(): void
    {
        $user = User::factory()->create();

        $first = $this->actingAs($user)->postJson('/api/auth/2fa/enable')->json('secret');
        $second = $this->actingAs($user)->postJson('/api/auth/2fa/enable')->json('secret');

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $user->fresh()->two_factor_secret);
    }

    public function test_enable_rejected_when_already_confirmed(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->actingAs($user)->postJson('/api/auth/2fa/enable')->assertStatus(422);
    }

    public function test_confirm_with_valid_code_enables_and_returns_recovery_codes(): void
    {
        $user = User::factory()->create();
        $secret = $this->actingAs($user)->postJson('/api/auth/2fa/enable')->json('secret');

        $response = $this->postJson('/api/auth/2fa/confirm', [
            'code' => $this->totp->code($secret),
        ]);

        $response->assertOk()
            ->assertJsonCount(8, 'recovery_codes')
            ->assertJsonPath('user.two_factor_enabled', true);

        $this->assertTrue($user->fresh()->two_factor_enabled);
    }

    public function test_confirm_with_invalid_code_fails(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/auth/2fa/enable');

        $this->postJson('/api/auth/2fa/confirm', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['code']]);

        $this->assertFalse($user->fresh()->two_factor_enabled);
    }

    public function test_confirm_without_pending_secret_fails(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/2fa/confirm', ['code' => '123456'])
            ->assertStatus(422);
    }

    public function test_disable_pending_requires_no_credential(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/auth/2fa/enable');

        $this->postJson('/api/auth/2fa/disable')->assertOk();

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_disable_confirmed_with_password(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->actingAs($user)->postJson('/api/auth/2fa/disable', ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.two_factor_enabled', false);

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_disable_confirmed_with_totp_code(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();

        $this->actingAs($user)->postJson('/api/auth/2fa/disable', [
            'code' => $this->totp->code($secret),
        ])->assertOk();

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_disable_confirmed_rejects_wrong_credential(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->actingAs($user)->postJson('/api/auth/2fa/disable', ['password' => 'wrong-password'])
            ->assertStatus(422);
        $this->actingAs($user)->postJson('/api/auth/2fa/disable', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertTrue($user->fresh()->two_factor_enabled);
    }

    /** 無密碼 + 手機遺失情境：備援碼可作為停用 2FA 的憑證（用掉即作廢）。 */
    public function test_disable_with_recovery_code(): void
    {
        [$user] = $this->userWithTwoFactor();
        $codes = app(RecoveryCodeService::class)->generateFor($user);

        $this->actingAs($user)->postJson('/api/auth/2fa/disable', [
            'code' => $codes[0],
        ])->assertOk();

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    /** 密碼管理器情境：兩欄同時送出時擇一有效即可（密碼錯要 fallback 驗 OTP）。 */
    public function test_disable_falls_back_to_code_when_password_wrong(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();

        $this->actingAs($user)->postJson('/api/auth/2fa/disable', [
            'password' => 'wrong-password',
            'code' => $this->totp->code($secret),
        ])->assertOk();

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_disable_when_not_enabled_fails(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/2fa/disable', ['password' => 'password'])
            ->assertStatus(422);
    }

    public function test_regenerate_recovery_codes_with_valid_credential(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $oldHashes = $user->two_factor_recovery_codes;

        $response = $this->actingAs($user)->postJson('/api/auth/2fa/recovery-codes', [
            'code' => $this->totp->code($secret),
        ]);

        $response->assertOk()->assertJsonCount(8, 'recovery_codes');
        $this->assertNotSame($oldHashes, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_regenerate_recovery_codes_rejects_wrong_credential(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->actingAs($user)->postJson('/api/auth/2fa/recovery-codes', ['code' => '000000'])
            ->assertStatus(422);
    }

    public function test_regenerate_recovery_codes_when_not_enabled_fails(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/2fa/recovery-codes', ['password' => 'password'])
            ->assertStatus(422);
    }

    /** 序列化安全：任何 user JSON 都不得洩漏 secret / recovery codes。 */
    public function test_me_exposes_flag_but_never_secrets(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('two_factor_enabled', true)
            ->assertJsonMissingPath('two_factor_secret')
            ->assertJsonMissingPath('two_factor_recovery_codes');
    }
}
