<?php

namespace App\Services\Mcp;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * codegraph MCP 工具層:讓 coding agent 查靜態程式碼結構圖(callers/callees/impact/search)，
 * 不用反覆 grep + 讀檔燒 token。消費端是 LLM。
 *
 * 查詢端零 Go:圖是普通唯讀 SQLite(config connection 'codegraph'，由 `cmd/codegraph index` 產生)。
 * 邊涵蓋跨語言:CALLS(同語言函式呼叫) / HANDLES(route→controller) / HTTP_CALLS(前端 fetch→route)。
 * 只讀結構(id/type/name/file/line + 呼叫關係)，無原始碼內容。
 *
 * node id 各語言格式不同,輸出一律帶 type + lang 讓 LLM 分辨:
 *   Go   `(*pkg.T).M`、PHP `Ns\Class::method`、TS `relpath:name`、route 節點 `VERB /api/...`。
 */
class CodeGraphMcpService implements McpToolServiceInterface
{
    private const TOOLS = ['codegraph_search', 'codegraph_callers', 'codegraph_callees', 'codegraph_impact', 'codegraph_get_node'];

    /** 跨語言連通的邊型別;callers/callees/impact 只沿這些邊走。 */
    private const CALL_EDGES = ['CALLS', 'HANDLES', 'HTTP_CALLS'];

    /** impact 結果上限,防大扇出爆量。 */
    private const IMPACT_CAP = 500;

    public function canHandle(string $name): bool
    {
        return \in_array($name, self::TOOLS);
    }

    public function call(string $name, array $args, mixed $id): JsonResponse
    {
        if (! $this->indexed()) {
            return $this->text($id, 'codegraph 尚未建立索引(找不到 SQLite db)。請先在 repo 根目錄執行 `cmd/codegraph/codegraph index .`。', true);
        }

        try {
            return match ($name) {
                'codegraph_search' => $this->search($id, $args),
                'codegraph_callers' => $this->callers($id, $args),
                'codegraph_callees' => $this->callees($id, $args),
                'codegraph_impact' => $this->impact($id, $args),
                'codegraph_get_node' => $this->getNode($id, $args),
                default => $this->text($id, "Unknown tool: $name", true),
            };
        } catch (Throwable $e) {
            return $this->text($id, $e->getMessage(), true);
        }
    }

    // ── 工具實作 ────────────────────────────────────────────────────────

    /** 模糊找節點(name / qualified LIKE)。 */
    private function search(mixed $id, array $args): JsonResponse
    {
        $q = trim((string) ($args['query'] ?? ''));
        if ($q === '') {
            return $this->text($id, 'query 必填', true);
        }
        $like = '%'.$q.'%';
        $limit = $this->clampLimit($args['limit'] ?? 50);

        $rows = $this->conn()->table('nodes')
            ->where('name', 'like', $like)
            ->orWhere('qualified', 'like', $like)
            ->orderBy('qualified')
            ->limit($limit)
            ->get();

        return $this->json($id, ['nodes' => $this->shape($rows)]);
    }

    /** 誰呼叫了這個符號。 */
    private function callers(mixed $id, array $args): JsonResponse
    {
        return $this->neighbors($id, $args, 'to_id', 'from_id');
    }

    /** 這個符號呼叫了誰。 */
    private function callees(mixed $id, array $args): JsonResponse
    {
        return $this->neighbors($id, $args, 'from_id', 'to_id');
    }

    private function neighbors(mixed $id, array $args, string $matchCol, string $pickCol): JsonResponse
    {
        $ids = $this->resolve((string) ($args['symbol'] ?? ''));
        if ($ids === []) {
            return $this->json($id, ['symbol' => $args['symbol'] ?? '', 'matched' => [], 'nodes' => []]);
        }

        $rows = $this->neighborRows($ids, $matchCol, $pickCol);

        return $this->json($id, ['symbol' => $args['symbol'] ?? '', 'matched' => $ids, 'nodes' => $this->shape($rows)]);
    }

    /** 反向 BFS:改這個符號會連帶影響到誰(沿 caller 方向遞移)。 */
    private function impact(mixed $id, array $args): JsonResponse
    {
        $startIds = $this->resolve((string) ($args['symbol'] ?? ''));
        if ($startIds === []) {
            return $this->json($id, ['symbol' => $args['symbol'] ?? '', 'matched' => [], 'impacted' => []]);
        }

        $maxDepth = (int) ($args['depth'] ?? 0); // <=0 = 不限深度
        $visited = array_fill_keys($startIds, true); // 起點不列入結果
        $out = [];
        $frontier = $startIds;
        $capped = false;

        for ($depth = 1; $frontier !== []; $depth++) {
            if ($maxDepth > 0 && $depth > $maxDepth) {
                break;
            }
            $callers = $this->neighborRows($frontier, 'to_id', 'from_id');
            $next = [];
            foreach ($callers as $c) {
                if (isset($visited[$c->id])) {
                    continue;
                }
                $visited[$c->id] = true;
                $out[] = ['depth' => $depth] + $this->shapeOne($c);
                $next[] = $c->id;
                if (\count($out) >= self::IMPACT_CAP) {
                    $capped = true;
                    break 2;
                }
            }
            $frontier = $next;
        }

        return $this->json($id, [
            'symbol' => $args['symbol'] ?? '',
            'matched' => $startIds,
            'impacted' => $out,
            'capped' => $capped,
        ]);
    }

