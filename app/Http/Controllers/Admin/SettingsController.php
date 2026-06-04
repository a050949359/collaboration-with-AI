<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AI\LlmManager;
use App\Support\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Settings');
    }

    public function show(): JsonResponse
    {
        return response()->json($this->getSettings());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_name'          => ['sometimes', 'string', 'max:80'],
            'maintenance_mode'   => ['sometimes', 'boolean'],
            'allow_registration' => ['sometimes', 'boolean'],
            'max_login_attempts' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'avatar_size'        => ['sometimes', 'integer', 'in:64,128,256'],
            'llm'                => ['sometimes', 'array'],
            'llm.*.provider'     => ['required', 'in:gemini,nvidia,ollama'],
            'llm.*.model'        => ['required', 'string', 'max:100'],
        ]);

        $current = $this->getSettings();
        $merged = array_merge($current, $validated);

        Cache::forever(AppSettings::CACHE_KEY, $merged);

        return response()->json([
            'message'  => '設定已更新',
            'settings' => $merged,
        ]);
    }

    /**
     * 連線測試：用指定 provider+model 送一個最小請求，回 ok/latency/reply。
     * 失敗也回 200（這是測試結果，不是 API 錯誤），讓前端內嵌顯示。
     */
    public function testLlm(Request $request, LlmManager $llm): JsonResponse
    {
        $validated = $request->validate([
            'provider'    => ['required', 'in:gemini,nvidia,ollama'],
            'model'       => ['required', 'string', 'max:100'],
            'with_schema' => ['sometimes', 'boolean'],
        ]);

        $options = [];
        if ($request->boolean('with_schema')) {
            $options['json_schema'] = [
                'type'       => 'object',
                'properties' => ['ok' => ['type' => 'boolean']],
                'required'   => ['ok'],
            ];
        }

        $start = microtime(true);

        try {
            $reply = $llm->driver($validated['provider'], $validated['model'])->generate(
                '你是連線測試助手，請用一句話簡短回覆。',
                [['role' => 'user', 'text' => 'ping']],
                $options,
            );

            return response()->json([
                'ok'         => true,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'reply'      => mb_substr($reply, 0, 500),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'         => false,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        return AppSettings::all();
    }
}
