<?php

namespace App\Services\Image;

use App\Enums\ImageVisibility;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 本地圖片儲存 pipeline。
 *
 * 三種來源(拖曳上傳 / AI 生成 binary / URL 下載)全部 funnel 進 private 的 store(),
 * 統一做安全閘 + 強制 GD re-encode 成 webp,再存 private disk。
 *
 * 安全核心:不「掃描」圖片找壞東西,而是用 GD 重新解碼像素再編碼成 webp ——
 * EXIF 藏的 code、檔尾接的 polyglot/PHP、註解區 payload 全部在 re-encode 時被丟棄。
 */
class ImageIngestService
{
    public function __construct(private readonly PublicImageCounter $counter) {}

    /**
     * 拖曳上傳:從 UploadedFile 取 bytes。
     *
     * @return string 不可猜的圖片 id(uuid,不含副檔名)
     */
    public function fromUpload(UploadedFile $file, ImageVisibility $visibility = ImageVisibility::Private): string
    {
        $bytes = @file_get_contents($file->getRealPath());

        if (! is_string($bytes) || $bytes === '') {
            throw new ImageRejectedException('Uploaded file is empty or unreadable.');
        }

        return $this->store($bytes, $visibility);
    }

    /**
     * AI 生成端:直接給 binary。
     *
     * @return string 不可猜的圖片 id
     */
    public function fromBinary(string $bytes, ImageVisibility $visibility = ImageVisibility::Private): string
    {
        return $this->store($bytes, $visibility);
    }

    /**
     * URL 下載:先過 SSRF 閘抓 bytes 再 store。
     *
     * @return string 不可猜的圖片 id
     */
    public function fromUrl(string $url, ImageVisibility $visibility = ImageVisibility::Private): string
    {
        return $this->store($this->fetchRemote($url), $visibility);
    }

    /**
     * 共同 pipeline:安全閘 → re-encode webp → 依 visibility 存對應 disk。
     *
     * @return string 圖片 id(uuid)
     */
    private function store(string $bytes, ImageVisibility $visibility): string
    {
        // 1. 大小閘
        $maxBytes = (int) config('images.max_bytes');
        if (strlen($bytes) > $maxBytes) {
            throw new ImageRejectedException('Image exceeds the maximum allowed size.');
        }

        // 2. 真實 MIME(看內容,不看副檔名)
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $allowed = (array) config('images.allowed_mimes');
        if (! is_string($mime) || ! in_array($mime, $allowed, true)) {
            throw new ImageRejectedException('Unsupported or undetectable image type.');
        }

        // 3. 尺寸閘(decompression bomb)
        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            throw new ImageRejectedException('Image dimensions could not be read.');
        }
        $maxPixels = (int) config('images.max_megapixels') * 1_000_000;
        if (($size[0] * $size[1]) > $maxPixels) {
            throw new ImageRejectedException('Image resolution exceeds the allowed limit.');
        }

        // 3.5 public 資料夾檔數上限(public 開放給任一登入者,需防塞爆);
        //     放在 re-encode 前,超限就 fail fast、不浪費編碼。
        if ($visibility === ImageVisibility::Public) {
            $this->assertPublicFileLimit();
        }

        // 4. 重新編碼(核心防線):只保留像素,丟棄一切附加資料
        $webp = $this->reencodeToWebp($bytes);

        // 5. 存檔(uuid 不可猜檔名,前綴分桶避免單一資料夾過大),disk 由 visibility 決定
        $id = Str::uuid()->toString();
        $path = self::pathFor($id);
        $disk = (string) config('images.disks.'.$visibility->value);

        // 明示 visibility,讓檔案權限(private→0640 / public→0644,見 filesystems.php)
        // 由本 pipeline 宣告,不依賴 disk 預設。
        if (Storage::disk($disk)->put($path, $webp, $visibility->value) !== true) {
            throw new ImageRejectedException('Failed to persist image.');
        }

        if ($visibility === ImageVisibility::Public) {
            $this->counter->added($id);
        }

