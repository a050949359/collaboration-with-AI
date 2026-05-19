<?php

namespace App\Http\Controllers\About;

use App\Http\Controllers\Controller;
use App\Services\AI\AIServiceException;
use App\Services\Chat\GeminiChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AboutController extends Controller
{
    public function __construct(private readonly GeminiChatService $gemini) {}

    public function ask(Request $request): JsonResponse
    {
        $request->validate([
            'message'         => ['required', 'string', 'max:500'],
            'history'         => ['nullable', 'array', 'max:20'],
            'history.*.role'  => ['required', 'in:user,model'],
            'history.*.text'  => ['required', 'string', 'max:2000'],
        ]);

        try {
            $reply = $this->gemini->chat(
                message: $request->string('message')->toString(),
                history: $request->input('history', []),
            );

            return response()->json(['reply' => $reply]);
        } catch (AIServiceException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }
}
