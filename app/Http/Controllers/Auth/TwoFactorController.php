<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\RecoveryCodeService;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly RecoveryCodeService $recoveryCodes,
    ) {
    }

    /** 產生新 secret 進入 pending 狀態，回傳 otpauth URI 供前端畫 QR。 */
    public function enable(Request $request): JsonResponse
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
        $user->save();

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
     * 敏感操作憑證：password（RSA 加密送來，DecryptPasswordFields 已解密）或 TOTP code 擇一。
     * 純 Google 社群帳號可能無密碼，OTP 本身即第二因子持有證明。
     */
    private function verifyCredential(Request $request, User $user): bool
    {
        $request->validate([
            'password' => 'required_without:code|nullable|string',
            'code' => 'required_without:password|nullable|string',
        ]);

        $password = $request->string('password')->toString();
        if ($password !== '' && $user->password !== null) {
            return Hash::check($password, $user->password);
        }

        $code = $request->string('code')->toString();
        if ($code !== '' && $user->two_factor_secret !== null) {
            return $this->totp->verify($user->two_factor_secret, $code) !== null;
        }

        return false;
    }
}
