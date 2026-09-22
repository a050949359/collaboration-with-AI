<?php

namespace App\Http\Controllers\Territory;

use App\Http\Controllers\Controller;
use App\Models\Territory\TerritoryEntity;
use App\Models\Territory\TerritoryObservation;
use App\Models\Territory\TerritoryRelation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Territory 網頁瀏覽用的 REST 端點（公開唯讀），跟 /api/mcp/territory 那套 JSON-RPC 分開：
 * MCP 端點是給 LLM/工具用的（API key + tool schema），這裡是給前端頁面用的一般 REST。
 * 沿用 MemoryGraphController 的慣例（Memory 也是同樣的雙軌設計）。
 */
class TerritoryBrowseController extends Controller
{
    /** 行政區資料幾乎不變，快取放長一點；匯入後可手動清 territory:countries。 */
    private const COUNTRIES_TTL = 86400;

    /** 國家層要帶出來的 observation 欄位 */
    private const COUNTRY_FIELDS = [
        'label_en', 'label_zh_tw', 'numeric', 'iso_code', 'continent', 'population',
    ];

    /** 行政區層要帶出來的 observation 欄位 */
    private const NODE_FIELDS = [
        'label', 'label_en', 'description', 'coordinates', 'population', 'area', 'layer2_gap',
    ];

    /**
     * 公開：世界層摘要（約 260 個國家節點）。
     * 資料量小且幾乎不變，整包快取，前端一次拿完就不用每次點擊再查 ISO 對照。
     */
    public function countries(): JsonResponse
    {
        $countries = Cache::remember('territory:countries', self::COUNTRIES_TTL, function () {
            $entities = TerritoryEntity::where('type', 'country')->get(['id', 'name']);
            $ids = $entities->pluck('id');

            // 一次撈完所有需要的 observation，避免逐國 N+1
            $obs = TerritoryObservation::whereIn('entity_id', $ids)
                ->whereIn('type', self::COUNTRY_FIELDS)
                ->get(['entity_id', 'type', 'content'])
                ->groupBy('entity_id');

            // 一次算完每國的第一層行政區數量
            $childCounts = TerritoryRelation::whereIn('to_entity_id', $ids)
                ->where('relation_type', 'part_of')
                ->select('to_entity_id', DB::raw('COUNT(*) as c'))
                ->groupBy('to_entity_id')
                ->pluck('c', 'to_entity_id');

            return $entities->map(function ($e) use ($obs, $childCounts) {
                $fields = ($obs[$e->id] ?? collect())->pluck('content', 'type');

                return [
                    'qid' => $e->name,
                    'label' => $fields['label_zh_tw'] ?? $fields['label_en'] ?? $e->name,
                    'label_en' => $fields['label_en'] ?? null,
                    // world-atlas 的 polygon id 是 ISO 3166-1 數字碼，補零成 3 碼才對得上。
                    // 注意：不是每個 country 節點都有 numeric（解體歷史實體/爭議地區沒有）。
                    'iso_numeric' => isset($fields['numeric'])
                        ? str_pad($fields['numeric'], 3, '0', STR_PAD_LEFT)
                        : null,
                    'iso_code' => $fields['iso_code'] ?? null,
                    'continent' => $fields['continent'] ?? null,
                    'population' => isset($fields['population']) ? (int) $fields['population'] : null,
                    'child_count' => (int) ($childCounts[$e->id] ?? 0),
                ];
            })->values();
        });

        return response()->json($countries);
    }

    /** 公開：某節點的直屬子節點（part_of 指向它的節點），含座標與統計欄位。 */
    public function children(string $qid): JsonResponse
    {
        $parent = TerritoryEntity::where('name', $qid)->first(['id', 'name', 'type']);

        if (! $parent) {
            return response()->json(['message' => 'Entity not found.'], 404);
        }

        $childIds = TerritoryRelation::where('to_entity_id', $parent->id)
            ->where('relation_type', 'part_of')
            ->pluck('from_entity_id');

        $children = TerritoryEntity::whereIn('id', $childIds)->get(['id', 'name', 'type']);

        $obs = TerritoryObservation::whereIn('entity_id', $children->pluck('id'))
            ->whereIn('type', self::NODE_FIELDS)
            ->get(['entity_id', 'type', 'content'])
            ->groupBy('entity_id');

        return response()->json([
            'parent' => ['qid' => $parent->name, 'type' => $parent->type],
            'children' => $children->map(fn ($c) => $this->formatNode($c, $obs[$c->id] ?? collect()))
                ->sortByDesc('population')
                ->values(),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TerritoryObservation>  $observations
     * @return array<string, mixed>
     */
    private function formatNode(TerritoryEntity $entity, $observations): array
    {
        $fields = $observations->pluck('content', 'type');
        [$lat, $lng] = $this->parsePoint($fields['coordinates'] ?? null);

        return [
            'qid' => $entity->name,
            'type' => $entity->type,
            'label' => $fields['label'] ?? $fields['label_en'] ?? $entity->name,
            'description' => $fields['description'] ?? null,
            'lat' => $lat,
            'lng' => $lng,
            'population' => isset($fields['population']) ? (int) $fields['population'] : null,
            'area' => isset($fields['area']) ? (float) $fields['area'] : null,
            // layer2_gap 標記這個節點的下一層在 Wikidata 是缺的（或腳本技術限制），
            // 前端拿來畫「資料完整度」，跟「真的沒有下層」要分開看待。
            'has_gap' => isset($fields['layer2_gap']),
        ];
    }

    /**
     * Wikidata 座標存成 "Point(lng lat)" 字串，在 API 邊界就解析成數值，
     * 前端/地球拿到的就是乾淨的 lat/lng，不用各自再寫一份解析。
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function parsePoint(?string $raw): array
    {
        if (! $raw || ! preg_match('/Point\(\s*(-?[\d.]+)\s+(-?[\d.]+)\s*\)/i', $raw, $m)) {
            return [null, null];
        }

        return [(float) $m[2], (float) $m[1]];
    }
}
