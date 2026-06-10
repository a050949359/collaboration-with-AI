<?php

namespace App\Http\Controllers\Mcp;

use App\Enums\ObservationType;
use App\Http\Controllers\Controller;
use App\Models\Mcp\McpEntity;
use App\Models\Mcp\McpObservation;
use App\Models\Mcp\McpRelation;
use App\Services\MicroHost\MicroHostStatus;
use Illuminate\Http\JsonResponse;

class MemoryGraphController extends Controller
{
    public function index(MicroHostStatus $microHost): JsonResponse
    {
        $entities = McpEntity::withCount(['observations' => fn ($q) => $q->ofDefaultType()])->get()->map(fn ($e) => [
            'id' => $e->id,
            'name' => $e->name,
            'type' => $e->type,
            'observation_count' => $e->observations_count,
        ]);

        $relations = McpRelation::with('from', 'to')->get()->map(fn ($r) => [
            'from' => $r->from->name,
            'relation_type' => $r->relation_type,
            'to' => $r->to->name,
        ]);

        // 微型主機在線時動態塞入該主機節點（payload 自帶 host name，離線就不塞）。
        // 與圖譜中靜態的 ZeroTier 連線（from = hostname）對接，節點一出現連線即生效。
        $micro = $microHost->full();
        if (
            $micro['status'] === 'online'
            && ! empty($micro['host'])
            && ! $entities->contains(fn ($e) => $e['name'] === $micro['host'])
        ) {
            $entities->push([
                'id' => -1,
                'name' => $micro['host'],
                'type' => 'host',
                'observation_count' => 0,
            ]);
        }

        return response()->json(compact('entities', 'relations'));
    }

    /** 公開：回傳所有 geo 觀察（entity 名稱/type + 座標），供 globe 視圖繪點。 */
    public function geo(): JsonResponse
    {
        $points = McpObservation::with('entity:id,name,type')
            ->ofType(ObservationType::Geo)
            ->get()
            ->map(fn ($o) => [
                'entity' => $o->entity?->name,
                'type' => $o->entity?->type,
                'content' => $o->content,
            ]);

        return response()->json($points);
    }
}
