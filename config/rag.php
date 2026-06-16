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

    // vecgen 向量庫 CLI（chromem-go，非常駐，exec 呼叫）
    'vecgen' => [
        'bin' => env('VECGEN_BIN', base_path('cmd/vecgen/vecgen')),
        'db' => env('VECGEN_DB', storage_path('app/rag_db')),
    ],

    // 切塊：遞迴邊界切（段落>行>句>硬切）+ 小塊合併湊到 size（以「字元」計，中文友善）
    'chunk' => [
        'size' => (int) env('RAG_CHUNK_SIZE', 450),
        'overlap' => (int) env('RAG_CHUNK_OVERLAP', 60),
        // 小於此字數的塊盡量往相鄰塊合併，避免一行標題自成一塊
        'min' => (int) env('RAG_CHUNK_MIN', 120),
    ],

    // 編輯鎖租約秒數（預設 30 分），編輯/續租會延長
    'lock' => [
        'ttl' => (int) env('RAG_LOCK_TTL', 1800),
    ],

    // 近似重複：草稿塊兩兩 cosine 超過此門檻視為重複（警示用）
    'dedup' => [
        'threshold' => (float) env('RAG_DEDUP_THRESHOLD', 0.95),
    ],
];
