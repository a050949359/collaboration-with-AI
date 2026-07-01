<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * codegraph 靜態程式碼結構圖（唯讀視覺化）。
 * 資料由 cmd/codegraph index 產生於 SQLite（config connection 'codegraph'）。
 * 純結構（名稱 + 呼叫關係），無原始碼內容；repo 本身即 public。
 */
class CodeGraphController extends Controller
{
    public function index(): JsonResponse
    {
        $dbPath = config('database.connections.codegraph.database');
        if (! is_string($dbPath) || ! is_file($dbPath)) {
            return response()->json(['indexed' => false, 'nodes' => [], 'edges' => [], 'stats' => new \stdClass]);
        }

        $conn = DB::connection('codegraph');

        $nodes = $conn->table('nodes')
            ->select('id', 'type', 'name', 'file', 'line')
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'name' => $n->name,
                'file' => $n->file,
                'line' => $n->line,
                'lang' => $this->langOf($n->file),
            ]);

        // 邊用 source/target（對齊 d3 慣例）；只留兩端都在節點集內的邊
        $ids = $nodes->pluck('id')->flip();
        $edges = $conn->table('edges')
            ->select('from_id', 'to_id', 'type', 'confidence')
            ->get()
            ->filter(fn ($e) => $ids->has($e->from_id) && $ids->has($e->to_id))
            ->map(fn ($e) => [
                'source' => $e->from_id,
                'target' => $e->to_id,
                'type' => $e->type,
                'confidence' => (float) $e->confidence,
            ])
            ->values();

        $stats = [
            'nodes' => $nodes->count(),
            'edges' => $edges->count(),
            'lang' => $nodes->groupBy('lang')->map->count(),
        ];

        return response()->json([
            'indexed' => true,
            'nodes' => $nodes->values(),
            'edges' => $edges,
            'stats' => $stats,
        ]);
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
}
