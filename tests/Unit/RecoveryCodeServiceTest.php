<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Auth\RecoveryCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RecoveryCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecoveryCodeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RecoveryCodeService();
    }

    public function test_generate_returns_eight_unique_formatted_codes(): void
    {
        $user = User::factory()->create();

        $codes = $this->service->generateFor($user);

        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z2-7]{5}-[A-Z2-7]{5}$/', $code);
        }
    }

    public function test_database_stores_bcrypt_hashes_not_plaintext(): void
    {
        $user = User::factory()->create();

        $codes = $this->service->generateFor($user);
        $stored = $user->fresh()->two_factor_recovery_codes;

        $this->assertCount(8, $stored);
        foreach ($stored as $i => $hash) {
            $this->assertNotContains($hash, $codes);
            $this->assertTrue(Hash::check($codes[$i], $hash));
        }
    }

    public function test_redeem_consumes_code_once(): void
    {
        $user = User::factory()->create();
        $codes = $this->service->generateFor($user);

        $this->assertTrue($this->service->redeem($user, $codes[0]));
        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);

        // 同一組碼第二次失敗
        $this->assertFalse($this->service->redeem($user->fresh(), $codes[0]));
    }

    public function test_redeem_normalizes_case_and_whitespace(): void
    {
        $user = User::factory()->create();
        $codes = $this->service->generateFor($user);

        $this->assertTrue($this->service->redeem($user, ' '.strtolower($codes[1]).' '));
    }

    public function test_redeem_fails_without_codes(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->service->redeem($user, 'AAAAA-AAAAA'));
    }

    /** per-user 失敗上限：超過後連正確的碼也直接拒絕（CPU 耗盡防護）。 */
    public function test_redeem_is_blocked_after_max_failures(): void
    {
        $user = User::factory()->create();
        $codes = $this->service->generateFor($user);
        $max = (int) config('two-factor.recovery.redeem_max_failures');

        for ($i = 0; $i < $max; $i++) {
            $this->assertFalse($this->service->redeem($user, 'AAAAA-AAAAA'));
        }

        $this->assertFalse($this->service->redeem($user, $codes[0]), '達上限後正確碼也應被拒絕');
    }

    /** 成功 redeem 會重置失敗計數。 */
    public function test_successful_redeem_resets_failure_counter(): void
    {
        $user = User::factory()->create();
        $codes = $this->service->generateFor($user);
        $max = (int) config('two-factor.recovery.redeem_max_failures');

        for ($i = 0; $i < $max - 1; $i++) {
            $this->service->redeem($user, 'AAAAA-AAAAA');
        }

        $this->assertTrue($this->service->redeem($user, $codes[0]));

        // 計數已重置：再累積 max-1 次失敗後，正確碼仍可通過
        for ($i = 0; $i < $max - 1; $i++) {
            $this->service->redeem($user->fresh(), 'AAAAA-AAAAA');
        }
        $this->assertTrue($this->service->redeem($user->fresh(), $codes[1]));
    }
}
