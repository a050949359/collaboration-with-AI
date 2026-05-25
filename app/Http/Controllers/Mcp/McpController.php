<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\TaskItem;
use App\Services\Task\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class McpController extends Controller
{
    public function __construct(private TaskService $service) {}

    public function handle(Request $request): JsonResponse
    {
        $body   = $request->json()->all();
        $method = $body['method'] ?? '';
        $id     = $body['id'] ?? null;
        $params = $body['params'] ?? [];

        return match ($method) {
            'initialize'  => $this->initialize($id),
            'tools/list'  => $this->toolsList($id),
            'tools/call'  => $this->toolsCall($id, $params),
            default       => $this->error($id, -32601, 'Method not found'),
        };
    }

    // ── JSON-RPC helpers ─────────────────────────────────────────

    private function ok(mixed $id, array $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    private function text(mixed $id, string $text, bool $isError = false): JsonResponse
    {
        return $this->ok($id, [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $isError,
        ]);
    }

    // ── MCP methods ──────────────────────────────────────────────

    private function initialize(mixed $id): JsonResponse
    {
        return $this->ok($id, [
            'protocolVersion' => '2024-11-05',
            'capabilities'    => ['tools' => new \stdClass()],
            'serverInfo'      => ['name' => 'collaboration-with-ai', 'version' => config('services.mcp.version', '1.0.0')],
        ]);
    }

    private function toolsList(mixed $id): JsonResponse
    {
        return $this->ok($id, ['tools' => $this->tools()]);
    }

    private function toolsCall(mixed $id, array $params): JsonResponse
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        // 需要認證的工具
        $authRequired = ['list_tasks', 'get_task', 'create_task', 'update_task', 'delete_task', 'add_task_item', 'update_task_item', 'delete_task_item'];
        if (\in_array($name, $authRequired) && ! Auth::check()) {
            return $this->text($id, 'Unauthorized: API key required.', true);
        }

        return match ($name) {
            'list_tasks'       => $this->toolListTasks($id, $args),
            'get_task'         => $this->toolGetTask($id, $args),
            'create_task'      => $this->toolCreateTask($id, $args),
            'update_task'      => $this->toolUpdateTask($id, $args),
            'delete_task'      => $this->toolDeleteTask($id, $args),
            'add_task_item'    => $this->toolAddTaskItem($id, $args),
            'update_task_item' => $this->toolUpdateTaskItem($id, $args),
            'delete_task_item' => $this->toolDeleteTaskItem($id, $args),
            default            => $this->text($id, "Unknown tool: $name", true),
        };
    }

    // ── Tools ────────────────────────────────────────────────────

    private function toolListTasks(mixed $id, array $args): JsonResponse
    {
        $tasks = $this->service->listTasks($args['status'] ?? null);
        return $this->text($id, json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function toolGetTask(mixed $id, array $args): JsonResponse
    {
        $task = $this->service->getTask($args['id'] ?? 0);
        if (! $task) return $this->text($id, 'Task not found.', true);
        return $this->text($id, json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function toolCreateTask(mixed $id, array $args): JsonResponse
    {
        $task = $this->service->createTask($args, Auth::id());
        return $this->text($id, json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function toolUpdateTask(mixed $id, array $args): JsonResponse
    {
        $task = $this->service->getTask($args['id'] ?? 0);
        if (! $task) return $this->text($id, 'Task not found.', true);
        try {
            $task = $this->service->updateTask($task, $args, Auth::id());
            return $this->text($id, json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } catch (AuthorizationException $e) {
            return $this->text($id, $e->getMessage(), true);
        }
    }

    private function toolDeleteTask(mixed $id, array $args): JsonResponse
    {
        $task = $this->service->getTask($args['id'] ?? 0);
        if (! $task) return $this->text($id, 'Task not found.', true);
        try {
            $this->service->deleteTask($task, Auth::id());
            return $this->text($id, 'Task deleted.');
        } catch (AuthorizationException $e) {
            return $this->text($id, $e->getMessage(), true);
        }
    }

    private function toolAddTaskItem(mixed $id, array $args): JsonResponse
    {
        $task = $this->service->getTask($args['task_id'] ?? 0);
        if (! $task) return $this->text($id, 'Task not found.', true);
        try {
            $item = $this->service->addItem($task, $args, Auth::id());
            return $this->text($id, json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } catch (AuthorizationException $e) {
            return $this->text($id, $e->getMessage(), true);
        }
    }

    private function toolUpdateTaskItem(mixed $id, array $args): JsonResponse
    {
        $item = TaskItem::with('task')->find($args['id'] ?? null);
        if (! $item) return $this->text($id, 'Item not found.', true);
        try {
            $item = $this->service->updateItem($item, $args, Auth::id());
            return $this->text($id, json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } catch (AuthorizationException $e) {
            return $this->text($id, $e->getMessage(), true);
        }
    }

    private function toolDeleteTaskItem(mixed $id, array $args): JsonResponse
    {
        $item = TaskItem::with('task')->find($args['id'] ?? null);
        if (! $item) return $this->text($id, 'Item not found.', true);
        try {
            $this->service->deleteItem($item, Auth::id());
            return $this->text($id, 'Item deleted.');
        } catch (AuthorizationException $e) {
            return $this->text($id, $e->getMessage(), true);
        }
    }

    // ── Tool schemas ─────────────────────────────────────────────

    private function tools(): array
    {
        return [
            [
                'name'        => 'list_tasks',
                'description' => '列出所有任務（含子項目）。可用 status 篩選。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['todo', 'in_progress', 'done'], 'description' => '篩選狀態（選填）'],
                    ],
                ],
            ],
            [
                'name'        => 'get_task',
                'description' => '取得單一任務詳情（含子項目）。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'description' => '任務 ID']],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'create_task',
                'description' => '新增任務。需要 API Key 認證。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'title'       => ['type' => 'string', 'description' => '任務標題'],
                        'description' => ['type' => 'string', 'description' => '任務描述（選填）'],
                        'status'      => ['type' => 'string', 'enum' => ['todo', 'in_progress', 'done']],
                        'sort'        => ['type' => 'integer'],
                    ],
                    'required' => ['title'],
                ],
            ],
            [
                'name'        => 'update_task',
                'description' => '更新任務標題、描述或狀態。需要 API Key 認證。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'          => ['type' => 'integer'],
                        'title'       => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'status'      => ['type' => 'string', 'enum' => ['todo', 'in_progress', 'done']],
                        'sort'        => ['type' => 'integer'],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name'        => 'delete_task',
                'description' => '刪除任務（含所有子項目）。需要 API Key 認證。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'add_task_item',
                'description' => '新增子項目到指定任務。需要 API Key 認證。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer'],
                        'content' => ['type' => 'string'],
                        'sort'    => ['type' => 'integer'],
                    ],
                    'required' => ['task_id', 'content'],
                ],
            ],
            [
                'name'        => 'update_task_item',
                'description' => '更新子項目內容或完成狀態。需要 API Key 認證。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'      => ['type' => 'integer'],
                        'content' => ['type' => 'string'],
                        'is_done' => ['type' => 'boolean'],
                        'sort'    => ['type' => 'integer'],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name'        => 'delete_task_item',
                'description' => '刪除子項目。需要 API Key 認證。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required'   => ['id'],
                ],
            ],
        ];
    }
}
