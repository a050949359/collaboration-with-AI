<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\Mcp\TaskMcpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskMcpController extends Controller
{
    public function __construct(private TaskMcpService $service) {}

    public function handle(Request $request): JsonResponse
    {
        $body   = $request->json()->all();
        $method = $body['method'] ?? '';
        $id     = $body['id'] ?? null;
        $params = $body['params'] ?? [];

        return match ($method) {
            'initialize'  => $this->initialize($id),
            'tools/list'  => $this->ok($id, ['tools' => $this->service->toolSchemas()]),
            'tools/call'  => $this->service->call(
                                $params['name'] ?? '',
                                $params['arguments'] ?? [],
                                $id,
                                $request->user()?->isAdmin() ?? false,
                            ),
            default       => $this->error($id, -32601, 'Method not found'),
        };
    }

    private function initialize(mixed $id): JsonResponse
    {
        return $this->ok($id, [
            'protocolVersion' => '2024-11-05',
            'capabilities'    => ['tools' => new \stdClass()],
            'serverInfo'      => ['name' => 'collab-tasks', 'version' => '1.0.0'],
        ]);
    }

    private function ok(mixed $id, array $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
