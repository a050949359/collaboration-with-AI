<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 本地圖片儲存設定
    |--------------------------------------------------------------------------
    |
    | 三來源(拖曳上傳 / AI 生成 binary / URL 下載)統一進 ImageIngestService,
    | 強制 GD re-encode 成 webp 後存 private disk,以不可猜的 uuid 檔名 + 登入鑑權
    | 出圖。以下為各道安全閘與編碼的限制值。
    |
    */

    // 兩條 visibility 路線對應的 disk:
    //  - public:正常展示素材(gacha 卡圖等),可由 /storage URL 直接存取
    //  - private:個人敏感 / NSFW,須登入經鑑權 controller 出圖
    'disks' => [
        'public' => 'public',
        'private' => 'private',
    ],

    // 未指定時的預設 visibility(偏保守:預設私有)
    'default_visibility' => 'private',

    'directory' => 'images',

    // 原始輸入大小上限(bytes)。超過直接拒,避免塞爆磁碟 / DoS。
    'max_bytes' => (int) env('IMAGE_MAX_BYTES', 10 * 1024 * 1024), // 10 MB

    // 解析後像素總量上限(百萬像素),擋 decompression bomb。
    'max_megapixels' => (int) env('IMAGE_MAX_MEGAPIXELS', 50),

    // 以「內容」判定的合法 MIME 白名單(不看副檔名)。刻意不含 gif/svg。
    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
    ],

    // webp 輸出品質(0-100)。
    'webp_quality' => (int) env('IMAGE_WEBP_QUALITY', 82),

    // URL 下載(SSRF 防護)相關。
    'download_timeout' => (int) env('IMAGE_DOWNLOAD_TIMEOUT', 15), // 秒
    'max_redirects' => (int) env('IMAGE_MAX_REDIRECTS', 3),
];