    /** 取單一節點細節(by id / qualified / name)。 */
    private function getNode(mixed $id, array $args): JsonResponse
    {
        $ids = $this->resolve((string) ($args['symbol'] ?? ''));
        if ($ids === []) {
            return $this->text($id, '找不到符號: '.($args['symbol'] ?? ''), true);
        }
        $rows = $this->conn()->table('nodes')->whereIn('id', $ids)->get();

        return $this->json($id, ['nodes' => $this->shape($rows)]);
    }

    // ── 共用 ────────────────────────────────────────────────────────────

    /** 把使用者給的符號(簡名 / 限定名 / id)對應到節點 id。 */
    private function resolve(string $sym): array
    {
        $sym = trim($sym);
        if ($sym === '') {
            return [];
        }

        return $this->conn()->table('nodes')
            ->where('id', $sym)->orWhere('qualified', $sym)->orWhere('name', $sym)
            ->orderBy('qualified')
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int, string>  $ids
     * @return Collection<int, object>
     */
    private function neighborRows(array $ids, string $matchCol, string $pickCol)
    {
        return $this->conn()->table('edges as e')
            ->join('nodes as n', 'n.id', '=', 'e.'.$pickCol)
            ->whereIn('e.type', self::CALL_EDGES)
            ->whereIn('e.'.$matchCol, $ids)
            ->distinct()
            ->orderBy('n.qualified')
            ->select('n.id', 'n.type', 'n.name', 'n.qualified', 'n.file', 'n.line')
            ->get();
    }

    private function indexed(): bool
    {
        $path = config('database.connections.codegraph.database');

        return \is_string($path) && is_file($path);
    }

    private function conn()
    {
        return DB::connection('codegraph');
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function shape($rows): array
    {
        return $rows->map(fn ($n) => $this->shapeOne($n))->all();
    }

    /** @return array<string, mixed> */
    private function shapeOne(object $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'name' => $n->name,
            'qualified' => $n->qualified,
            'file' => $n->file,
            'line' => $n->line,
            'lang' => $this->langOf((string) $n->file),
        ];
    }

    private function langOf(string $file): string
    {
        return match (true) {
            Str::endsWith($file, '.go') => 'go',
            Str::endsWith($file, '.php') => 'php',
            Str::endsWith($file, ['.ts', '.vue', '.js']) => 'ts',
            default => 'other',
        };
    }

    private function clampLimit(mixed $limit): int
    {
        return max(1, min(200, (int) $limit));
    }

    public function toolSchemas(): array
    {
        $symbol = ['type' => 'string', 'description' => '符號:可用 node id(如 Go `(*pkg.T).M`、PHP `Ns\\Class::method`、TS `relpath:name`)、限定名或簡名;多筆同名會一併處理'];

        return [
            [
                'name' => 'codegraph_search',
                'description' => '模糊搜尋節點(函式/方法/類別/route)的 name 或 qualified 名。用來把人類講的名字對應到精確的 node id,再餵給其他工具。',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => '關鍵字(LIKE 部分比對)'],
                    'limit' => ['type' => 'integer', 'description' => '上限,預設 50、最多 200'],
                ], 'required' => ['query']],
            ],
            [
                'name' => 'codegraph_callers',
                'description' => '誰呼叫了這個符號(反向一層)。沿 CALLS/HANDLES/HTTP_CALLS 邊,故 controller 會列出對應 route、後端 route 會列出呼叫它的前端 Vue 函式。',
                'inputSchema' => ['type' => 'object', 'properties' => ['symbol' => $symbol], 'required' => ['symbol']],
            ],
            [
                'name' => 'codegraph_callees',
                'description' => '這個符號呼叫了誰(正向一層)。',
                'inputSchema' => ['type' => 'object', 'properties' => ['symbol' => $symbol], 'required' => ['symbol']],
            ],
            [
                'name' => 'codegraph_impact',
                'description' => '改這個符號會連帶影響到誰:對 callers 做反向 BFS 遞移,回受影響節點 + 距離深度(depth)。結果上限 '.self::IMPACT_CAP.' 筆(capped=true 表示被截斷)。',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'symbol' => $symbol,
                    'depth' => ['type' => 'integer', 'description' => '最大追溯深度,省略或 <=0 = 不限'],
                ], 'required' => ['symbol']],
            ],
            [
                'name' => 'codegraph_get_node',
                'description' => '取符號對應節點的細節(id/type/name/qualified/file/line/lang)。',
                'inputSchema' => ['type' => 'object', 'properties' => ['symbol' => $symbol], 'required' => ['symbol']],
            ],
        ];
    }

    private function json(mixed $id, mixed $data): JsonResponse
    {
        return $this->text($id, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

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
