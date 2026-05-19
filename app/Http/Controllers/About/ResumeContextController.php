<?php

namespace App\Http\Controllers\About;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAdmin;
use App\Services\Chat\GeminiChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResumeContextController extends Controller
{
    public function __construct(private readonly GeminiChatService $gemini) {}

    public function show(): JsonResponse
    {
        return response()->json(['context' => $this->gemini->loadContext()]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'context' => ['required', 'string', 'max:20000'],
        ]);

        $this->gemini->saveContext($request->string('context')->toString());

        return response()->json(['message' => 'Context saved.']);
    }
}
