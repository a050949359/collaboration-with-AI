<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function success(mixed $data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    protected function error(string $message = 'Error', int $code = 400, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
            'errors'  => $errors,
        ], $code);
    }

    protected function paginated(mixed $resource): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => $resource->items(),
            'meta'   => [
                'current_page' => $resource->currentPage(),
                'last_page'    => $resource->lastPage(),
                'per_page'     => $resource->perPage(),
                'total'        => $resource->total(),
            ],
            'links' => [
                'first' => $resource->url(1),
                'last'  => $resource->url($resource->lastPage()),
                'prev'  => $resource->previousPageUrl(),
                'next'  => $resource->nextPageUrl(),
            ],
        ]);
    }
}