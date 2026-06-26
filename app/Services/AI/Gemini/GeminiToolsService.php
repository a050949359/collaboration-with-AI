<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\AIServiceException;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gemini tools（grounding）：以 generateContent + tools 讓模型把答案接地到外部資料源。
 *
 * 刻意「不」走 ChatCompletion 抽象層——抽象層回純文字、多 provider；grounding 的
 * 價值在回應的 groundingMetadata（引用來源、搜尋查詢、地圖 widget token），需保留結構。
 *
 * 兩個 tool 共用 generateContent 骨架，只差 tools 與要抽哪些 metadata：
 *   - searchGrounded：tools:[{google_search:{}}]    一般免費 key 可用。
 *   - mapGrounded：   tools:[{google_maps:{}}]      preview，可能需付費方案／Maps key。
 *
 * model：options['model'] → config('services.gemini.{search,map}_model')。
 */
class GeminiToolsService
{
    private string $apiKey;

    private string $searchModel;

    private string $mapModel;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.api_key', '');

        // model：admin_settings('tools').{search_model,map_model} 優先 → 退回 config('services.gemini.{search,map}_model')。
        $settings = AppSettings::get('tools', []);
        $settings = is_array($settings) ? $settings : [];
        $this->searchModel = ((string) ($settings['search_model'] ?? '')) ?: (string) config('services.gemini.search_model');
        $this->mapModel = ((string) ($settings['map_model'] ?? '')) ?: (string) config('services.gemini.map_model');
    }

    /**
     * Google Search grounding：答案 + 引用來源 + 搜尋查詢 + search-entry-point HTML。
     *
     * @param  array{model?: string, system?: string, temperature?: float, max_tokens?: int}  $options
     * @return array{text: string, citations: array<int, array{title: string, uri: string, snippet: string}>, search_queries: array<int, string>, search_entry_point: string, model: string}
     */
    public function searchGrounded(string $query, array $options = []): array
    {
        $model = ((string) ($options['model'] ?? '')) ?: $this->searchModel;
        $payload = $this->generate($model, [['google_search' => new \stdClass]], $query, $options);

        return $this->shapeCommon($payload, $model);
    }

    /**
     * Google Maps grounding：答案 + 引用地點 + 可嵌入互動地圖的 widget token。
     * 可選 lat/lng（options['lat'], options['lng']）把結果偏向某地理位置。
     *
     * @param  array{model?: string, system?: string, temperature?: float, max_tokens?: int, lat?: float, lng?: float}  $options
     * @return array{text: string, citations: array<int, array{title: string, uri: string, snippet: string}>, search_queries: array<int, string>, search_entry_point: string, model: string, map_widget_token: string, places: array<int, array<string, mixed>>}
     */
    public function mapGrounded(string $query, array $options = []): array
    {
        $extra = [];
        if (isset($options['lat'], $options['lng'])) {
            $extra['toolConfig'] = [
                'retrievalConfig' => [
                    'latLng' => [
                        'latitude' => (float) $options['lat'],
                        'longitude' => (float) $options['lng'],
                    ],
                ],
            ];
        }

        $model = ((string) ($options['model'] ?? '')) ?: $this->mapModel;
        $payload = $this->generate($model, [['google_maps' => new \stdClass]], $query, $options, $extra);

        $common = $this->shapeCommon($payload, $model);

        $meta = data_get($payload, 'candidates.0.groundingMetadata', []);

        return $common + [
            'map_widget_token' => (string) data_get($meta, 'googleMapsWidgetContextToken', ''),
            'places' => $this->extractPlaces(is_array($meta) ? $meta : []),
        ];
    }

    /**
     * 共用的 generateContent 呼叫。回傳解碼後的 payload 陣列。
     *
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $extra  併入 body 的額外鍵（如 toolConfig）
     * @return array<string, mixed>
     */
    private function generate(string $model, array $tools, string $query, array $options, array $extra = []): array
    {
        if ($this->apiKey === '') {
            throw new AIServiceException('GEMINI_API_KEY is not configured.');
        }

        $body = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $query]]],
            ],
            'tools' => $tools,
        ] + $extra;

        if (! empty($options['system'])) {
            $body['system_instruction'] = ['parts' => [['text' => (string) $options['system']]]];
        }

        $generationConfig = [];
        if (isset($options['temperature'])) {
            $generationConfig['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $generationConfig['maxOutputTokens'] = (int) $options['max_tokens'];
        }
        if ($generationConfig !== []) {
            $body['generationConfig'] = $generationConfig;
        }

        $response = Http::withQueryParameters(['key' => $this->apiKey])
            ->acceptJson()
            ->when(config('services.gemini.proxy'), fn ($req, $proxy) => $req->withOptions(['proxy' => $proxy]))
            ->timeout(180)
            ->post($this->endpointForModel($model), $body);

        Log::debug('GeminiToolsService response', [
            'status' => $response->status(),
            'model' => $model,
            'tool' => array_key_first($tools[0] ?? []),
        ]);

        if (! $response->ok()) {
            throw new AIServiceException('Gemini tools request failed: '.$response->status().' (model: '.$model.')');
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * 抽出 search/map 共通的欄位：text、citations、search_queries、search_entry_point。
     *
     * @param  array<string, mixed>  $payload
     * @return array{text: string, citations: array<int, array{title: string, uri: string, snippet: string}>, search_queries: array<int, string>, search_entry_point: string, model: string}
     */
    private function shapeCommon(array $payload, string $model): array
    {
        $meta = data_get($payload, 'candidates.0.groundingMetadata', []);
        $meta = is_array($meta) ? $meta : [];

        $queries = data_get($meta, 'webSearchQueries', []);

        return [
            'text' => $this->extractText($payload),
            'citations' => $this->extractCitations($meta),
            'search_queries' => is_array($queries) ? array_values(array_map('strval', $queries)) : [],
            'search_entry_point' => (string) data_get($meta, 'searchEntryPoint.renderedContent', ''),
            'model' => $model,
        ];
    }

    /**
     * groundingChunks → 正規化引用。chunk 內可能是 web（search）或 maps（map）。
     *
     * @param  array<string, mixed>  $meta
     * @return array<int, array{title: string, uri: string, snippet: string}>
     */
    private function extractCitations(array $meta): array
    {
        $chunks = data_get($meta, 'groundingChunks', []);
        if (! is_array($chunks)) {
            return [];
        }

        $citations = [];
        foreach ($chunks as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            // web（search grounding）或 maps（map grounding）擇一存在。
            $src = $chunk['web'] ?? $chunk['maps'] ?? [];
            $src = is_array($src) ? $src : [];

            $title = (string) ($src['title'] ?? '');
            $uri = (string) ($src['uri'] ?? '');
            if ($title === '' && $uri === '') {
                continue;
            }

            $citations[] = [
                'title' => $title,
                'uri' => $uri,
                'snippet' => (string) ($src['text'] ?? $src['snippet'] ?? ''),
            ];
        }

        return $citations;
    }

    /**
     * Map grounding 的地點清單。結構以實機回傳為準，這裡盡量寬鬆地撈常見欄位。
     *
     * @param  array<string, mixed>  $meta
     * @return array<int, array<string, mixed>>
     */
    private function extractPlaces(array $meta): array
    {
        $chunks = data_get($meta, 'groundingChunks', []);
        if (! is_array($chunks)) {
            return [];
        }

        $places = [];
        foreach ($chunks as $chunk) {
            $maps = is_array($chunk) ? ($chunk['maps'] ?? null) : null;
            if (is_array($maps)) {
                $places[] = $maps;
            }
        }

        return $places;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractText(array $payload): string
    {
        $parts = data_get($payload, 'candidates.0.content.parts', []);
        if (! is_array($parts)) {
            return '';
        }

        $texts = [];
        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $texts[] = trim($part['text']);
            }
        }

        // array_filter 不給 callback 會連 "0" 一起濾掉（PHP falsy），明確只濾空字串。
        return trim(implode("\n", array_filter($texts, fn ($t) => $t !== '')));
    }

    private function endpointForModel(string $model): string
    {
        return sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $model,
        );
    }
}
