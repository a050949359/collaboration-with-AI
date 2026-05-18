<?php

namespace App\Http\Controllers\Story;

use App\Http\Controllers\Controller;
use App\Http\Requests\Story\CreateSessionRequest;
use App\Http\Requests\Story\PlayerTurnRequest;
use App\Models\Story\StoryCharacter;
use App\Models\Story\StoryItem;
use App\Models\Story\StorySegment;
use App\Models\Story\StorySession;
use App\Services\AI\GeminiStoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class StorySessionController extends Controller
{
    public function __construct(private GeminiStoryService $story) {}

    public function store(CreateSessionRequest $request): JsonResponse
    {
        if (StorySession::where('status', 'active')->exists()) {
            throw ValidationException::withMessages([
                'status' => ['已有一個進行中的故事，請先暫停或完成後再建立新故事。'],
            ]);
        }

        $session = StorySession::create([
            'title'                    => $request->string('title')->toString(),
            'setting'                  => $request->array('setting'),
            'world_state'              => $request->input('setting.opening', ''),
            'advance_interval_minutes' => $request->integer('advance_interval_minutes', 120),
            'content_rating'           => $request->string('content_rating', 'general')->toString(),
        ]);

        foreach ($request->array('characters') as $index => $char) {
            StoryCharacter::create([
                'session_id'   => $session->id,
                'name'         => $char['name'],
                'persona'      => $char['persona'],
                'type'         => $char['type'] ?? 'llm',
                'model_config' => $char['model_config'] ?? null,
                'turn_order'   => $index,
            ]);
        }

        foreach ($request->array('items', []) as $item) {
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
            'status' => ['required', 'string', 'in:active,paused,completed'],
        ]);

        $newStatus = $request->string('status')->toString();

        if ($newStatus === 'active' && StorySession::where('status', 'active')->where('id', '!=', $session->id)->exists()) {
            throw ValidationException::withMessages([
                'status' => ['已有一個進行中的故事。'],
            ]);
        }

        if ($newStatus === 'active' && $session->status === 'paused') {
            $session->update([
                'status'          => 'active',
                'next_advance_at' => now()->addMinutes($session->advance_interval_minutes),
            ]);
        } else {
            $session->update(['status' => $newStatus]);
        }

        return response()->json(['status' => $session->fresh()->status]);
    }

    public function playerTurn(PlayerTurnRequest $request, StorySession $session): JsonResponse
    {
        if ($session->status !== 'active') {
            return response()->json(['message' => '故事目前不在進行狀態。'], 422);
        }

        $current = $session->currentCharacter;

        if ($current === null || $current->type !== 'player') {
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
