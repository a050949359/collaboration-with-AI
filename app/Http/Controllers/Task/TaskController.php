<?php

namespace App\Http\Controllers\Task;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\Task\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function __construct(private TaskService $service) {}

    public function index(): JsonResponse
    {
        return response()->json($this->service->listTasks());
    }

    public function show(Task $task): JsonResponse
    {
        return response()->json($task->load('items'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'status'      => ['nullable', Rule::enum(TaskStatus::class)],
            'sort'        => 'integer',
        ]);
        $task = $this->service->createTask($data, Auth::id());
        return response()->json($task, 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status'      => ['sometimes', Rule::enum(TaskStatus::class)],
            'sort'        => 'sometimes|integer',
        ]);
        try {
            $task = $this->service->updateTask($task, $data, Auth::id());
            return response()->json($task);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function destroy(Task $task): JsonResponse
    {
        try {
            $this->service->deleteTask($task, Auth::id());
            return response()->json(['message' => 'deleted']);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
