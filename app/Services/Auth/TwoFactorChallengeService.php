<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * 登入二階段（2FA challenge）狀態機：
 * 密碼驗證通過但尚未通過 OTP 前，唯一存在的憑證是這筆 cache 記錄——
 * 它只能拿來「換取輸入 OTP 的資格」，打不了任何 API。
 */
class TwoFactorChallengeService
{
    public function __construct(private readonly TotpService $totp)
    {
    }

    /** 建立 challenge，回傳一次性 token（明文只出現在 login 回應中）。 */
    public function create(User $user, bool $remember = false, ?string $deviceId = null, ?string $deviceName = null): string
    {
        $token = bin2hex(random_bytes(32));
        $ttl = (int) config('two-factor.challenge.ttl', 300);

        Cache::put($this->key($token), [
            'user_id' => $user->getKey(),
            'remember' => $remember,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'attempts' => 0,
            // 自帶絕對到期時間：recordFailure 重寫時據此保留剩餘 TTL，失敗不會續命
            'expires_at' => now()->addSeconds($ttl)->getTimestamp(),
        ], $ttl);

        return $token;
    }

    /** @return array{user_id: int, remember: bool, device_id: string|null, device_name: string|null, attempts: int, expires_at: int}|null */
    public function get(string $token): ?array
    {
        $data = Cache::get($this->key($token));

        return is_array($data) ? $data : null;
    }

    /** 記一次失敗；達上限即作廢。回傳 challenge 是否已作廢。 */
    public function recordFailure(string $token): bool
    {
        $data = $this->get($token);
        if ($data === null) {
            return true;
        }

        $data['attempts']++;
        $remaining = $data['expires_at'] - now()->getTimestamp();

        if ($data['attempts'] >= (int) config('two-factor.challenge.max_attempts', 5) || $remaining <= 0) {
            Cache::forget($this->key($token));

            return true;
        }

        Cache::put($this->key($token), $data, $remaining);

        return false;
    }

    public function consume(string $token): void
    {
        Cache::forget($this->key($token));
    }

    /**
     * TOTP 驗證 + replay 防護：per-user 記錄最後用過的 timestep，
     * 相同或更舊的 6 碼即使正確也拒絕——攔截到的碼用過一次即失效。
     */
    public function verifyTotpOnce(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        $step = $this->totp->verify($user->two_factor_secret, $code);
        if ($step === null) {
            return false;
        }

        $key = "2fa:last-step:{$user->getKey()}";
        $last = Cache::get($key);
        if (is_int($last) && $step <= $last) {
            return false;
        }

        $period = (int) config('two-factor.period', 30);
        $window = (int) config('two-factor.window', 1);
        Cache::put($key, $step, $period * (2 * $window + 2));

        return true;
    }

    private function key(string $token): string
    {
        return "2fa:challenge:{$token}";
    }
}
