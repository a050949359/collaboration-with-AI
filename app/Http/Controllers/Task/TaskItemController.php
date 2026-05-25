<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskItem;
use App\Services\Task\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class TaskItemController extends Controller
{
    public function __construct(private TaskService $service) {}

    public function store(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate([
            'content' => 'required|string|max:500',
            'sort'    => 'integer',
        ]);
        try {
            $item = $this->service->addItem($task, $data, Auth::id());
            return response()->json($item, 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, Task $task, TaskItem $item): JsonResponse
    {
        $data = $request->validate([
            'content' => 'sometimes|string|max:500',
            'is_done' => 'sometimes|boolean',
            'sort'    => 'sometimes|integer',
        ]);
        try {
            $item = $this->service->updateItem($item, $data, Auth::id());
            return response()->json($item);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function destroy(Task $task, TaskItem $item): JsonResponse
    {
        try {
            $this->service->deleteItem($item, Auth::id());
            return response()->json(['message' => 'deleted']);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
