<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * 2FA 一次性備援碼：明文只在產生當下回傳一次，DB 僅存 bcrypt hash（同密碼防護等級）。
 */
class RecoveryCodeService
{
    /** 與 base32 同字元集（去除易混淆的 0/1/8/9），格式 XXXXX-XXXXX。 */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * 為使用者產生一批新備援碼並存入 hash（整批覆蓋舊碼），回傳明文陣列供一次性顯示。
     *
     * @return array<int, string>
     */
    public function generateFor(User $user): array
    {
        $count = (int) config('two-factor.recovery.count', 8);

        $codes = [];
        while (count($codes) < $count) {
            $code = $this->randomCode();
            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        $user->two_factor_recovery_codes = array_map(fn (string $code) => Hash::make($code), $codes);
        $user->save();

        return $codes;
    }

    /**
     * 驗證並作廢：命中即從 hash 陣列移除該碼並儲存。
     * per-user 失敗計數超過上限時直接拒絕（不跑 bcrypt），
     * 阻斷「持有密碼 + 多 IP」的 CPU 耗盡攻擊。
     */
    public function redeem(User $user, string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes;
        if (!is_array($hashes) || $hashes === []) {
            return false;
        }

        $failKey = "2fa:redeem-fails:{$user->getKey()}";
        if ((int) Cache::get($failKey, 0) >= (int) config('two-factor.recovery.redeem_max_failures', 10)) {
            return false;
        }

        // 正規化：大寫、去掉字元集外的符號（空白/破折號等）；
        // hash 是對 XXXXX-XXXXX 格式做的，剛好 10 碼時補回中間的破折號
        $code = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $code) ?? '');
        $blockLength = (int) config('two-factor.recovery.block_length', 5);
        if (strlen($code) === $blockLength * 2) {
            $code = substr($code, 0, $blockLength).'-'.substr($code, $blockLength);
        }

        foreach ($hashes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$index]);
                $user->two_factor_recovery_codes = array_values($hashes);
                $user->save();
                Cache::forget($failKey);

                return true;
            }
        }

        // add 只在 key 不存在時建立（同時設 TTL），increment 保留剩餘 TTL
        Cache::add($failKey, 0, (int) config('two-factor.recovery.redeem_failure_ttl', 3600));
        Cache::increment($failKey);

        return false;
    }

    private function randomCode(): string
    {
        $blocks = (int) config('two-factor.recovery.blocks', 2);
        $length = (int) config('two-factor.recovery.block_length', 5);

        $parts = [];
        for ($b = 0; $b < $blocks; $b++) {
            $part = '';
            for ($i = 0; $i < $length; $i++) {
                $part .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $parts[] = $part;
        }

        return implode('-', $parts);
    }
}
