<?php

namespace App\Services\Task;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

class TaskService
{
    public function listTasks(?string $status = null, ?string $project = null): Collection
    {
        $query = Task::with('items')->orderBy('sort')->orderBy('id');
        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($project !== null) {
            $query->where('project', $project);
        }
        return $query->get();
    }

    public function getTask(int $id): ?Task
    {
        return Task::with('items')->find($id);
    }

    public function createTask(array $data, int $userId): Task
    {
        $task = new Task([
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'project'     => $data['project'] ?? null,
            'status'      => $data['status'] ?? TaskStatus::Todo,
            'sort'        => $data['sort'] ?? 0,
        ]);
        $task->created_by = $userId;
        $task->save();
        return $task->load('items');
    }

    /** @throws AuthorizationException */
    public function updateTask(Task $task, array $data, int $userId): Task
    {
        $this->assertOwner($task->created_by, $userId);
        $task->update(array_filter([
            'title'       => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'project'     => $data['project'] ?? null,
            'status'      => $data['status'] ?? null,
            'sort'        => $data['sort'] ?? null,
        ], fn($v) => $v !== null));
        return $task->load('items');
    }

    /** @throws AuthorizationException */
    public function deleteTask(Task $task, int $userId): void
    {
        $this->assertOwner($task->created_by, $userId);
        $task->delete();
    }

    /** @throws AuthorizationException */
    public function addItem(Task $task, array $data, int $userId): TaskItem
    {
        $this->assertOwner($task->created_by, $userId);
        return $task->items()->create([
            'content' => $data['content'],
            'sort'    => $data['sort'] ?? 0,
        ]);
    }

    /** @throws AuthorizationException */
    public function updateItem(TaskItem $item, array $data, int $userId): TaskItem
    {
        $this->assertOwner($item->task->created_by, $userId);
        $item->update(array_filter([
            'content' => $data['content'] ?? null,
            'is_done' => isset($data['is_done']) ? (bool) $data['is_done'] : null,
            'sort'    => $data['sort'] ?? null,
        ], fn($v) => $v !== null));
        return $item;
    }

    /** @throws AuthorizationException */
    public function deleteItem(TaskItem $item, int $userId): void
    {
        $this->assertOwner($item->task->created_by, $userId);
        $item->delete();
    }

    private function assertOwner(int $ownerId, int $userId): void
    {
        if ($ownerId !== $userId) {
            throw new AuthorizationException('Forbidden: not your task.');
        }
    }
}
