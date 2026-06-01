<?php

namespace App\Services\AI;

use App\Enums\ArticleAspectRatio;
use App\Services\AI\Contracts\GeneratesArticleImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class VertexImageGenerationService implements GeneratesArticleImage
{
    /** @var array<int, string> */
    private array $allowedAspectRatios;

    public function __construct(private readonly GcpAccessTokenProvider $tokenProvider)
    {
        $this->allowedAspectRatios = array_column(ArticleAspectRatio::cases(), 'value');
    }

    public function generate(string $prompt, string $directory = 'articles', string $aspectRatio = '1:1'): array
    {
        $projectId = (string) config('services.vertex_ai.project_id');
        $location = (string) config('services.vertex_ai.location', 'us-central1');
        $model = (string) config('services.vertex_ai.image_model', 'imagen-4.0-generate-001');

        if ($projectId === '') {
            throw new AIServiceException('GCP_PROJECT_ID is required for image generation.');
        }

        $endpoint = sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:predict',
            $location,
            $projectId,
            $location,
            $model,
        );

        $prompt = $this->sanitizeUtf8($prompt);
        $aspectRatio = in_array($aspectRatio, $this->allowedAspectRatios, true) ? $aspectRatio : ArticleAspectRatio::R1x1->value;

        $payload = [
            'instances' => [
                [
                    'prompt' => $prompt,
                ],
            ],
            'parameters' => [
                'sampleCount' => 1,
                'aspectRatio' => $aspectRatio,
            ],
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if (!is_string($json)) {
            throw new AIServiceException('Failed to encode image generation payload.');
        }

        $response = Http::withToken($this->tokenProvider->getToken())
            ->acceptJson()
            ->timeout(90)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->withBody($json, 'application/json')
            ->post($endpoint);

        if (!$response->ok()) {
            throw new AIServiceException('Vertex image generation failed.');
        }

        $payload = $response->json();
        $base64Image = $this->extractBase64Image($payload);

        if ($base64Image === '') {
            throw new AIServiceException('Vertex image output was empty.');
        }

        $binary = base64_decode($base64Image, true);

        if (!is_string($binary) || $binary === '') {
            throw new AIServiceException('Failed to decode Vertex image output.');
        }

        $filename = Str::uuid()->toString().'.png';
        $path = trim($directory, '/').'/'.$filename;

        $written = Storage::disk('public')->put($path, $binary);

        if ($written === true && Storage::disk('public')->exists($path)) {
            return [
                'image_path' => $path,
                'image_url' => Storage::disk('public')->url($path),
            ];
        }

        $tmpPath = $this->persistToTmp($binary);

        Log::warning('Public storage write failed, image persisted to /tmp fallback.', [
            'public_path' => $path,
            'tmp_path' => $tmpPath,
        ]);

        return [
            'image_path' => $tmpPath,
            'image_url' => '',
        ];
    }

    private function persistToTmp(string $binary): string
    {
        $baseDir = '/tmp/collaboration-with-ai-images';

        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            throw new AIServiceException('Failed to create /tmp fallback directory.');
        }

        $tmpPath = $baseDir.'/'.Str::uuid()->toString().'.png';
        $bytes = @file_put_contents($tmpPath, $binary);

        if (!is_int($bytes) || $bytes <= 0 || !is_file($tmpPath)) {
            throw new AIServiceException('Failed to persist generated image to storage and /tmp fallback.');
        }

        return $tmpPath;
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

    /**
     * @param mixed $payload
     */
    private function extractBase64Image(mixed $payload): string
    {
        if (!is_array($payload)) {
            return '';
        }

        $predictions = $payload['predictions'] ?? null;

        if (!is_array($predictions) || !isset($predictions[0]) || !is_array($predictions[0])) {
            return '';
        }

        $prediction = $predictions[0];

        foreach (['bytesBase64Encoded', 'imageBase64', 'b64_json'] as $key) {
            if (isset($prediction[$key]) && is_string($prediction[$key]) && $prediction[$key] !== '') {
                return $prediction[$key];
            }
        }

        return '';
    }
}
