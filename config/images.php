<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 功能開關(預設全關,需明確開啟)
    |--------------------------------------------------------------------------
    |
    | enabled:總開關,關閉時所有 image 路由回 404(整個功能停用)。
    | 其餘為子開關,總開關開啟後才有意義;對應停用回 403:
    |   upload_enabled        — admin 檔案上傳(POST /images 帶 file)
    |   public_upload_enabled — 登入者 public 上傳(POST /images/public)
    |   url_download_enabled  — URL 下載(SSRF 面;service 層也會擋)
    |
    */
    'enabled' => (bool) env('IMAGE_ENABLED', false),
    'upload_enabled' => (bool) env('IMAGE_UPLOAD_ENABLED', false),
    'public_upload_enabled' => (bool) env('IMAGE_PUBLIC_UPLOAD_ENABLED', false),
    'url_download_enabled' => (bool) env('IMAGE_URL_DOWNLOAD_ENABLED', false),

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
    // 注意:接近上限的圖 GD 解碼會吃約 寬×高×4 bytes 記憶體(20MP ≈ 80MB),
    // 調高時請確認 PHP memory_limit 足夠,否則大圖會 OOM。
    'max_megapixels' => (int) env('IMAGE_MAX_MEGAPIXELS', 20),

    // 以「內容」判定的合法 MIME 白名單(不看副檔名)。刻意不含 gif/svg。
    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
    ],

    // webp 輸出品質(0-100)。
    'webp_quality' => (int) env('IMAGE_WEBP_QUALITY', 82),

    // public 資料夾(storage/app/public/images)檔案數上限。
    // public 端點開放給任一登入者,需防塞爆;達上限即拒新上傳。0 或負值 = 不限。
    // (用檔數而非 bytes:只列目錄、免逐檔 stat;每檔已受 max_bytes 限制)
    'public_max_files' => (int) env('IMAGE_PUBLIC_MAX_FILES', 10000),

    // public 計數 driver:'scan'(掃 FS,預設、零依賴)或 'redis'(shard hash,O(1) 取總和)。
    'public_count_driver' => env('IMAGE_PUBLIC_COUNT_DRIVER', 'scan'),
    // redis driver 用的 hash key(field = shard 2 碼、value = 該桶檔數)。
    'public_count_redis_key' => env('IMAGE_PUBLIC_COUNT_REDIS_KEY', 'image:public:shard_counts'),

    // URL 下載(SSRF 防護)相關。
    'download_timeout' => (int) env('IMAGE_DOWNLOAD_TIMEOUT', 15), // 秒
    'max_redirects' => (int) env('IMAGE_MAX_REDIRECTS', 3),
    // 下載用 UA:預設 Guzzle UA 常被 CDN/圖床當爬蟲擋,給個瀏覽器樣式提高相容性。
    'download_user_agent' => env(
        'IMAGE_DOWNLOAD_USER_AGENT',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ),
];
