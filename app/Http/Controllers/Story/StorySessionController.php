<?php

namespace App\Http\Controllers\Story;

use App\Enums\StoryCharacterType;
use App\Enums\StoryContentRating;
use App\Enums\StorySessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Story\CreateSessionRequest;
use App\Http\Requests\Story\PlayerTurnRequest;
use App\Jobs\Story\StoryOrchestrateJob;
use App\Models\Story\StoryCharacter;
use App\Models\Story\StoryItem;
use App\Models\Story\StorySegment;
use App\Models\Story\StorySession;
use App\Services\Story\GeminiStoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StorySessionController extends Controller
{
    public function __construct(private GeminiStoryService $story) {}

    public function index(): JsonResponse
    {
        $sessions = StorySession::select(['id', 'title', 'status', 'content_rating', 'next_advance_at', 'updated_at'])
            ->orderByDesc('updated_at')
            ->get();

        return response()->json($sessions);
    }

    public function store(CreateSessionRequest $request): JsonResponse
    {
        if (StorySession::where('status', StorySessionStatus::Active)->exists()) {
            throw ValidationException::withMessages([
                'status' => ['已有一個進行中的故事，請先暫停或完成後再建立新故事。'],
            ]);
        }

        $session = StorySession::create([
            'title'                    => $request->string('title')->toString(),
            'setting'                  => $request->array('setting'),
            'world_state'              => $request->input('setting.opening', ''),
            'advance_interval_minutes' => $request->integer('advance_interval_minutes', 30),
            'rounds_per_advance'       => $request->integer('rounds_per_advance', 1),
            'content_rating'           => $request->string('content_rating', StoryContentRating::General->value)->toString(),
        ]);

        foreach ($request->array('characters') as $index => $char) {
            StoryCharacter::create([
                'session_id'   => $session->id,
                'name'         => $char['name'],
                'persona'      => $char['persona'],
                'type'         => $char['type'] ?? StoryCharacterType::Llm->value,
                'model_config' => $char['model_config'] ?? null,
                'turn_order'   => $index,
                'is_narrator'  => $char['is_narrator'] ?? true,
            ]);
        }

        foreach ((array) $request->input('items', []) as $item) {
            $holderCharacter = null;
            if (!empty($item['holder'])) {
                $holderCharacter = $session->characters()
                    ->where('name', $item['holder'])
                    ->first();
            }

            StoryItem::create([
                'session_id'          => $session->id,
                'name'                => $item['name'],
                'description'         => $item['description'],
                'holder_character_id' => $holderCharacter?->id,
                'is_preset'           => true,
            ]);
        }

        $firstCharacter = $session->characters()->first();
        $session->update(['current_character_id' => $firstCharacter?->id]);

        return response()->json(
            $session->load(['characters', 'items']),
            201,
        );
    }

    public function show(StorySession $session): JsonResponse
    {
        return response()->json(
            $session->load([
                'characters',
                'items.holder',
                'segments.character',
                'currentCharacter',
            ]),
        );
    }

    public function updateStatus(Request $request, StorySession $session): JsonResponse
    {
        $request->validate([
            'status' => ['required', Rule::enum(StorySessionStatus::class)],
        ]);

        $newStatus = StorySessionStatus::from($request->string('status')->toString());

        if ($newStatus === StorySessionStatus::Active && StorySession::where('status', StorySessionStatus::Active)->where('id', '!=', $session->id)->exists()) {
            throw ValidationException::withMessages([
                'status' => ['已有一個進行中的故事。'],
            ]);
        }

        if ($newStatus === StorySessionStatus::Active && $session->status === StorySessionStatus::Paused) {
            $session->update([
                'status'          => StorySessionStatus::Active,
                'next_advance_at' => now()->addMinutes($session->advance_interval_minutes),
            ]);

            StoryOrchestrateJob::dispatch($session->id);
        } elseif ($newStatus === StorySessionStatus::Completed && in_array($session->status, [StorySessionStatus::Active, StorySessionStatus::Paused], true)) {
            $currentMaxTurn = $session->segments()->max('turn_number') ?? 0;

            $session->update([
                'status'                 => StorySessionStatus::Active,
                'needs_complete'         => true,
                'complete_deadline_turn' => $currentMaxTurn + 20,
                'next_advance_at'        => now()->addMinutes($session->advance_interval_minutes),
            ]);

            StoryOrchestrateJob::dispatch($session->id);
        } else {
            $session->update(['status' => $newStatus]);
        }

        return response()->json(['status' => $session->fresh()->status]);
    }

    public function playerTurn(PlayerTurnRequest $request, StorySession $session): JsonResponse
    {
        if ($session->status !== StorySessionStatus::Active) {
            return response()->json(['message' => '故事目前不在進行狀態。'], 422);
        }

        $current = $session->currentCharacter;

        if ($current === null || $current->type !== StoryCharacterType::Player) {
            return response()->json(['message' => '目前輪到的角色不是玩家角色。'], 422);
        }

        $turnNumber = $session->segments()->max('turn_number') + 1;

        $segment = StorySegment::create([
            'session_id'       => $session->id,
            'character_id'     => $current->id,
            'content'          => $request->string('content')->toString(),
            'turn_number'      => $turnNumber,
            'is_player_written' => true,
        ]);

        $this->advanceToNextCharacter($session);

        return response()->json($segment->load('character'), 201);
    }

    private function advanceToNextCharacter(StorySession $session): void
    {
        $characters = $session->characters()->where('status', 'active')->get();

        if ($characters->isEmpty()) {
            return;
        }

        $currentOrder = $session->currentCharacter?->turn_order ?? -1;
        $next = $characters->firstWhere('turn_order', '>', $currentOrder)
            ?? $characters->first();

        $session->update(['current_character_id' => $next->id]);
    }
}
