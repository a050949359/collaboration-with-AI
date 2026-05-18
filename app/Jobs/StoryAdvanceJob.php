<?php

namespace App\Jobs;

use App\Models\Story\StoryCharacter;
use App\Models\Story\StoryScene;
use App\Models\Story\StorySegment;
use App\Models\Story\StorySession;
use App\Services\AI\GeminiStoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class StoryAdvanceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries   = 1;

    public function __construct(public readonly int $sessionId) {}

    public function handle(GeminiStoryService $story): void
    {
        $session = StorySession::with(['characters', 'items.holder', 'currentCharacter'])
            ->find($this->sessionId);

        if ($session === null || $session->status !== 'active') {
            return;
        }

        $setting    = is_array($session->setting) ? json_encode($session->setting, JSON_UNESCAPED_UNICODE) : (string) $session->setting;
        $worldState = $session->world_state;
        $items      = $this->itemsPayload($session);

        $recentSegments = StorySegment::where('session_id', $session->id)
            ->orderByDesc('turn_number')
            ->limit(6)
            ->get()
            ->reverse()
            ->map(fn($s) => [
                'character' => $s->character?->name ?? '旁白',
                'content'   => $s->content,
            ])
            ->values()
            ->toArray();

        // Stall detection — inject external event before character turn
        if ($session->isStalled(threshold: 3)) {
            Log::info("StoryAdvanceJob: session {$session->id} stalled, injecting external event");

            $eventContent = $story->generateExternalEvent($setting, $worldState, $items, $recentSegments);

            $turnNumber = (StorySegment::where('session_id', $session->id)->max('turn_number') ?? 0) + 1;

            StorySegment::create([
                'session_id'        => $session->id,
                'character_id'      => null,
                'content'           => $eventContent,
                'turn_number'       => $turnNumber,
                'is_player_written' => false,
                'is_event'          => true,
            ]);

            $recentSegments[] = ['character' => '旁白', 'content' => $eventContent];

            $worldState = $story->updateWorldState($setting, $worldState, $eventContent, '旁白', $items);
            $session->update(['world_state' => $worldState, 'rounds_without_progress' => 0]);
        }

        $current = $session->currentCharacter;

        if ($current === null) {
            Log::warning("StoryAdvanceJob: session {$session->id} has no current character");
            return;
        }

        // Skip non-active characters
        if ($current->isSkippable()) {
            $this->advanceCharacter($session);
            $current = $session->fresh()->currentCharacter;
            if ($current === null || $current->isSkippable()) {
                Log::warning("StoryAdvanceJob: no active character in session {$session->id}");
                return;
            }
        }

        // Player turn — skip if not yet submitted (will be retried next interval)
        if ($current->type === 'player') {
            Log::info("StoryAdvanceJob: session {$session->id} waiting for player turn from {$current->name}");
            $this->scheduleNext($session);
            return;
        }

        // Resolve scene description for current location if mentioned in world_state
        $sceneDescription = $this->resolveScene($session, $setting, $worldState, $story);

        $characterPersona = $current->persona;
        if (is_array($current->model_config) && !empty($current->model_config['persona_append'])) {
            $characterPersona .= "\n" . $current->model_config['persona_append'];
        }

        $segmentContent = $story->generateSegment(
            setting: $setting,
            worldState: $worldState,
            characterName: $current->name,
            characterPersona: $characterPersona,
            recentSegments: $recentSegments,
            items: $items,
            contentRating: $session->content_rating,
            sceneDescription: $sceneDescription,
        );

        $turnNumber = (StorySegment::where('session_id', $session->id)->max('turn_number') ?? 0) + 1;

        StorySegment::create([
            'session_id'        => $session->id,
            'character_id'      => $current->id,
            'content'           => $segmentContent,
            'turn_number'       => $turnNumber,
            'is_player_written' => false,
            'is_event'          => false,
        ]);

        $newWorldState = $story->updateWorldState($setting, $worldState, $segmentContent, $current->name, $items);
        $session->update([
            'world_state'              => $newWorldState,
            'rounds_without_progress'  => $session->rounds_without_progress + 1,
        ]);

        $this->advanceCharacter($session);
        $this->scheduleNext($session);

        Log::info("StoryAdvanceJob: session {$session->id} turn {$turnNumber} done by {$current->name}");
    }

    /** @return array<int, array{name: string, description: string, holder: string|null}> */
    private function itemsPayload(StorySession $session): array
    {
        return $session->items->map(fn($item) => [
            'name'        => $item->name,
            'description' => $item->description,
            'holder'      => $item->holder?->name,
        ])->toArray();
    }

    private function advanceCharacter(StorySession $session): void
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

    private function scheduleNext(StorySession $session): void
    {
        $session->update([
            'next_advance_at' => now()->addMinutes($session->advance_interval_minutes),
        ]);

        StoryAdvanceJob::dispatch($session->id)
            ->delay(now()->addMinutes($session->advance_interval_minutes));
    }

    private function resolveScene(
        StorySession $session,
        string $setting,
        string $worldState,
        GeminiStoryService $story,
    ): ?string {
        // Extract location from world_state heuristically (first 【場景】 or 「at 」 mention)
        if (!preg_match('/【當前場景】[：:]\s*([^\n【]{2,30})/u', $worldState, $m)) {
            return null;
        }

        $locationName = trim($m[1]);

        $scene = StoryScene::firstOrCreate(
            ['session_id' => $session->id, 'location_name' => $locationName],
            [
                'description'     => $story->generateScene($setting, $locationName, $worldState),
                'first_visited_at' => now(),
            ],
        );

        return $scene->description;
    }
}
