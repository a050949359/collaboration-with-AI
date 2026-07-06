<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\RecoveryCodeService;
use App\Services\Auth\TotpService;
use App\Services\Auth\TwoFactorChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly RecoveryCodeService $recoveryCodes,
        private readonly TwoFactorChallengeService $challenges,
    ) {
    }

    /**
     * 登入二階段：憑 challenge token + OTP/備援碼換取正式 token。
     * 公開路由（此時尚未登入），challenge token 即身分證明。
     */
    public function challenge(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_token' => 'required|string',
            'code' => 'required|string',
        ]);

        $token = $request->string('challenge_token')->toString();
        $data = $this->challenges->get($token);
        $user = $data !== null ? User::find($data['user_id']) : null;

        if ($user === null) {
            $this->challenges->consume($token);

            return response()->json(['message' => '驗證階段已過期，請重新登入'], 401);
        }

        // challenge 期間被停用 2FA：密碼已驗過，視同通過
        $passed = !$user->two_factor_enabled
            || $this->verifyChallengeCode($user, $request->string('code')->toString());

        if (!$passed) {
            if ($this->challenges->recordFailure($token)) {
                return response()->json(['message' => '嘗試次數過多，請重新登入'], 401);
            }

            return response()->json(['errors' => ['code' => ['驗證碼不正確']]], 422);
        }

        $this->challenges->consume($token);

        return $this->issueToken($user, (bool) $data['remember'], $data['device_id'], $data['device_name']);
    }

    /** 含 '-' 或去空白後超過 6 碼視為備援碼（XXXXX-XXXXX），其餘走 TOTP。 */
    private function verifyChallengeCode(User $user, string $code): bool
    {
        $compact = preg_replace('/\s+/', '', $code) ?? '';

        if (str_contains($compact, '-') || strlen($compact) > 6) {
            return $this->recoveryCodes->redeem($user, $code);
        }

        return $this->challenges->verifyTotpOnce($user, $code);
    }

    /** 發正式 token + auth_token cookie（與 LoginController 成功路徑同款）。 */
    private function issueToken(User $user, bool $remember, ?string $deviceId, ?string $deviceName): JsonResponse
    {
        if ($deviceId) {
            $user->tokens()->where('device_id', $deviceId)->delete();
        } else {
            $user->tokens()->where('name', 'web')->whereNull('device_id')->delete();
        }

        $tokenName = $deviceName ?? ($deviceId ? 'mobile' : 'web');
        $plainText = $user->createToken($tokenName, deviceId: $deviceId)->plainTextToken;
        $minutes = $remember ? 60 * 24 * 7 : 0;

        return response()->json([
            'message' => '登入成功',
            'user' => $user,
            'access_token' => $plainText,
            'token_type' => 'Bearer',
            'redirect' => route('home'),
        ])->cookie('auth_token', $plainText, $minutes, '/', null, app()->isProduction(), true, false, 'Lax');
    }

    /** 產生新 secret 進入 pending 狀態，回傳 otpauth URI 供前端畫 QR。 */
    public function enable(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->two_factor_enabled) {
            return response()->json(['message' => '兩步驟驗證已啟用，如需重新綁定請先停用'], 422);
        }

        // pending 狀態重複呼叫直接覆寫新 secret（舊 QR 作廢），對「重掃一次」的重試友善
        $secret = $this->totp->generateSecret();
        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $this->totp->otpauthUri($secret, $user->email),
        ]);
    }

    /** 以 OTP 確認綁定成功，正式啟用並回傳一次性備援碼。 */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        /** @var User $user */
        $user = Auth::user();

        if ($user->two_factor_enabled) {
            return response()->json(['message' => '兩步驟驗證已啟用'], 422);
        }

        if (!$user->hasPendingTwoFactor()) {
            return response()->json(['message' => '請先產生驗證金鑰'], 422);
        }

        if ($this->totp->verify($user->two_factor_secret, $request->string('code')->toString()) === null) {
            return response()->json(['errors' => ['code' => ['驗證碼不正確']]], 422);
        }

        $user->two_factor_confirmed_at = now();

        // generateFor 內部的 save 會一併寫入 confirmed_at（同一物件），合併為一次寫入
        $codes = $this->recoveryCodes->generateFor($user);

        return response()->json([
            'message' => '兩步驟驗證已啟用',
            'recovery_codes' => $codes,
            'user' => $user->fresh(),
        ]);
    }

    /** 停用：pending 免憑證（secret 未生效）；已啟用需密碼或 OTP 擇一。 */
    public function disable(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->two_factor_secret === null) {
            return response()->json(['message' => '兩步驟驗證未啟用'], 422);
        }

        if ($user->two_factor_enabled && !$this->verifyCredential($request, $user)) {
            return response()->json(['errors' => ['credential' => ['密碼或驗證碼不正確']]], 422);
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return response()->json([
            'message' => '兩步驟驗證已停用',
            'user' => $user->fresh(),
        ]);
    }

    /** 重新產生一批備援碼（舊碼全數作廢），需密碼或 OTP 擇一。 */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user->two_factor_enabled) {
            return response()->json(['message' => '兩步驟驗證未啟用'], 422);
        }

        if (!$this->verifyCredential($request, $user)) {
            return response()->json(['errors' => ['credential' => ['密碼或驗證碼不正確']]], 422);
        }

        return response()->json(['recovery_codes' => $this->recoveryCodes->generateFor($user)]);
    }

    /**
     * 敏感操作憑證：password（RSA 加密送來，DecryptPasswordFields 已解密）、
     * TOTP code 或備援碼，擇一有效即可。
     * 純 Google 社群帳號可能無密碼；手機遺失者只剩備援碼可證明身分
     * （否則無密碼 + 無手機的使用者將永遠無法停用 2FA 重新綁定）。
     */
    private function verifyCredential(Request $request, User $user): bool
    {
        $request->validate([
            'password' => 'required_without:code|nullable|string',
            'code' => 'required_without:password|nullable|string',
        ]);

        $password = $request->string('password')->toString();
        if ($password !== '' && $user->password !== null && Hash::check($password, $user->password)) {
            return true;
        }

        // 密碼未提供或不符時 fallback 驗 code：擇一有效即可（密碼管理器可能同時自動填入兩欄）
        $code = $request->string('code')->toString();
        if ($code !== '' && $user->two_factor_secret !== null) {
            $compact = preg_replace('/\s+/', '', $code) ?? '';

            // 含 '-' 或超過 6 碼視為備援碼（XXXXX-XXXXX），其餘走 TOTP
            if (str_contains($compact, '-') || strlen($compact) > 6) {
                return $this->recoveryCodes->redeem($user, $compact);
            }

            return $this->totp->verify($user->two_factor_secret, $compact) !== null;
        }

        return false;
    }
}
