<?php

namespace App\Services\Mcp;

use App\Models\Mcp\McpEntity;
use App\Models\Mcp\McpObservation;
use App\Models\Mcp\McpRelation;
use Illuminate\Http\JsonResponse;

class MemoryMcpService implements McpToolServiceInterface
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

    public function call(string $name, array $args, mixed $id, bool $isAdmin, ?array $scopes = null): JsonResponse
    {
        if (\in_array($name, self::WRITE_TOOLS)) {
            $hasScope = $scopes === null || \in_array('memory:mcp', $scopes);
            if (! $isAdmin || ! $hasScope) {
                return $this->text($id, 'Unauthorized: admin key with memory:write scope required.', true);
            }
        }

        return match ($name) {
            'create_entity'    => $this->createEntity($id, $args),
            'delete_entity'    => $this->deleteEntity($id, $args),
            'add_observation'  => $this->addObservation($id, $args),
            'remove_observation' => $this->removeObservation($id, $args),
            'create_relation'  => $this->createRelation($id, $args),
            'delete_relation'  => $this->deleteRelation($id, $args),
            'read_graph'       => $this->readGraph($id, $args),
            'search_nodes'     => $this->searchNodes($id, $args),
            default            => $this->text($id, "Unknown tool: $name", true),
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
        $entity = McpEntity::firstOrCreate(['name' => $name], ['type' => $type]);
        return $this->text($id, json_encode($entity, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function deleteEntity(mixed $id, array $args): JsonResponse
    {
        $entity = McpEntity::where('name', $args['name'] ?? '')->first();
        if (! $entity) return $this->text($id, 'Entity not found.', true);
        $entity->delete();
        return $this->text($id, "Entity '{$entity->name}' deleted.");
    }

    private function addObservation(mixed $id, array $args): JsonResponse
    {
        $entity = McpEntity::where('name', $args['entity_name'] ?? '')->first();
        if (! $entity) return $this->text($id, 'Entity not found.', true);
        $content = trim($args['content'] ?? '');
        if (! $content) return $this->text($id, 'content is required.', true);
        $obs = $entity->observations()->create(['content' => $content]);
        return $this->text($id, json_encode($obs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function removeObservation(mixed $id, array $args): JsonResponse
    {
        $obs = McpObservation::find($args['id'] ?? null);
        if (! $obs) return $this->text($id, 'Observation not found.', true);
        $obs->delete();
        return $this->text($id, 'Observation removed.');
    }

    private function createRelation(mixed $id, array $args): JsonResponse
    {
        $from = McpEntity::where('name', $args['from'] ?? '')->first();
        $to   = McpEntity::where('name', $args['to'] ?? '')->first();
        if (! $from) return $this->text($id, "Entity '{$args['from']}' not found.", true);
        if (! $to)   return $this->text($id, "Entity '{$args['to']}' not found.", true);
        $relationType = trim($args['relation_type'] ?? '');
        if (! $relationType) return $this->text($id, 'relation_type is required.', true);

        $rel = McpRelation::firstOrCreate([
            'from_entity_id' => $from->id,
            'to_entity_id'   => $to->id,
            'relation_type'  => $relationType,
        ]);
        $rel->load('from', 'to');
        return $this->text($id, json_encode([
            'from'          => $rel->from->name,
            'relation_type' => $rel->relation_type,
            'to'            => $rel->to->name,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function deleteRelation(mixed $id, array $args): JsonResponse
    {
        $from = McpEntity::where('name', $args['from'] ?? '')->first();
        $to   = McpEntity::where('name', $args['to'] ?? '')->first();
        if (! $from || ! $to) return $this->text($id, 'Entity not found.', true);

        $deleted = McpRelation::where([
            'from_entity_id' => $from->id,
            'to_entity_id'   => $to->id,
            'relation_type'  => $args['relation_type'] ?? '',
        ])->delete();

        return $this->text($id, $deleted ? 'Relation deleted.' : 'Relation not found.');
    }

    // ── Read tools ────────────────────────────────────────────────

    private function readGraph(mixed $id, array $args): JsonResponse
    {
        $entityName = $args['entity_name'] ?? null;

        $entityQuery = McpEntity::with('observations');
        if ($entityName) {
            $entityQuery->where('name', $entityName);
        }
        $entities = $entityQuery->get()->map(fn($e) => [
            'id'           => $e->id,
            'name'         => $e->name,
            'type'         => $e->type,
            'observations' => $e->observations->map(fn($o) => ['id' => $o->id, 'content' => $o->content]),
        ]);

        $relQuery = McpRelation::with('from', 'to');
        if ($entityName) {
            $entityIds = $entities->pluck('id');
            $relQuery->whereIn('from_entity_id', $entityIds)->orWhereIn('to_entity_id', $entityIds);
        }
        $relations = $relQuery->get()->map(fn($r) => [
            'from'          => $r->from->name,
            'relation_type' => $r->relation_type,
            'to'            => $r->to->name,
        ]);

        return $this->text($id, json_encode(
            compact('entities', 'relations'),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
    }

    private function searchNodes(mixed $id, array $args): JsonResponse
    {
        $query = trim($args['query'] ?? '');
        if (! $query) return $this->text($id, 'query is required.', true);

        $entities = McpEntity::with('observations')
            ->where('name', 'like', "%{$query}%")
            ->orWhere('type', 'like', "%{$query}%")
            ->orWhereHas('observations', fn($q) => $q->where('content', 'like', "%{$query}%"))
            ->get()
            ->map(fn($e) => [
                'name'         => $e->name,
                'type'         => $e->type,
                'observations' => $e->observations->map(fn($o) => ['id' => $o->id, 'content' => $o->content]),
            ]);

        return $this->text($id, json_encode($entities, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    // ── Tool schemas ──────────────────────────────────────────────

    public function toolSchemas(): array
    {
        return [
            [
                'name'        => 'create_entity',
                'description' => '建立知識圖譜節點（entity）。若已存在則直接回傳。需要 admin memory:mcp key。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => '唯一名稱（例如 collaboration-with-AI）'],
                        'type' => ['type' => 'string', 'description' => '自由字串（例如 project、host、service）'],
                    ],
                    'required' => ['name', 'type'],
                ],
            ],
            [
                'name'        => 'delete_entity',
                'description' => '刪除節點及其所有 observations 和 relations。需要 admin memory:mcp key。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['name' => ['type' => 'string']],
                    'required'   => ['name'],
                ],
            ],
            [
                'name'        => 'add_observation',
                'description' => '對節點新增一條觀察紀錄。需要 admin memory:mcp key。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entity_name' => ['type' => 'string', 'description' => '目標節點名稱'],
                        'content'     => ['type' => 'string', 'description' => '觀察內容'],
                    ],
                    'required' => ['entity_name', 'content'],
                ],
            ],
            [
                'name'        => 'remove_observation',
                'description' => '刪除指定觀察紀錄。需要 admin memory:mcp key。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'observation ID']],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'create_relation',
                'description' => '在兩個節點之間建立有向關係。需要 admin memory:mcp key。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'from'          => ['type' => 'string', 'description' => '來源節點名稱'],
                        'to'            => ['type' => 'string', 'description' => '目標節點名稱'],
                        'relation_type' => ['type' => 'string', 'description' => '關係類型（例如 calls_api、depends_on、deployed_on）'],
                    ],
                    'required' => ['from', 'to', 'relation_type'],
                ],
            ],
            [
                'name'        => 'delete_relation',
                'description' => '刪除指定的有向關係。需要 admin memory:mcp key。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'from'          => ['type' => 'string'],
                        'to'            => ['type' => 'string'],
                        'relation_type' => ['type' => 'string'],
                    ],
                    'required' => ['from', 'to', 'relation_type'],
                ],
            ],
            [
                'name'        => 'read_graph',
                'description' => '讀取知識圖譜。可指定 entity_name 只看該節點及其關係；不指定則回傳完整圖。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entity_name' => ['type' => 'string', 'description' => '篩選特定節點（選填）'],
                    ],
                ],
            ],
            [
                'name'        => 'search_nodes',
                'description' => '依名稱、type 或 observation 內容搜尋節點。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => '搜尋關鍵字'],
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
            'id'      => $id,
            'result'  => [
                'content' => [['type' => 'text', 'text' => $text]],
                'isError' => $isError,
            ],
        ]);
    }
}
