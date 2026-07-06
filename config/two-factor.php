<?php

return [
    // otpauth URI 顯示在 Authenticator App 裡的發行者名稱
    'issuer' => env('TWO_FACTOR_ISSUER', env('APP_NAME', 'Laravel')),

    // TOTP 參數（RFC 6238；Google Authenticator 相容組合，勿隨意更動）
    'digits' => 6,
    'period' => 30,

    // 驗證時允許的前後 time step 數（1 = 接受前後各 30 秒的碼）
    'window' => (int) env('TWO_FACTOR_WINDOW', 1),

    // secret 長度（bytes）；RFC 4226 建議 160-bit
    'secret_bytes' => 20,

    // 一次性備援碼
    'recovery' => [
        'count' => 8,
        'block_length' => 5,
        'blocks' => 2, // 格式 XXXXX-XXXXX
    ],
];