        return $id;
    }

    /**
     * 由圖片 id(uuid)推出 disk 內相對路徑,前 2 碼分桶(256 桶)避免單一資料夾過大。
     * store / show / urlFor 共用此單一來源,id 即唯一 token,不需另存路徑。
     */
    public static function pathFor(string $id): string
    {
        $dir = trim((string) config('images.directory'), '/');

        return $dir.'/'.substr($id, 0, 2).'/'.$id.'.webp';
    }

    /**
     * public 資料夾檔數上限:現有檔數已達上限就拒新上傳。上限 <= 0 視為不限。
     * 計數委派 PublicImageCounter(scan 直接掃 FS / redis 走 shard hash)。
     */
    private function assertPublicFileLimit(): void
    {
        $cap = (int) config('images.public_max_files');
        if ($cap <= 0) {
            return;
        }

        if ($this->counter->total() >= $cap) {
            throw new ImageRejectedException('Public image count limit reached.');
        }
    }

    /**
     * 用 GD 解碼像素後重編成 webp,回傳 webp bytes。
     * 任何無法被 GD 解出像素的內容(偽圖、損毀)在這裡就會被拒。
     */
    private function reencodeToWebp(string $bytes): string
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            throw new ImageRejectedException('Image could not be decoded.');
        }

        // 保留透明度(png → webp)
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $ok = imagewebp($image, null, (int) config('images.webp_quality'));
        $webp = (string) ob_get_clean();
        imagedestroy($image);

        if ($ok === false || $webp === '') {
            throw new ImageRejectedException('Failed to encode image to webp.');
        }

        return $webp;
    }

    /**
     * SSRF 防護下的遠端下載:
     *  - 只允許 http(s);每一跳都解析並擋私網/保留段 IP(IPv4 + IPv6)
     *  - 把驗證過的 IP 用 CURLOPT_RESOLVE pin 給 curl,避免「驗證後再解析」的 DNS rebinding(TOCTOU)
     *  - 串流邊讀邊累加,超過 max_bytes 立即中斷(防 OOM DoS)
     */
    private function fetchRemote(string $url): string
    {
        $maxRedirects = (int) config('images.max_redirects');
        $timeout = (int) config('images.download_timeout');
        $maxBytes = (int) config('images.max_bytes');

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            // 驗證 scheme + IP 安全,並取回要 pin 的目標(host/port/ip)
            ['host' => $host, 'port' => $port, 'ip' => $ip] = $this->resolveSafeTarget($url);
            $pinnedIp = str_contains($ip, ':') ? "[$ip]" : $ip; // IPv6 需用中括號

            $response = Http::timeout($timeout)
                ->withOptions([
                    'allow_redirects' => false, // 自己處理,以便每一跳重驗 IP
                    'stream' => true,           // 串流,避免整包進記憶體
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinnedIp}"]],
                ])
                ->get($url);

            // 手動跟隨 redirect,並重驗下一跳的 IP
            if ($response->redirect()) {
                $location = (string) $response->header('Location');
                if ($location === '') {
                    throw new ImageRejectedException('Redirect without a location.');
                }
                $url = $this->resolveRedirectUrl($url, $location);

                continue;
            }

            if (! $response->successful()) {
                throw new ImageRejectedException('Remote image fetch failed.');
            }

            // Content-Length 若已超標,先擋(省下載)
            $declared = (int) $response->header('Content-Length');
            if ($declared > $maxBytes) {
                throw new ImageRejectedException('Remote image exceeds the maximum allowed size.');
            }

            // 串流讀取,邊累加邊檢查上限
            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            while (! $stream->eof()) {
                $body .= $stream->read(8192);
                if (strlen($body) > $maxBytes) {
                    throw new ImageRejectedException('Remote image exceeds the maximum allowed size.');
                }
            }

            if ($body === '') {
                throw new ImageRejectedException('Remote image was empty.');
            }

            return $body;
        }

        throw new ImageRejectedException('Too many redirects while fetching the image.');
    }

    /**
     * 驗證 URL 的 scheme 與解析後的所有 IP(IPv4 A + IPv6 AAAA)是否安全,
     * 回傳 ['host','port','ip'] —— ip 為已驗證安全、供 pin 用的位址。
     *
     * @return array{host: string, port: int, ip: string}
     */
    private function resolveSafeTarget(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new ImageRejectedException('Only http(s) image URLs are allowed.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = $this->resolveHostIps($host);

        foreach ($ips as $ip) {
            $public = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
            if ($public === false) {
                throw new ImageRejectedException('Image host resolves to a disallowed address.');
            }
        }

        // 全部 IP 皆已驗證安全,pin 第一個
        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * 解析 host 的所有 IP(A + AAAA);host 本身是 IP literal 則直接回。
     *
     * @return array<int, string>
     */
    private function resolveHostIps(string $host): array
    {
        // IPv6 literal URL 的 host 帶中括號,去掉再驗
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            return [$literal];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];        // A
                } elseif (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];      // AAAA
                }
            }
        }

        if ($ips === []) {
            $ips = gethostbynamel($host) ?: []; // 後備(僅 IPv4)
        }

        if ($ips === []) {
            throw new ImageRejectedException('Image host could not be resolved.');
        }

        return $ips;
    }

    /**
     * 把 redirect 的 Location(可能是相對路徑)解析成絕對 URL。
     */
    private function resolveRedirectUrl(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        // protocol-relative(//host/path):繼承 scheme,host 換成 Location 指定的
        // —— 必須在單斜線判斷之前攔,否則會被誤當成原 host 的路徑。
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$host.$port.$location;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $scheme.'://'.$host.$port.$dir.$location;
    }
}
