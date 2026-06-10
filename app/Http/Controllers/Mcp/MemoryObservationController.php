<?php

namespace App\Http\Controllers\Mcp;

use App\Enums\ObservationType;
use App\Http\Controllers\Controller;
use App\Models\Mcp\McpEntity;
use App\Models\Mcp\McpObservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 知識圖譜 typed observation 的 admin 端點（編輯面板用）。
 * 公開的 desc / graph / 各 type 讀取在 MemoryGraphController；此處只處理需 admin 的操作。
 */
class MemoryObservationController extends Controller
{
    /** 回傳某 entity 的全部 typed（非 desc）觀察，供編輯面板顯示與增刪改。 */
    public function typed(McpEntity $entity): JsonResponse
    {
        $observations = $entity->observations()
            ->exceptDefaultType()
            ->get(['id', 'type', 'content'])
            ->map(fn ($o) => [
                'id' => $o->id,
                'type' => $o->type->value,
                'content' => $o->content,
            ]);

        return response()->json([
            'entity' => ['id' => $entity->id, 'name' => $entity->name, 'type' => $entity->type],
            'observations' => $observations,
        ]);
    }

    /** 新增 typed 觀察。desc 不可由此寫入；受該 type 的 maxCount 限制。 */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_id' => ['required', 'integer', 'exists:mcp_entities,id'],
            'type' => ['required', Rule::enum(ObservationType::class)->except([ObservationType::Desc])],
            'content' => ['required', 'string'],
        ]);

        $type = ObservationType::from($data['type']);
        $this->assertWithinMax($data['entity_id'], $type, null);

        $obs = McpObservation::create($data);

        return response()->json(['id' => $obs->id], 201);
    }

    /** 更新 typed 觀察的 content / type。改 type 時重新檢查 maxCount。 */
    public function update(Request $request, McpObservation $observation): JsonResponse
    {
        $this->assertNotDesc($observation);

        $data = $request->validate([
            'type' => ['sometimes', Rule::enum(ObservationType::class)->except([ObservationType::Desc])],
            'content' => ['sometimes', 'string'],
        ]);

        if (isset($data['type']) && $data['type'] !== $observation->type->value) {
            $this->assertWithinMax($observation->entity_id, ObservationType::from($data['type']), $observation->id);
        }

        $observation->fill($data)->save();

        return response()->json(['id' => $observation->id]);
    }

    /** 刪除 typed 觀察。 */
    public function destroy(McpObservation $observation): JsonResponse
    {
        $this->assertNotDesc($observation);
        $observation->delete();

        return response()->json(['ok' => true]);
    }

    /** desc 觀察一律走 MCP，不可由此 admin 端點增刪改。 */
    private function assertNotDesc(McpObservation $observation): void
    {
        if ($observation->type === ObservationType::Desc) {
            abort(403, 'desc 觀察不可透過此端點操作');
        }
    }

    /** 檢查 entity 同 type 的筆數是否已達 maxCount（null=無限）。excludeId 用於 update 排除自身。 */
    private function assertWithinMax(int $entityId, ObservationType $type, ?int $excludeId): void
    {
        $max = $type->maxCount();
        if ($max === null) {
            return;
        }

        $count = McpObservation::where('entity_id', $entityId)
            ->ofType($type)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->count();

        if ($count >= $max) {
            abort(422, "{$type->value} 已達上限 {$max} 筆");
        }
    }
}
