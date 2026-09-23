<?php

namespace App\Services\Mcp;

use App\Models\Territory\TerritoryEntity;
use App\Models\Territory\TerritoryObservation;
use App\Models\Territory\TerritoryObservationJob;
use App\Models\Territory\TerritoryRelation;
use App\Support\TerritoryCache;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class TerritoryMcpService implements McpToolServiceInterface
{
    private const WRITE_TOOLS = [
        'create_entity', 'delete_entity',
        'add_observation', 'remove_observation',
        'create_relation', 'delete_relation',
        'refresh_observations',
    ];

    private const READ_TOOLS = ['read_graph', 'read_children', 'read_subtree', 'read_ancestors', 'search_nodes'];

    // 未指定 entity_name 時 read_graph 的匯出上限；search_nodes 的搜尋結果上限。
    private const UNSCOPED_GRAPH_LIMIT = 200;

    private const SEARCH_LIMIT = 50;

    // read_subtree 的安全上限：depth 最多往下幾層、整棵樹最多回傳幾個節點
    // （防止對大國一次查太深層，把伺服器記憶體或回應大小炸掉）。
    private const SUBTREE_MAX_DEPTH = 5;

    private const SUBTREE_NODE_LIMIT = 1000;

    public function canHandle(string $name): bool
    {
        return \in_array($name, [...self::WRITE_TOOLS, ...self::READ_TOOLS]);
    }

    public function call(string $name, array $args, mixed $id): JsonResponse
    {
        return match ($name) {
            'create_entity' => $this->createEntity($id, $args),
            'delete_entity' => $this->deleteEntity($id, $args),
            'add_observation' => $this->addObservation($id, $args),
            'refresh_observations' => $this->refreshObservations($id, $args),
            'remove_observation' => $this->removeObservation($id, $args),
            'create_relation' => $this->createRelation($id, $args),
            'delete_relation' => $this->deleteRelation($id, $args),
            'read_graph' => $this->readGraph($id, $args),
            'read_children' => $this->readChildren($id, $args),
            'read_subtree' => $this->readSubtree($id, $args),
            'read_ancestors' => $this->readAncestors($id, $args),
            'search_nodes' => $this->searchNodes($id, $args),
            default => $this->text($id, "Unknown tool: $name", true),
        };
    }

    // ── Write tools ───────────────────────────────────────────────

    private function createEntity(mixed $id, array $args): JsonResponse
    {
        $name = trim($args['name'] ?? '');
        $type = trim($args['type'] ?? '');
        if (! $name || ! $type) {
            return $this->text($id, 'name and type are required.', true);
        }
        if (! preg_match('/^Q\d+$/', $name)) {
            return $this->text($id, "name must be a Wikidata QID (e.g. Q90), got: {$name}", true);
        }
        $entity = TerritoryEntity::firstOrCreate(['name' => $name], ['type' => $type]);

        return $this->text($id, json_encode($entity, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function deleteEntity(mixed $id, array $args): JsonResponse
    {
        $entity = TerritoryEntity::where('name', trim($args['name'] ?? ''))->first();
        if (! $entity) {
            return $this->text($id, 'Entity not found.', true);
        }
        $entity->delete();

        return $this->text($id, "Entity '{$entity->name}' deleted.");
    }

    private function addObservation(mixed $id, array $args): JsonResponse
    {
        $entity = TerritoryEntity::where('name', trim($args['entity_name'] ?? ''))->first();
        if (! $entity) {
            return $this->text($id, 'Entity not found.', true);
        }
        $content = trim($args['content'] ?? '');
        if (! $content) {
            return $this->text($id, 'content is required.', true);
        }
        $type = trim($args['type'] ?? '') ?: 'desc';

        try {
            $obs = $entity->observations()->create(['content' => $content, 'type' => $type]);
        } catch (UniqueConstraintViolationException) {
            return $this->text($id, "Entity already has an observation of type '{$type}'. Use remove_observation to replace it.", true);
        }

        return $this->text($id, json_encode($obs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function refreshObservations(mixed $id, array $args): JsonResponse
    {
        $entityName = trim($args['entity_name'] ?? '');
        if (! preg_match('/^Q\d+$/', $entityName)) {
            return $this->text($id, "entity_name must be a Wikidata QID (e.g. Q90), got: {$entityName}", true);
        }

        $entity = TerritoryEntity::where('name', $entityName)->first();
        if (! $entity) {
            return $this->text($id, 'Entity not found.', true);
        }

        $job = TerritoryObservationJob::queue($entityName, Auth::id());

        return $this->text($id, json_encode(['job_id' => $job->id, 'status' => $job->status], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function removeObservation(mixed $id, array $args): JsonResponse
    {
        // Eloquent find() 遇陣列會走 findMany() 回傳 Collection（恆真），不是 model-or-null，
        // 用 is_int 先擋掉，避免非整數 id（例如陣列）繞過下面的「not found」判斷。
        if (! \is_int($args['id'] ?? null)) {
            return $this->text($id, 'id must be an integer.', true);
        }
        $obs = TerritoryObservation::find($args['id']);
        if (! $obs) {
            return $this->text($id, 'Observation not found.', true);
        }
        $obs->delete();

        return $this->text($id, 'Observation removed.');
    }

    private function createRelation(mixed $id, array $args): JsonResponse
    {
        $from = TerritoryEntity::where('name', trim($args['from'] ?? ''))->first();
        $to = TerritoryEntity::where('name', trim($args['to'] ?? ''))->first();
        if (! $from) {
            return $this->text($id, "Entity '{$args['from']}' not found.", true);
        }
        if (! $to) {
            return $this->text($id, "Entity '{$args['to']}' not found.", true);
        }
        $relationType = trim($args['relation_type'] ?? '');
        if (! $relationType) {
            return $this->text($id, 'relation_type is required.', true);
        }

        $rel = TerritoryRelation::firstOrCreate([
            'from_entity_id' => $from->id,
            'to_entity_id' => $to->id,
            'relation_type' => $relationType,
        ]);

        // child_count 是世界層摘要的一部分，關係變動就要讓它重算
        TerritoryCache::forgetCountries();

        return $this->text($id, json_encode([
            'from' => $from->name,
            'relation_type' => $rel->relation_type,
            'to' => $to->name,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function deleteRelation(mixed $id, array $args): JsonResponse
    {
        $from = TerritoryEntity::where('name', trim($args['from'] ?? ''))->first();
        $to = TerritoryEntity::where('name', trim($args['to'] ?? ''))->first();
        if (! $from || ! $to) {
            return $this->text($id, 'Entity not found.', true);
        }
        $relationType = trim($args['relation_type'] ?? '');
        if (! $relationType) {
            return $this->text($id, 'relation_type is required.', true);
        }

        $deleted = TerritoryRelation::where([
            'from_entity_id' => $from->id,
            'to_entity_id' => $to->id,
            'relation_type' => $relationType,
        ])->delete();

        if ($deleted) {
            TerritoryCache::forgetCountries();
        }

        return $this->text($id, $deleted ? 'Relation deleted.' : 'Relation not found.');
    }

    // ── Read tools ────────────────────────────────────────────────

    private function readGraph(mixed $id, array $args): JsonResponse
    {
        $entityName = trim($args['entity_name'] ?? '') ?: null;

        $entityQuery = TerritoryEntity::with('observations');
        if ($entityName) {
            $entityQuery->where('name', $entityName);
        } else {
            // 未指定 entity_name = 匯出整張圖；資料量成長後（批次匯入行政區）避免一次載入全表 OOM。
            $entityQuery->limit(self::UNSCOPED_GRAPH_LIMIT);
        }
        $entities = $entityQuery->get()->map(fn ($e) => [
            'id' => $e->id,
            'name' => $e->name,
            'type' => $e->type,
            'observations' => $e->observations->map(fn ($o) => ['id' => $o->id, 'type' => $o->type, 'content' => $o->content])->all(),
        ]);

        $relQuery = TerritoryRelation::with('from', 'to');
        if ($entityName) {
            $entityIds = $entities->pluck('id');
            $relQuery->whereIn('from_entity_id', $entityIds)->orWhereIn('to_entity_id', $entityIds);
        } else {
            $relQuery->limit(self::UNSCOPED_GRAPH_LIMIT);
        }
        $relations = $relQuery->get()->map(fn ($r) => [
            'from' => $r->from->name,
            'relation_type' => $r->relation_type,
            'to' => $r->to->name,
        ]);

        return $this->text($id, json_encode(
            compact('entities', 'relations'),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
    }

    private function readChildren(mixed $id, array $args): JsonResponse
    {
        $entityName = trim($args['entity_name'] ?? '');
        if (! $entityName) {
            return $this->text($id, 'entity_name is required.', true);
        }
        $parent = TerritoryEntity::where('name', $entityName)->first();
        if (! $parent) {
            return $this->text($id, 'Entity not found.', true);
        }

        // 兩步查詢，不是逐子節點各查一次：先一次撈出所有子節點 id，
        // 再用 whereIn + with('observations') 一次 eager load 全部子節點的資料。
        $childIds = TerritoryRelation::where('to_entity_id', $parent->id)
            ->where('relation_type', 'part_of')
            ->pluck('from_entity_id');

        $children = TerritoryEntity::with('observations')
            ->whereIn('id', $childIds)
            ->get()
            ->map(fn ($e) => $this->formatEntity($e));

        return $this->text($id, json_encode([
            'parent' => $this->formatEntity($parent),
            'children' => $children,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    // read_children/read_subtree 專用的精簡節點格式：
    // - 不含內部 DB id（對外一律用 QID 識別，這個數字 id 沒有呼叫端會用到）
    // - QID 欄位命名為 qid 而非 name——底層 TerritoryEntity.name 欄位存的其實是 Wikidata QID
    //   （既有慣例，見 create_entity 工具說明），沿用 name 這個 key 容易被誤會是人類可讀名稱，
    //   真正的顯示名稱在 observations.label / observations.label_en 裡
    // - observations 從 [{id,type,content}] 陣列壓成 {type: content} 扁平物件
    //   （同一節點同一 type 唯一，DB 有 unique(entity_id,type) 約束，pluck 不會丟資料）
    // read_graph/search_nodes 刻意不套用這個格式，兩者的既有呼叫端（territory-import-subdivisions.py
    // 的 remove_observation 流程、entities[0] 假設）依賴原本的 name/id 欄位，不能動。
    private function formatEntity(TerritoryEntity $entity): array
    {
        return [
            'qid' => $entity->name,
            'type' => $entity->type,
            'observations' => $entity->observations->pluck('content', 'type')->all(),
        ];
    }

    private function readSubtree(mixed $id, array $args): JsonResponse
    {
        return $this->walkPartOfBfs($id, $args, goUp: false, childrenKey: 'children');
    }

    private function readAncestors(mixed $id, array $args): JsonResponse
    {
        return $this->walkPartOfBfs($id, $args, goUp: true, childrenKey: 'parents');
    }

    // read_subtree／read_ancestors 共用的 BFS：沿 part_of 關係一路往下（子節點）或往上
    // （父節點）走 depth 層，逐層固定 2 條 SQL（relations 一次撈、entities+observations 一次
    // eager load），不管那一層有幾個節點都不會變成 N+1；最後用遞迴 closure 一次組成巢狀樹回傳。
    // $goUp=false：frontier 用 to_entity_id 比對，往子節點走（read_subtree）。
    // $goUp=true：frontier 用 from_entity_id 比對，往父節點走（read_ancestors）。
    // $childrenKey 決定輸出巢狀陣列叫 children 還是 parents，語意上比較清楚。
    private function walkPartOfBfs(mixed $id, array $args, bool $goUp, string $childrenKey): JsonResponse
    {
        $entityName = trim($args['entity_name'] ?? '');
        if (! $entityName) {
            return $this->text($id, 'entity_name is required.', true);
        }
        $depth = (int) ($args['depth'] ?? 1);
        $depth = max(1, min($depth, self::SUBTREE_MAX_DEPTH));

        $root = TerritoryEntity::with('observations')->where('name', $entityName)->first();
        if (! $root) {
            return $this->text($id, 'Entity not found.', true);
        }

        $frontierColumn = $goUp ? 'from_entity_id' : 'to_entity_id';
        $nextColumn = $goUp ? 'to_entity_id' : 'from_entity_id';

        $entitiesById = [$root->id => $root];
        $relatedIdsByAnchorId = [];
        $frontierIds = [$root->id];
        $totalNodes = 1;
        $truncated = false;

        for ($level = 0; $level < $depth && $frontierIds !== [] && $totalNodes < self::SUBTREE_NODE_LIMIT; $level++) {
            $relations = TerritoryRelation::where('relation_type', 'part_of')
                ->whereIn($frontierColumn, $frontierIds)
                ->get(['from_entity_id', 'to_entity_id']);

            if ($relations->isEmpty()) {
                break;
            }

            $remaining = self::SUBTREE_NODE_LIMIT - $totalNodes;
            $nextIds = $relations->pluck($nextColumn)->unique()->values();
            if ($nextIds->count() > $remaining) {
                $truncated = true;
                $nextIds = $nextIds->take($remaining);
            }
            $allowedNextIds = $nextIds->flip();

            foreach ($relations as $rel) {
                $anchorId = $goUp ? $rel->from_entity_id : $rel->to_entity_id;
                $relatedId = $goUp ? $rel->to_entity_id : $rel->from_entity_id;
                if (! $allowedNextIds->has($relatedId)) {
                    continue;
                }
                $relatedIdsByAnchorId[$anchorId][] = $relatedId;
            }

            $newEntities = TerritoryEntity::with('observations')->whereIn('id', $nextIds)->get()->keyBy('id');
            foreach ($newEntities as $entityId => $entity) {
                $entitiesById[$entityId] = $entity;
            }

            $totalNodes += $newEntities->count();
            $frontierIds = $newEntities->keys()->all();
        }

        // $ancestorPath = 從根節點到目前節點路上已經走過的 id 清單（不論往上還是往下走，
        // 命名都沿用 ancestorPath，代表「這條路徑上已經出現過的節點」）。part_of 理論上不該
        // 出現循環，但 DB 沒有約束擋掉，沒有這層防護真的遇到循環會無限遞迴到 stack overflow。
        $buildNode = function (int $entityId, array $ancestorPath = []) use (&$buildNode, $entitiesById, $relatedIdsByAnchorId, $childrenKey): array {
            $node = $this->formatEntity($entitiesById[$entityId]);
            if (\in_array($entityId, $ancestorPath, true)) {
                $node[$childrenKey] = [];

                return $node;
            }
            $path = [...$ancestorPath, $entityId];
            $node[$childrenKey] = array_map(
                fn (int $relatedId) => $buildNode($relatedId, $path),
                $relatedIdsByAnchorId[$entityId] ?? []
            );

            return $node;
        };

        return $this->text($id, json_encode([
            'tree' => $buildNode($root->id),
            'total_nodes' => $totalNodes,
            'truncated' => $truncated,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function searchNodes(mixed $id, array $args): JsonResponse
    {
        $query = trim($args['query'] ?? '');
        if (! $query) {
            return $this->text($id, 'query is required.', true);
        }
        // 跳脫 LIKE 萬用字元，避免 query 本身含 % / _ 時被當成萬用字元造成非預期配對。
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);

        $entities = TerritoryEntity::with('observations')
            ->where(function ($q) use ($escaped) {
                // OR 條件包進巢狀 closure，避免日後加 where/全域 scope 時運算子優先級拆錯分組
                $q->where('name', 'like', "%{$escaped}%")
                    ->orWhere('type', 'like', "%{$escaped}%")
                    ->orWhereHas('observations', fn ($sub) => $sub->where('content', 'like', "%{$escaped}%"));
            })
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->map(fn ($e) => [
                'name' => $e->name,
                'type' => $e->type,
                'observations' => $e->observations->map(fn ($o) => ['id' => $o->id, 'type' => $o->type, 'content' => $o->content])->all(),
            ]);

        return $this->text($id, json_encode($entities, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    // ── Tool schemas ──────────────────────────────────────────────

    public function toolSchemas(): array
    {
        return [
            [
                'name' => 'create_entity',
                'description' => '建立行政區/國家節點。name 全域唯一，慣例一律填 Wikidata QID（如 Q90），不要填人類可讀的地名——同名地點在世界上極常見（美國有 30+ 個 Springfield、法國巴黎跟美國德州都有 Paris），用顯示名稱當唯一鍵會把不同地點誤判成同一筆。QID 由 Wikidata 保證全域唯一，天然解決這個問題。建立後應立即呼叫 refresh_observations 觸發伺服器自動補齊 label 等欄位（不要自己用 add_observation 手動補 label，那個工具的 content 現在只放值本身、type 才是分類鍵，例如 type="label_en"，不要把 key 塞進 content 字串），顯示名稱查詢靠 search_nodes 比對 observation 內容。type 為自由字串（慣例：country、province、city、special_ward、traditional_authority），不用來推斷層級深度，只作顯示用途——但 country 這個值目前也是 refresh_observations 用來判斷走國家版還是行政區版 Wikidata 查詢的依據，務必精確填小寫 "country"。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => '節點唯一識別碼，填 Wikidata QID，例如 Q865（Taiwan）、Q1867（Taipei）'],
                        'type' => ['type' => 'string', 'description' => '節點類型，例如 country、province、city、special_ward、traditional_authority'],
                    ],
                    'required' => ['name', 'type'],
                ],
            ],
            [
                'name' => 'delete_entity',
                'description' => '刪除指定節點，並 cascade 刪除該節點的所有 observations 和 relations，操作不可復原。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string', 'description' => 'Wikidata QID']],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'add_observation',
                'description' => '對節點附加一條觀察，用於記錄人類可讀名稱、人口、座標、資料可信度等事實。type 是這條觀察的分類鍵（例如 "label_en"、"capital"、"status"），content 只放值本身（不要自己把 key 塞進 content 字串）；同一節點同一個 type 只能有一條（DB 有 unique(entity_id, type) 約束），重複呼叫同一 type（含不填 type 時預設的 "desc"）會失敗，需要更新請先 remove_observation 再重新 add。同一節點可有多條「不同」type 的觀察。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_name' => ['type' => 'string', 'description' => '目標節點的 Wikidata QID'],
                        'content' => ['type' => 'string', 'description' => '觀察內容的值本身，例如 "Paris"（不要寫成 "label: Paris"）'],
                        'type' => ['type' => 'string', 'description' => '分類鍵，例如 "label_en"、"capital"、"status"；不填預設 "desc"'],
                    ],
                    'required' => ['entity_name', 'content'],
                ],
            ],
            [
                'name' => 'refresh_observations',
                'description' => '不是直接寫入，而是建一筆 job 丟進 Laravel queue，由伺服器端非同步對這個 QID 重新查一次 Wikidata，依欄位分別建立/更新對應的 observation（同一節點同一種欄位只留最新值，可安全對同一節點重複呼叫來刷新資料）。country 節點跟其他節點（行政區）欄位不同：country 查 label_en/label_zh_tw/iso_code/alpha3/numeric/capital/phone_code/population/continent，並額外用查到的 iso_code 比對本機 countries 表補上 recognized/status/notes；行政區查 label/description/instance_of/座標/人口/面積。立即回傳 job_id，實際寫入結果要晚一點才會反映在 read_graph/search_nodes。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_name' => ['type' => 'string', 'description' => '目標節點的 Wikidata QID'],
                    ],
                    'required' => ['entity_name'],
                ],
            ],
            [
                'name' => 'remove_observation',
                'description' => '以 ID 刪除單條觀察。observation ID 可從 read_graph 或 search_nodes 回傳結果中取得。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'observation ID（來自 read_graph / search_nodes）']],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'create_relation',
                'description' => '在兩個已存在的節點之間建立有向關係（from → relation_type → to）。慣例 relation_type：part_of（子節點屬於某父節點）。同一節點可有多條 part_of 指向不同的平行治理單位（例如同時屬於市政區與部落領地），不受限於單一父節點。相同的三元組不會重複建立。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => '來源節點的 Wikidata QID（子節點）'],
                        'to' => ['type' => 'string', 'description' => '目標節點的 Wikidata QID（父節點）'],
                        'relation_type' => ['type' => 'string', 'description' => '關係類型，慣例：part_of'],
                    ],
                    'required' => ['from', 'to', 'relation_type'],
                ],
            ],
            [
                'name' => 'delete_relation',
                'description' => '刪除指定的有向關係。需同時提供 from、to、relation_type 三個欄位才能精確定位。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'Wikidata QID'],
                        'to' => ['type' => 'string', 'description' => 'Wikidata QID'],
                        'relation_type' => ['type' => 'string'],
                    ],
                    'required' => ['from', 'to', 'relation_type'],
                ],
            ],
            [
                'name' => 'read_graph',
                'description' => '讀取行政區知識圖譜，回傳 entities（含 observations）與 relations。指定 entity_name 時只回傳該節點及與其相連的 relations；不指定則回傳完整圖，但為避免資料量成長後 OOM，未指定時最多回傳 200 個節點（非完整快照）。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_name' => ['type' => 'string', 'description' => '只看特定節點的子圖，填 Wikidata QID（選填）'],
                    ],
                ],
            ],
            [
                'name' => 'read_children',
                'description' => '一次讀取指定節點的所有直屬子節點（part_of 指向它的節點）完整資料，含各自的 observations——不用像 read_graph 那樣每個子節點各查一次才拿得到名稱。回傳 parent（該節點本身）與 children（子節點陣列）；每個節點格式為 {qid, type, observations}，qid 是 Wikidata QID（識別用），observations 是 {type: content} 扁平物件（例如 observations.label 是顯示名稱，非 read_graph 那種 [{id,type,content}] 陣列，這裡不含 observation 自己的 DB id，需要用 id 呼叫 remove_observation 時改用 read_graph）。只往下抓一層；子節點自己的子節點需再對該子節點呼叫一次。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_name' => ['type' => 'string', 'description' => '父節點的 Wikidata QID'],
                    ],
                    'required' => ['entity_name'],
                ],
            ],
            [
                'name' => 'read_subtree',
                'description' => '一次讀取指定節點往下 N 層的完整子樹（巢狀結構），伺服器內部逐層 BFS（每層固定 2 條 SQL，不會因為節點數暴增變成逐點查詢），適合像「一次拿某國所有省 + 每省底下所有市」這種要跨兩層以上資料的情境，取代自己迴圈呼叫 read_children 多次。回傳 {tree, total_nodes, truncated}：tree 是巢狀節點，格式為 {qid, type, observations, children}（qid 是 Wikidata QID，observations 是 {type: content} 扁平物件，例如 observations.label 是顯示名稱；不含 entity/observation 自己的 DB id，需要 id 來呼叫 remove_observation 時改用 read_graph），children 是同樣格式的子節點陣列。total_nodes 是實際回傳的節點總數，truncated 為 true 代表因安全上限（最多 '.self::SUBTREE_NODE_LIMIT.' 個節點）被截斷，並非資料本身只有這麼多，需要縮小 depth 或改用 read_children 分批查。depth 上限 '.self::SUBTREE_MAX_DEPTH.' 層。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_name' => ['type' => 'string', 'description' => '根節點的 Wikidata QID'],
                        'depth' => ['type' => 'integer', 'description' => '往下查幾層，預設 1（等同 read_children），最大 '.self::SUBTREE_MAX_DEPTH],
                    ],
                    'required' => ['entity_name'],
                ],
            ],
            [
                'name' => 'read_ancestors',
                'description' => 'read_subtree 的反方向版本：一次讀取指定節點往上 N 層的祖先鏈（沿 part_of 往父節點走），適合手上只有一個子節點 QID（例如某個區），想知道它上面依序屬於哪個省、哪個國家，不用自己對 read_graph 拿到的父節點 QID 再逐層手動查。回傳 {tree, total_nodes, truncated}：tree 是巢狀節點，格式為 {qid, type, observations, parents}（parents 是同樣格式的父節點陣列——一個節點可能有多個 parallel parent，見 create_relation 說明）。total_nodes/truncated 語意同 read_subtree。depth 上限 '.self::SUBTREE_MAX_DEPTH.' 層。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_name' => ['type' => 'string', 'description' => '起點節點的 Wikidata QID'],
                        'depth' => ['type' => 'integer', 'description' => '往上查幾層，預設 1，最大 '.self::SUBTREE_MAX_DEPTH],
                    ],
                    'required' => ['entity_name'],
                ],
            ],
            [
                'name' => 'search_nodes',
                'description' => '以關鍵字搜尋節點，比對範圍包含節點名稱（QID）、type 及所有 observation 內容（含 label）。因為 name 存的是 QID 不是地名，用人類可讀名稱找節點時應該用這個工具（例如搜尋 "Paris"）取得對應的 QID，再用 QID 呼叫其他工具。最多回傳 50 筆，結果過多時請縮小關鍵字。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => '搜尋關鍵字，部分比對'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    // ── JSON-RPC helper ───────────────────────────────────────────

    private function text(mixed $id, string $text, bool $isError = false): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [['type' => 'text', 'text' => $text]],
                'isError' => $isError,
            ],
        ]);
    }
}
