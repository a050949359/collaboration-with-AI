<?php

namespace App\Services\Mcp;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class AgydMcpService implements McpToolServiceInterface
{
    private const TOOLS = ['bg_run_prompt', 'bg_run_script', 'bg_list_scripts', 'bg_status', 'bg_log'];

    public function canHandle(string $name): bool
    {
        return \in_array($name, self::TOOLS);
    }

    public function call(string $name, array $args, mixed $id): JsonResponse
    {
        try {
            return match ($name) {
                'bg_run_prompt'   => $this->bgRunPrompt($id, $args),
                'bg_run_script'   => $this->bgRunScript($id, $args),
                'bg_list_scripts' => $this->bgListScripts($id),
                'bg_status'       => $this->bgStatus($id, $args),
                'bg_log'          => $this->bgLog($id, $args),
                default           => $this->text($id, "Unknown tool: $name", true),
            };
        } catch (\Throwable $e) {
            return $this->text($id, 'daemon connection error: ' . $e->getMessage(), true);
        }
    }

    // ── Tool implementations ──────────────────────────────────────

    private function bgRunPrompt(mixed $id, array $args): JsonResponse
    {
        $prompt = $args['prompt'] ?? '';
        $label  = $args['label'] ?? 'untitled';

        if (empty($prompt)) {
            return $this->text($id, 'prompt is required', true);
        }

        $resp = $this->daemon()->post('/run', compact('prompt', 'label'));

        if ($resp->failed()) {
            return $this->text($id, 'daemon error: ' . $resp->body(), true);
        }

        return $this->text($id, json_encode($resp->json(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function bgRunScript(mixed $id, array $args): JsonResponse
    {
        $name  = $args['name'] ?? '';
        $label = $args['label'] ?? $name;

        if (empty($name)) {
            return $this->text($id, 'name is required', true);
        }

        $resp = $this->daemon()->post('/run-script', compact('name', 'label'));

        if ($resp->failed()) {
            return $this->text($id, 'daemon error: ' . $resp->body(), true);
        }

        return $this->text($id, json_encode($resp->json(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function bgListScripts(mixed $id): JsonResponse
    {
        $resp = $this->daemon()->get('/scripts');

        if ($resp->failed()) {
            return $this->text($id, 'daemon error: ' . $resp->body(), true);
        }

        return $this->text($id, json_encode($resp->json(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function bgStatus(mixed $id, array $args): JsonResponse
    {
        $taskId = $args['task_id'] ?? '';
        if (empty($taskId)) {
            return $this->text($id, 'task_id is required', true);
        }

        $resp = $this->daemon()->get("/status/{$taskId}");

        if ($resp->failed()) {
            return $this->text($id, 'daemon error: ' . $resp->body(), true);
        }

        return $this->text($id, json_encode($resp->json(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function bgLog(mixed $id, array $args): JsonResponse
    {
        $taskId = $args['task_id'] ?? '';
        if (empty($taskId)) {
            return $this->text($id, 'task_id is required', true);
        }

        $resp = $this->daemon()->get("/log/{$taskId}");

        if ($resp->failed()) {
            return $this->text($id, 'daemon error: ' . $resp->body(), true);
        }

        return $this->text($id, json_encode($resp->json(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    // ── Tool schemas ──────────────────────────────────────────────

    public function toolSchemas(): array
    {
        return [
            [
                'name'        => 'bg_run_prompt',
                'description' => '在本地微型主機上以 agy 執行一個動態背景工作（非同步）。提供完整 prompt，agy 自主完成任務後 ZIP 推回 Laravel。回傳 task_id。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => '給 agy 的完整 prompt'],
                        'label'  => ['type' => 'string', 'description' => '工作標籤，方便辨識（選填）'],
                    ],
                    'required' => ['prompt'],
                ],
            ],
            [
                'name'        => 'bg_run_script',
                'description' => '在本地微型主機上執行一支預定義 script（非同步）。script 名稱須在 daemon 的白名單內，可先用 bg_list_scripts 查詢可用清單。回傳 task_id。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'  => ['type' => 'string', 'description' => 'script 名稱（由 bg_list_scripts 取得）'],
                        'label' => ['type' => 'string', 'description' => '工作標籤（選填，預設同 name）'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name'        => 'bg_list_scripts',
                'description' => '列出本地微型主機上所有可用的預定義 scripts，包含名稱與說明。',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
            [
                'name'        => 'bg_status',
                'description' => '查詢背景工作狀態。status 可能為 running / done / failed。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'string', 'description' => '由 bg_run_prompt 或 bg_run_script 回傳的 task ID'],
                    ],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name'        => 'bg_log',
                'description' => '取得背景工作的 stdout/stderr 輸出。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'string', 'description' => '由 bg_run_prompt 或 bg_run_script 回傳的 task ID'],
                    ],
                    'required' => ['task_id'],
                ],
            ],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function daemon(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(config('agyd.url'))
            ->withToken(config('agyd.secret'))
            ->timeout(10);
    }

    private function text(mixed $id, string $text, bool $isError = false): JsonResponse
    {
        $result = ['content' => [['type' => 'text', 'text' => $text]]];
        if ($isError) $result['isError'] = true;

        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }
}
