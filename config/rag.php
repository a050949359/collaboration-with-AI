<?php

return [
    // Google Drive 來源（用專用 service account 讀分享資料夾）
    'drive' => [
        // SA 金鑰路徑（gitignore，見 storage/app/.gitignore）
        'credentials_path' => env('RAG_DRIVE_CREDENTIALS_PATH', storage_path('app/rag-drive-sa.json')),
        // 分享給 SA 的資料夾 ID（網址 /folders/<id>）
        'folder_id' => env('RAG_DRIVE_FOLDER_ID', ''),
        'scope' => 'https://www.googleapis.com/auth/drive.readonly',
    ],

    // ragctl 向量庫 CLI（非常駐，exec 呼叫）
    'ragctl' => [
        'bin' => env('RAGCTL_BIN', base_path('cmd/ragctl/ragctl')),
        'db' => env('RAGCTL_DB', storage_path('app/rag_db')),
    ],
];
