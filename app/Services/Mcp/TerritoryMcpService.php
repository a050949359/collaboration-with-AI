<?php

namespace App\Services\Mcp;

use App\Models\Territory\TerritoryEntity;
use App\Models\Territory\TerritoryObservation;
use App\Models\Territory\TerritoryRelation;
use Illuminate\Http\JsonResponse;

class TerritoryMcpService implements McpToolServiceInterface
{
    private const WRITE_TOOLS = [
        'create_entity', 'delete_entity',
        'add_observation', 'remove_observation',
        'create_relation', 'delete_relation',
    ];

    private const READ_TOOLS = ['read_graph', 'search_nodes'];

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
            'remove_observation' => $this->removeObservation($id, $args),
            'create_relation' => $this->createRelation($id, $args),
            'delete_relation' => $this->deleteRelation($id, $args),
            'read_graph' => $this->readGraph($id, $args),
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
        $entity = TerritoryEntity::firstOrCreate(['name' => $name], ['type' => $type]);

        return $this->text($id, json_encode($entity, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function deleteEntity(mixed $id, array $args): JsonResponse
    {
        $entity = TerritoryEntity::where('name', $args['name'] ?? '')->first();
        if (! $entity) {
            return $this->text($id, 'Entity not found.', true);
        }
        $entity->delete();

        return $this->text($id, "Entity '{$entity->name}' deleted.");
    }

    private function addObservation(mixed $id, array $args): JsonResponse
    {
        $entity = TerritoryEntity::where('name', $args['entity_name'] ?? '')->first();
        if (! $entity) {
            return $this->text($id, 'Entity not found.', true);
        }
        $content = trim($args['content'] ?? '');
        if (! $content) {
            return $this->text($id, 'content is required.', true);
        }
        $obs = $entity->observations()->create(['content' => $content]);

        return $this->text($id, json_encode($obs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function removeObservation(mixed $id, array $args): JsonResponse
    {
        $obs = TerritoryObservation::find($args['id'] ?? null);
        if (! $obs) {
            return $this->text($id, 'Observation not found.', true);
        }
        $obs->delete();

        return $this->text($id, 'Observation removed.');
    }

    private function createRelation(mixed $id, array $args): JsonResponse
    {
        $from = TerritoryEntity::where('name', $args['from'] ?? '')->first();
        $to = TerritoryEntity::where('name', $args['to'] ?? '')->first();
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
        $rel->load('from', 'to');

        return $this->text($id, json_encode([
            'from' => $rel->from->name,
            'relation_type' => $rel->relation_type,
            'to' => $rel->to->name,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function deleteRelation(mixed $id, array $args): JsonResponse
    {
        $from = TerritoryEntity::where('name', $args['from'] ?? '')->first();
        $to = TerritoryEntity::where('name', $args['to'] ?? '')->first();
        if (! $from || ! $to) {
            return $this->text($id, 'Entity not found.', true);
        }

        $deleted = TerritoryRelation::where([
            'from_entity_id' => $from->id,
            'to_entity_id' => $to->id,
            'relation_type' => $args['relation_type'] ?? '',
        ])->delete();

        return $this->text($id, $deleted ? 'Relation deleted.' : 'Relation not found.');
    }

    // ── Read tools ────────────────────────────────────────────────

    private function readGraph(mixed $id, array $args): JsonResponse
    {
        $entityName = $args['entity_name'] ?? null;

        $entityQuery = TerritoryEntity::with('observations');
        if ($entityName) {
            $entityQuery->where('name', $entityName);
        }
        $entities = $entityQuery->get()->map(fn ($e) => [
            'id' => $e->id,
            'name' => $e->name,
            'type' => $e->type,
            'observations' => $e->observations->map(fn ($o) => ['id' => $o->id, 'content' => $o->content])->all(),
        ]);

        $relQuery = TerritoryRelation::with('from', 'to');
        if ($entityName) {
            $entityIds = $entities->pluck('id');
            $relQuery->whereIn('from_entity_id', $entityIds)->orWhereIn('to_entity_id', $entityIds);
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

    private function searchNodes(mixed $id, array $args): JsonResponse
    {
        $query = trim($args['query'] ?? '');
        if (! $query) {
            return $this->text($id, 'query is required.', true);
        }

        $entities = TerritoryEntity::with('observations')
            ->where(function ($q) use ($query) {
                // OR 條件包進巢狀 closure，避免日後加 where/全域 scope 時運算子優先級拆錯分組
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('type', 'like', "%{$query}%")
                    ->orWhereHas('observations', fn ($sub) => $sub->where('content', 'like', "%{$query}%"));
            })
            ->get()
            ->map(fn ($e) => [
                'name' => $e->name,
                'type' => $e->type,
                'observations' => $e->observations->map(fn ($o) => ['id' => $o->id, 'content' => $o->content])->all(),
            ]);

        return $this->text($id, json_encode($entities, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    // ── Tool schemas ──────────────────────────────────────────────

    public function toolSchemas(): array
    {
        return [
            [
                'name' => 'create_entity',
                'description' => '建立行政區/國家節點。name 全域唯一，慣例一律填 Wikidata QID（如 Q90），不要填人類可讀的地名——同名地點在世界上極常見（美國有 30+ 個 Springfield、法國巴黎跟美國德州都有 Paris），用顯示名稱當唯一鍵會把不同地點誤判成同一筆。QID 由 Wikidata 保證全域唯一，天然解決這個問題。建立後應立即用 add_observation 補一條 "label: <人類可讀名稱>" 的觀察，顯示名稱查詢靠 search_nodes 比對 observation 內容。type 為自由字串（慣例：country、province、city、special_ward、traditional_authority），不用來推斷層級深度，只作顯示用途。',
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
                'description' => '對節點附加一條文字觀察，用於記錄人類可讀名稱（label: ...）、人口、座標、資料可信度等事實。同一節點可有多條觀察。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_name' => ['type' => 'string', 'description' => '目標節點的 Wikidata QID'],
                        'content' => ['type' => 'string', 'description' => '觀察內容文字，例如 "label: Paris"'],
                    ],
                    'required' => ['entity_name', 'content'],
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
                'description' => '讀取行政區知識圖譜，回傳 entities（含 observations）與 relations。指定 entity_name 時只回傳該節點及與其相連的 relations；不指定則回傳完整圖。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_name' => ['type' => 'string', 'description' => '只看特定節點的子圖，填 Wikidata QID（選填）'],
                    ],
                ],
            ],
            [
                'name' => 'search_nodes',
                'description' => '以關鍵字搜尋節點，比對範圍包含節點名稱（QID）、type 及所有 observation 內容（含 label）。因為 name 存的是 QID 不是地名，用人類可讀名稱找節點時應該用這個工具（例如搜尋 "Paris"）取得對應的 QID，再用 QID 呼叫其他工具。',
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
