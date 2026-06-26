<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\AI\AIServiceException;
use App\Services\AI\Gemini\GeminiToolsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gemini tools 對外 API：Google Search grounding / Google Maps grounding。
 *
 * 皆 auth:sanctum + throttle（路由層）。GEMINI_API_KEY 永遠不出後端，
 * 外部只拿到模型答案與引用 metadata。AI 失敗統一回 502，不噴 500。
 */
class ToolsController extends Controller
{
    /**
     * Google Search grounding：回答 + 引用來源 + 搜尋查詢 + search-entry-point HTML。
     *
     * ⚠️ Google ToS：若前端要呈現此結果，必須一併顯示 search_entry_point（Search Suggestions）。
     */
    public function search(Request $request, GeminiToolsService $tools): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'system' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = $tools->searchGrounded(
                $validated['query'],
                $this->options($validated),
            );
        } catch (AIServiceException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (\Throwable $e) {
            // 非預期錯誤（型別/網路等）：report 進 log 便於排查，對外仍回通用 502。
            report($e);

            return response()->json(['message' => 'AI 服務呼叫失敗'], 502);
        }

        return response()->json($result);
    }

    /**
     * Google Maps grounding：回答 + 引用地點 + 可嵌入互動地圖的 widget token。
     * 可選 lat/lng 把結果偏向某地理位置（兩者必須成對）。
     *
     * Maps grounding 為 preview，免費 key 可能回 400/403，前端應容忍 502。
     */
    public function map(Request $request, GeminiToolsService $tools): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'system' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        $options = $this->options($validated);
        if (isset($validated['lat'], $validated['lng'])) {
            $options['lat'] = (float) $validated['lat'];
            $options['lng'] = (float) $validated['lng'];
        }

        try {
            $result = $tools->mapGrounded($validated['query'], $options);
        } catch (AIServiceException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (\Throwable $e) {
            // 非預期錯誤（型別/網路等）：report 進 log 便於排查，對外仍回通用 502。
            report($e);

            return response()->json(['message' => 'AI 服務呼叫失敗'], 502);
        }

        return response()->json($result);
    }

    /**
     * 把已驗證輸入轉成 service options（只取 service 認得的鍵）。
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function options(array $validated): array
    {
        $options = [];
        if (! empty($validated['system'])) {
            $options['system'] = (string) $validated['system'];
        }

        return $options;
    }
}
