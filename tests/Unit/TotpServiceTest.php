<?php

namespace Tests\Unit;

use App\Services\Auth\TotpService;
use InvalidArgumentException;
use Tests\TestCase;

class TotpServiceTest extends TestCase
{
    private TotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = new TotpService();
    }

    /** RFC 6238 Appendix B 測試向量（SHA1 列；seed 為 ASCII "12345678901234567890"，8 位數）。 */
    public function test_rfc6238_appendix_b_vectors(): void
    {
        $secret = $this->totp->base32Encode('12345678901234567890');

        $vectors = [
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ];

        foreach ($vectors as $timestamp => $expected) {
            $this->assertSame($expected, $this->totp->code($secret, $timestamp, digits: 8), "T={$timestamp}");
        }
    }

    /** RFC 4648 base32 測試向量。 */
    public function test_base32_encode_rfc4648_vectors(): void
    {
        $vectors = [
            '' => '',
            'f' => 'MY',
            'fo' => 'MZXQ',
            'foo' => 'MZXW6',
            'foob' => 'MZXW6YQ',
            'fooba' => 'MZXW6YTB',
            'foobar' => 'MZXW6YTBOI',
        ];

        foreach ($vectors as $plain => $encoded) {
            $this->assertSame($encoded, $this->totp->base32Encode($plain));
            $this->assertSame($plain, $this->totp->base32Decode($encoded));
        }
    }

    public function test_base32_decode_accepts_padding_and_lowercase(): void
    {
        $this->assertSame('foobar', $this->totp->base32Decode('MZXW6YTBOI======'));
        $this->assertSame('foobar', $this->totp->base32Decode('mzxw6ytboi'));
    }

    public function test_base32_decode_rejects_invalid_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->totp->base32Decode('MZXW6YTB01');
    }

    public function test_base32_roundtrip_random_bytes(): void
    {
        $binary = random_bytes(20);
        $this->assertSame($binary, $this->totp->base32Decode($this->totp->base32Encode($binary)));
    }

    public function test_generate_secret_format(): void
    {
        $secret = $this->totp->generateSecret();

        $this->assertSame(32, strlen($secret)); // 20 bytes → 32 base32 字元
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertNotSame($secret, $this->totp->generateSecret());
    }

    public function test_verify_accepts_codes_within_window(): void
    {
        config(['two-factor.window' => 1]);
        $secret = $this->totp->generateSecret();
        $now = 1_700_000_000;

        // 現在、前一步、後一步（±30s）都接受
        foreach ([0, -30, 30] as $drift) {
            $code = $this->totp->code($secret, $now + $drift);
            $this->assertNotNull($this->totp->verify($secret, $code, $now), "drift={$drift}");
        }

        // ±2 步（±60s）拒絕
        foreach ([-60, 60] as $drift) {
            $code = $this->totp->code($secret, $now + $drift);
            $this->assertNull($this->totp->verify($secret, $code, $now), "drift={$drift}");
        }
    }

    public function test_verify_returns_matched_timestep(): void
    {
        $secret = $this->totp->generateSecret();
        $now = 1_700_000_000;

        $step = $this->totp->verify($secret, $this->totp->code($secret, $now), $now);
        $this->assertSame(intdiv($now, 30), $step);
    }

    public function test_verify_normalizes_whitespace_and_rejects_bad_format(): void
    {
        $secret = $this->totp->generateSecret();
        $now = 1_700_000_000;
        $code = $this->totp->code($secret, $now);

        // 空白 normalize（使用者常複製到 "123 456"）
        $spaced = substr($code, 0, 3).' '.substr($code, 3);
        $this->assertNotNull($this->totp->verify($secret, $spaced, $now));

        $this->assertNull($this->totp->verify($secret, 'abcdef', $now));
        $this->assertNull($this->totp->verify($secret, '12345', $now));
        $this->assertNull($this->totp->verify($secret, '1234567', $now));
        $this->assertNull($this->totp->verify($secret, '', $now));
    }

    public function test_otpauth_uri_format_and_encoding(): void
    {
        $uri = $this->totp->otpauthUri('ABCDEFGH', 'user@example.com', 'My App');

        $this->assertStringStartsWith('otpauth://totp/My%20App:user%40example.com?', $uri);
        $this->assertStringContainsString('secret=ABCDEFGH', $uri);
        $this->assertStringContainsString('issuer=My%20App', $uri); // RFC3986：空白必須 %20 非 +
        $this->assertStringContainsString('algorithm=SHA1', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
