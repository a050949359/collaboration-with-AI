<?php

namespace App\Services\Auth;

use InvalidArgumentException;

/**
 * RFC 6238 TOTP（HMAC-SHA1、30s、6 位數，Google Authenticator 相容）。
 * 自刻零依賴，正確性由 RFC 6238 Appendix B 測試向量保證（tests/Unit/TotpServiceTest）。
 */
class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 產生 base32 編碼的隨機 secret（預設 20 bytes → 32 字元）。 */
    public function generateSecret(?int $bytes = null): string
    {
        $bytes ??= (int) config('two-factor.secret_bytes', 20);

        return $this->base32Encode(random_bytes($bytes));
    }

    /** 計算指定時間的 OTP。$digits 可覆寫（RFC 測試向量為 8 位數）。 */
    public function code(string $base32Secret, ?int $timestamp = null, ?int $digits = null): string
    {
        $digits ??= (int) config('two-factor.digits', 6);
        $period = (int) config('two-factor.period', 30);
        $counter = intdiv($timestamp ?? time(), $period);

        return $this->hotp($this->base32Decode($base32Secret), $counter, $digits);
    }

    /**
     * 驗證 OTP，允許 ±window 個 time step。
     * 回傳命中的 time step（供未來登入 challenge 做 replay 防護），未命中回傳 null。
     */
    public function verify(string $base32Secret, string $code, ?int $timestamp = null): ?int
    {
        $digits = (int) config('two-factor.digits', 6);
        $period = (int) config('two-factor.period', 30);
        $window = (int) config('two-factor.window', 1);

        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!ctype_digit($code) || strlen($code) !== $digits) {
            return null;
        }

        $key = $this->base32Decode($base32Secret);
        $currentStep = intdiv($timestamp ?? time(), $period);

        for ($i = -$window; $i <= $window; $i++) {
            $step = $currentStep + $i;
            if ($step >= 0 && hash_equals($this->hotp($key, $step, $digits), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** 組裝 otpauth:// URI 供 Authenticator App 掃描。 */
    public function otpauthUri(string $base32Secret, string $accountName, ?string $issuer = null): string
    {
        $issuer ??= (string) config('two-factor.issuer', 'Laravel');

        $query = http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => (int) config('two-factor.digits', 6),
            'period' => (int) config('two-factor.period', 30),
        ], '', '&', PHP_QUERY_RFC3986); // RFC3986：空白編成 %20，部分 App 對 + 解析錯誤

        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($accountName).'?'.$query;
    }

    public function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $bits = 0;
        $buffer = 0;
        $output = '';

        foreach (str_split($binary) as $char) {
            $buffer = ($buffer << 8) | ord($char);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $output .= self::BASE32_ALPHABET[($buffer >> $bits) & 0x1f];
            }
        }

        if ($bits > 0) {
            $output .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 0x1f];
        }

        return $output;
    }

    public function base32Decode(string $base32): string
    {
        $base32 = rtrim(strtoupper($base32), '=');
        if ($base32 === '') {
            return '';
        }

        $bits = 0;
        $buffer = 0;
        $output = '';

        foreach (str_split($base32) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                throw new InvalidArgumentException("Invalid base32 character: {$char}");
            }
            $buffer = ($buffer << 5) | $index;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xff);
            }
        }

        return $output;
    }

    /** RFC 4226 HOTP：counter 打包 8-byte big-endian → HMAC-SHA1 → dynamic truncation。 */
    private function hotp(string $key, int $counter, int $digits): string
    {
        $message = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $message, $key, true);

        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }
}
