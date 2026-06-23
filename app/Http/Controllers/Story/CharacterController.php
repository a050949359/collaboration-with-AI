<?php

namespace App\Http\Controllers\Story;

use App\Enums\ArticleAspectRatio;
use App\Enums\StoryGenre;
use App\Http\Controllers\Controller;
use App\Models\Story\Character;
use App\Services\AI\Contracts\GeneratesImage;
use App\Services\Story\LlmCharacterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CharacterController extends Controller
{
    public function __construct(
        private LlmCharacterService $ai,
        private GeneratesImage $images,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(
            Character::orderByDesc('updated_at')->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'persona' => ['nullable', 'string', 'max:500'],
            'secret' => ['nullable', 'string', 'max:500'],
            'background' => ['nullable', 'string', 'max:500'],
            'appearance' => ['nullable', 'array'],
            'outfit' => ['nullable', 'string', 'max:300'],
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
            'name' => ['sometimes', 'string', 'max:100'],
            'persona' => ['sometimes', 'string', 'max:500'],
            'secret' => ['nullable', 'string', 'max:500'],
            'background' => ['nullable', 'string', 'max:500'],
            'appearance' => ['nullable', 'array'],
            'outfit' => ['nullable', 'string', 'max:300'],
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
            'genre' => ['nullable', Rule::enum(StoryGenre::class)],
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
            'notes' => ['nullable', 'string', 'max:300'],
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

    /**
     * 用 image_prompt 實際生成角色立繪(同步):走共用的 GeneratesImage(key-based Gemini)
     * → re-encode webp 存 public disk → 把 image_path/image_url 寫回角色。
     * prompt 取 body 覆寫值,否則用角色已存的 image_prompt;皆空則 422。
     */
    public function generateImage(Request $request, Character $character): JsonResponse
    {
        $request->validate([
            'image_prompt' => ['nullable', 'string'],
            'aspect_ratio' => ['nullable', Rule::enum(ArticleAspectRatio::class)],
        ]);

        // body 帶非空 image_prompt 才覆寫;null / 空(含 {"image_prompt":null})退回角色已存的。
        // 用 input()+cast 而非 string($key,$default):後者對「鍵存在但為 null」不套 default,會誤成 ""。
        $prompt = trim((string) $request->input('image_prompt'));
        if ($prompt === '') {
            $prompt = trim((string) $character->image_prompt);
        }

        if ($prompt === '') {
            return response()->json([
                'message' => 'image_prompt is required; generate or fill it first.',
            ], 422);
        }

        // 角色立繪預設直幅(3:4);可由 body aspect_ratio 覆寫。
        // enum():null / 未帶 / 非法皆回 null → 套預設,避免把 null 當 "" 而誤落 1:1。
        $aspectRatio = $request->enum('aspect_ratio', ArticleAspectRatio::class)?->value
            ?? ArticleAspectRatio::R3x4->value;

        try {
            $result = $this->images->generate($prompt, 'characters', $aspectRatio);
        } catch (\Throwable $e) {
            // 生圖會發外部 HTTP + 檔案 IO,除 AIServiceException 外也可能拋連線/逾時等異常;
            // 一律收斂成 502,細節寫 log、對外回通用訊息(不外洩上游錯誤)。
            // 傳整個 Exception 物件:Monolog / Sentry 等會自動帶 stack trace + 檔案行號。
            Log::error('Character image generation failed', [
                'character_id' => $character->id,
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Image generation failed.'], 502);
        }

        $character->update([
            'image_prompt' => $prompt,
            'image_path' => $result['image_path'],
            'image_url' => $result['image_url'],
        ]);

        return response()->json([
            'image_path' => $result['image_path'],
            'image_url' => $result['image_url'],
        ]);
    }
}
