<?php

return [
    'url'    => env('AGYD_URL', ''),
    'secret' => env('AGYD_SECRET', ''),

    // 接收 ZIP 後存放的根目錄（相對 storage/app/public）
    'storage_path' => env('AGYD_STORAGE_PATH', 'agy'),

    // 解壓前最低剩餘空間（MB）；低於此值直接拒絕
    'min_free_mb' => (int) env('AGYD_MIN_FREE_MB', 10240),
];
