<?php

namespace App\Http\Controllers\Story;

use App\Http\Controllers\Controller;
use App\Http\Requests\Story\GenerateSetupRequest;
use App\Http\Requests\Story\RefineSetupRequest;
use App\Services\Story\GeminiStoryService;
use Illuminate\Http\JsonResponse;

class StorySetupController extends Controller
{
    public function __construct(private GeminiStoryService $story) {}

    public function generate(GenerateSetupRequest $request): JsonResponse
    {
        $raw = $this->story->generateSetup(
            $request->string('keywords')->toString(),
            $request->string('genre', 'fantasy')->toString(),
        );

        $setup = $this->parseJson($raw);

        return response()->json(['setup' => $setup]);
    }

    public function refine(RefineSetupRequest $request): JsonResponse
    {
        $raw = $this->story->refineSetup(
            $request->array('setup'),
            $request->string('notes', '')->toString(),
        );

        $setup = $this->parseJson($raw);

        return response()->json(['setup' => $setup]);
    }

    /** @return array<string, mixed> */
    private function parseJson(string $raw): array
    {
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $raw) ?? $raw;
        $clean = preg_replace('/\s*```$/m', '', $clean) ?? $clean;

        $decoded = json_decode(trim($clean), true);

        return is_array($decoded) ? $decoded : ['raw' => $raw];
    }
}
