<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;

class VerifyEmailController extends Controller
{
    public function __invoke(EmailVerificationRequest $request)
    {
        // 已驗證過
        if ($request->user()->hasVerifiedEmail()) {
            return redirect('/app/verify-result?status=already');
        }

        try {
            $request->fulfill(); // 標記已驗證 + 觸發 Verified 事件
        } catch (\Exception $e) {
            // 可能過期或 hash 錯誤
            return redirect('/app/verify-result?status=error');
        }

        return redirect('/app/verify-result?status=success');
    }
}
