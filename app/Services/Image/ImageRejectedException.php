<?php

namespace App\Services\Image;

use RuntimeException;

/**
 * 圖片被安全閘擋下(過大、MIME 不合、解碼失敗、SSRF 等)時拋出。
 * Controller 會把它轉成 422。
 */
class ImageRejectedException extends RuntimeException {}
