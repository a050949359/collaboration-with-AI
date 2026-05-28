<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\Mcp\McpEntity;
use App\Models\Mcp\McpRelation;
use Illuminate\Http\JsonResponse;

class MemoryGraphController extends Controller
{
    public function index(): JsonResponse
    {
        $entities = McpEntity::with('observations')->get()->map(fn($e) => [
            'id'           => $e->id,
            'name'         => $e->name,
            'type'         => $e->type,
            'observations' => $e->observations->pluck('content'),
        ]);

        $relations = McpRelation::with('from', 'to')->get()->map(fn($r) => [
            'from'          => $r->from->name,
            'relation_type' => $r->relation_type,
            'to'            => $r->to->name,
        ]);

        return response()->json(compact('entities', 'relations'));
    }
}
