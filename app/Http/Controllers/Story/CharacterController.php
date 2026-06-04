<?php

namespace App\Http\Controllers\Story;

use App\Enums\StoryGenre;
use App\Http\Controllers\Controller;
use App\Models\Story\Character;
use App\Services\Story\LlmCharacterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CharacterController extends Controller
{
    public function __construct(private LlmCharacterService $ai) {}

    public function index(): JsonResponse
    {
        return response()->json(
            Character::orderByDesc('updated_at')->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'       => ['nullable', 'string', 'max:100'],
            'persona'    => ['nullable', 'string', 'max:500'],
            'secret'     => ['nullable', 'string', 'max:500'],
            'background' => ['nullable', 'string', 'max:500'],
            'appearance' => ['nullable', 'array'],
            'outfit'     => ['nullable', 'string', 'max:300'],
        ]);

        $character = Character::create($request->only([
            'name', 'persona', 'secret', 'background', 'appearance', 'outfit',
        ]));

        return response()->json($character, 201);
    }

    public function show(Character $character): JsonResponse
    {
        return response()->json($character);
    }

    public function update(Request $request, Character $character): JsonResponse
    {
        $request->validate([
            'name'         => ['sometimes', 'string', 'max:100'],
            'persona'      => ['sometimes', 'string', 'max:500'],
            'secret'       => ['nullable', 'string', 'max:500'],
            'background'   => ['nullable', 'string', 'max:500'],
            'appearance'   => ['nullable', 'array'],
            'outfit'       => ['nullable', 'string', 'max:300'],
            'image_prompt' => ['nullable', 'string'],
        ]);

        $character->update($request->only([
            'name', 'persona', 'secret', 'background', 'appearance', 'outfit', 'image_prompt',
        ]));

        return response()->json($character->fresh());
    }

    public function destroy(Character $character): JsonResponse
    {
        $character->delete();

        return response()->json(null, 204);
    }

    // ── AI endpoints ──────────────────────────────────────────

    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'description' => ['nullable', 'string', 'max:500'],
            'genre'       => ['nullable', Rule::enum(StoryGenre::class)],
        ]);

        $data = $this->ai->generate(
            $request->string('description', '')->toString(),
            $request->string('genre', StoryGenre::Fantasy->value)->toString(),
        );

        return response()->json(['character' => $data]);
    }

    public function refine(Request $request): JsonResponse
    {
        $request->validate([
            'character' => ['required', 'array'],
            'notes'     => ['nullable', 'string', 'max:300'],
        ]);

        $data = $this->ai->refine(
            $request->array('character'),
            $request->string('notes', '')->toString(),
        );

        return response()->json(['character' => $data]);
    }

    public function generateImagePrompt(Request $request, Character $character): JsonResponse
    {
        $request->validate([
            'character' => ['nullable', 'array'],
        ]);

        $data = $request->has('character')
            ? [...$character->toArray(), ...$request->array('character')]
            : $character->toArray();

        $imagePrompt = $this->ai->generateImagePrompt($data);

        $character->update(['image_prompt' => $imagePrompt]);

        return response()->json(['image_prompt' => $imagePrompt]);
    }
}
