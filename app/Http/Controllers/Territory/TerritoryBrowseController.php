<?php

namespace App\Http\Controllers\Territory;

use App\Enums\Territory\CountryObservationType;
use App\Enums\Territory\SubdivisionObservationType;
use App\Http\Controllers\Controller;
use App\Models\Territory\TerritoryEntity;
use App\Models\Territory\TerritoryObservation;
use App\Models\Territory\TerritoryRelation;
use App\Support\TerritoryCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Territory 網頁瀏覽用的 REST 端點（公開唯讀），跟 /api/mcp/territory 那套 JSON-RPC 分開：
 * MCP 端點是給 LLM/工具用的（API key + tool schema），這裡是給前端頁面用的一般 REST。
 * 沿用 MemoryGraphController 的慣例（Memory 也是同樣的雙軌設計）。
 */
class TerritoryBrowseController extends Controller
{
    /**
     * 要帶出來的 observation 欄位。
     *
     * 值一律取自 enum，不要在這裡寫字串字面值——專案慣例是「enum 是合法值的單一
     * 來源」。寫死的話，之後改 enum 的 value 這裡不會有任何編譯期或執行期警告，
     * 260 個國家會一起靜默變成 null。
     *
     * 這裡刻意只取用得到的**子集**（不是 `array_column(cases())`）：API 回傳什麼欄位
     * 是這支端點的決定，跟「資料層存了哪些 type」是兩回事。
     * 特別是 `layer2_gap` 絕不能進來——那是匯入流程的內部品管記錄（內容含日期與
     * 內部工具名稱），屬於開發端資訊，不該從公開端點流出去。
     *
     * @return list<string>
     */
    private static function countryFields(): array
    {
        return array_map(fn (CountryObservationType $t) => $t->value, [
            CountryObservationType::LabelEn,
            CountryObservationType::LabelZhTw,
            CountryObservationType::Numeric,
            CountryObservationType::IsoCode,
            CountryObservationType::Continent,
            CountryObservationType::Population,
        ]);
    }

    /**
     * 行政區層要帶出來的欄位。
     *
     * 除了 SubdivisionObservationType 那幾個，另外收 `label_en`——它屬於
     * CountryObservationType，因為有些「子節點」本身是 country 型別的實體
     * （法國的海外屬地就是這樣），它們身上掛的是國家版的欄位組合。
     *
     * @return list<string>
     */
    private static function nodeFields(): array
    {
        return [
            ...array_map(fn (SubdivisionObservationType $t) => $t->value, [
                SubdivisionObservationType::Label,
                SubdivisionObservationType::Description,
                SubdivisionObservationType::Coordinates,
                SubdivisionObservationType::Population,
                SubdivisionObservationType::Area,
            ]),
            CountryObservationType::LabelEn->value,
        ];
    }

    /**
     * 公開：世界層摘要（約 260 個國家節點）。
     * 資料量小且幾乎不變，整包快取，前端一次拿完就不用每次點擊再查 ISO 對照。
     */
    public function countries(): JsonResponse
    {
        // ⚠️ 不要用 Cache::remember 包 try/catch。那樣寫在「closure 已經跑完、只是
        // 存快取失敗」時（例如 Redis maxmemory + noeviction）會掉進 catch 再 build
        // 一次，等於每個 request 打兩輪查詢、而且快取永遠填不回去——這支是公開且
        // 沒有 throttle 的端點，等於自帶放大器。
        //
        // 拆成「讀 → build → 寫」三步，build 最多只會發生一次；讀寫各自的失敗都
        // 只是退化成直接查 DB。DB 本身的例外不攔，直接往上拋。
        $cached = null;

        try {
            $cached = Cache::get(TerritoryCache::COUNTRIES_KEY);
        } catch (\Throwable) {
            // 讀不到就當 miss
        }

        if (is_array($cached)) {
            return response()->json($cached);
        }

        $countries = $this->buildCountries();

        try {
            Cache::put(TerritoryCache::COUNTRIES_KEY, $countries, TerritoryCache::COUNTRIES_TTL);
        } catch (\Throwable) {
            // 寫不進去不影響這次回應，下次再試
        }

        return response()->json($countries);
    }

    /**
     * 回傳「純陣列」而非 Eloquent Collection：快取序列化時物件會帶著類別資訊，
     * 換行程讀回來還原不了會變成 __PHP_Incomplete_Class，之後整個 TTL 期間
     * 都回傳壞掉的內容。純資料不管哪種 serializer 都安全。
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildCountries(): array
    {
        // ⚠️ 大小寫不敏感比對：`type` 是 create_entity 傳進來的自由字串，
        // WriteTerritoryObservationJob 已經用 strtolower() 防過同一件事。這裡若用
        // `where('type', 'country')`，一個存成 'Country' 的實體會照樣拿到完整的國家版
        // observation，卻在這支端點上憑空消失（生產是 SQLite，`=` 對大小寫敏感）。
        $entities = TerritoryEntity::whereRaw('LOWER(type) = ?', ['country'])
            ->get(['id', 'name']);
        $ids = $entities->pluck('id');

        // 一次撈完所有需要的 observation，避免逐國 N+1
        $obs = TerritoryObservation::whereIn('entity_id', $ids)
            ->whereIn('type', self::countryFields())
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
        })->values()->all();
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
            ->whereIn('type', self::nodeFields())
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
     * @param  Collection<int, TerritoryObservation>  $observations
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
