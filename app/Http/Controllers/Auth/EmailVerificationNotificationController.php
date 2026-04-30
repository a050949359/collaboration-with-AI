<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    public function store(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['success' => true, 'message' => '電子郵件已驗證']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['success' => true, 'message' => '驗證信已寄出']);
    }
}
