<?php

namespace App\Services\AI\Gemini;

use App\Enums\ArticleAspectRatio;
use App\Enums\ImageVisibility;
use App\Services\AI\AIServiceException;
use App\Services\AI\Contracts\GeneratesImage;
use App\Services\Image\ImageIngestService;
use App\Services\Image\ImageRejectedException;
use App\Support\AppSettings;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Key-based(AI Studio / generativelanguage)的共用圖片生成(GeneratesImage)。
 *
 * 用 GEMINI_API_KEY 走 v1beta 的 Imagen :predict（generativelanguage 端點），query 帶 key、
 * 支援 GEMINI_PROXY 出口代理(AI Studio 地區限制)。請求/回應格式同 Vertex 的
 * instances/parameters → predictions[].bytesBase64Encoded，差別僅在認證走 key 而非 GCP token。特性:
 *   - 不需 GCP 服務帳號 / project_id,只要一把 key(與需要 GCP 的 Vertex 文章那條獨立)。
 *   - 產出 binary 不自行落地,一律 funnel 進 ImageIngestService::fromBinary() ——
 *     強制 GD re-encode 成 webp(剝除任何附加 payload)後存 public disk,路徑/檔名
 *     由該 pipeline 統一管理(uuid 分桶),故 $directory 參數在此不影響落地路徑。
 *
 * model 由 config('services.gemini.image_model')(env GEMINI_IMAGE_MODEL)決定,
 * 未設定時丟例外(避免打到不存在的 endpoint)。
 */
class GeminiImageGenerationService implements GeneratesImage
{
    /** @var array<int, string> */
    private array $allowedAspectRatios;

    public function __construct(private readonly ImageIngestService $images)
    {
        $this->allowedAspectRatios = array_column(ArticleAspectRatio::cases(), 'value');
    }

    public function generate(string $prompt, string $directory = 'images', string $aspectRatio = ArticleAspectRatio::R1x1->value): array
    {
        $apiKey = (string) config('services.gemini.api_key', '');
        if ($apiKey === '') {
            throw new AIServiceException('GEMINI_API_KEY is not configured.');
        }

        // model 解析:System 頁 runtime 設定(admin_settings.image.model)優先,
        // 空則退回 env 預設 GEMINI_IMAGE_MODEL；皆空才報錯。
        $imageSettings = AppSettings::get('image', []);
        $model = is_array($imageSettings) ? trim((string) ($imageSettings['model'] ?? '')) : '';
        if ($model === '') {
            $model = (string) config('services.gemini.image_model', '');
        }
        if ($model === '') {
            throw new AIServiceException('No image generation model configured (set it in System settings or GEMINI_IMAGE_MODEL).');
        }

        $prompt = $this->sanitizeUtf8($prompt);
        $aspectRatio = in_array($aspectRatio, $this->allowedAspectRatios, true)
            ? $aspectRatio
            : ArticleAspectRatio::R1x1->value;

        // Imagen（key 帳號可用的圖片模型）走 :predict，格式同 Vertex 的 instances/parameters。
        $body = [
            'instances' => [
                ['prompt' => $prompt],
            ],
            'parameters' => [
                'sampleCount' => 1,
                'aspectRatio' => $aspectRatio,
            ],
        ];

        $response = Http::withQueryParameters(['key' => $apiKey])
            ->acceptJson()
            ->when(config('services.gemini.proxy'), fn ($req, $proxy) => $req->withOptions(['proxy' => $proxy]))
            ->timeout(180)
            ->post($this->endpointForModel($model), $body);

        Log::debug('GeminiImageGenerationService response', [
            'status' => $response->status(),
            'model' => $model,
        ]);

        if (! $response->ok()) {
            throw new AIServiceException('Gemini image generation failed: '.$response->status().' (model: '.$model.')');
        }

        $base64Image = $this->extractBase64Image($response->json());
        if ($base64Image === '') {
            throw new AIServiceException('Gemini image output was empty.');
        }

        $binary = base64_decode($base64Image, true);
        if (! is_string($binary) || $binary === '') {
            throw new AIServiceException('Failed to decode Gemini image output.');
        }

        // 統一進 webp pipeline:re-encode 剝 payload、存 public disk(uuid 分桶檔名)。
        try {
            $id = $this->images->fromBinary($binary, ImageVisibility::Public);
        } catch (ImageRejectedException $e) {
            throw new AIServiceException('Generated image was rejected by the ingest pipeline: '.$e->getMessage());
        }

        $path = ImageIngestService::pathFor($id);

        // public disk 的 url() 在具體 FilesystemAdapter 上(Filesystem interface 無 url()),先 narrow。
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('images.disks.public', 'public'));

        return [
            'image_path' => $path,
            'image_url' => $disk->url($path),
        ];
    }

    private function endpointForModel(string $model): string
    {
        return sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:predict',
            $model,
        );
    }

    /**
     * 從 Imagen :predict 回應取出第一張圖的 base64。
     * 主鍵為 bytesBase64Encoded;不同版本/相容欄位再試 imageBase64 / b64_json。
     */
    private function extractBase64Image(mixed $payload): string
    {
        if (! is_array($payload)) {
            return '';
        }

        $prediction = $payload['predictions'][0] ?? null;
        if (! is_array($prediction)) {
            return '';
        }

        foreach (['bytesBase64Encoded', 'imageBase64', 'b64_json'] as $key) {
            $data = $prediction[$key] ?? null;
            if (is_string($data) && $data !== '') {
                return $data;
            }
        }

        return '';
    }

    private function sanitizeUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);

        if ($converted === false) {
            return '';
        }

        return $converted;
    }
}
