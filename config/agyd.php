<?php

return [
    'url'    => env('AGYD_URL', ''),
    'secret' => env('AGYD_SECRET', ''),

    // 接收 ZIP 後存放的根目錄（相對 storage/app/public）
    'storage_path' => env('AGYD_STORAGE_PATH', 'agy'),
];
