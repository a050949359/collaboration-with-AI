<?php

namespace App\Services\Mcp;

use Illuminate\Http\JsonResponse;

interface McpToolServiceInterface
{
    public function toolSchemas(): array;

    public function canHandle(string $name): bool;

    public function call(string $name, array $args, mixed $id): JsonResponse;
}
