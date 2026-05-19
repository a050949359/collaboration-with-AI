<?php

namespace App\Jobs\Story;

use App\Models\Story\StoryCharacter;
use App\Models\Story\StoryScene;
use App\Models\Story\StorySegment;
use App\Models\Story\StorySession;
use App\Services\Story\GeminiStoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class StorySegmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 90;

    public function __construct(
        public readonly int $sessionId,
        public readonly int $characterId,
    ) {}

    public function handle(GeminiStoryService $story): void
    {
        $session   = StorySession::with(['items.holder'])->find($this->sessionId);
        $character = StoryCharacter::find($this->characterId);

        if ($session === null || $session->status !== 'active' || $character === null || $character->status !== 'active') {
            return;
        }

        $setting = is_array($session->setting)
            ? json_encode($session->setting, JSON_UNESCAPED_UNICODE)
            : (string) $session->setting;

        $recentSegments = StorySegment::where('session_id', $session->id)
            ->orderByDesc('turn_number')
            ->limit(6)
            ->get()
            ->reverse()
            ->map(fn($s) => ['character' => $s->character?->name ?? '旁白', 'content' => $s->content])
            ->values()
            ->toArray();

        $items = $session->items->map(fn($item) => [
            'name'        => $item->name,
            'description' => $item->description,
            'holder'      => $item->holder?->name,
        ])->toArray();

        $sceneDescription = $this->resolveScene($session, $setting, $story);

        $segmentContent = $story->generateSegment(
            setting: $setting,
            worldState: $session->world_state,
            characterName: $character->name,
            characterPersona: $character->persona,
            recentSegments: $recentSegments,
            items: $items,
            contentRating: $session->content_rating,
            sceneDescription: $sceneDescription,
            needsComplete: $session->needs_complete,
        );

        $turnNumber = (StorySegment::where('session_id', $session->id)->max('turn_number') ?? 0) + 1;

        StorySegment::create([
            'session_id'        => $session->id,
            'character_id'      => $character->id,
            'content'           => $segmentContent,
            'turn_number'       => $turnNumber,
            'is_player_written' => false,
            'is_event'          => false,
        ]);

        Log::info("StorySegment: session {$session->id} turn {$turnNumber} by {$character->name}");
    }

    private function resolveScene(StorySession $session, string $setting, GeminiStoryService $story): ?string
    {
        $location = $session->pending_scene_location;

        if ($location === null || $location === '') {
            return null;
        }

        $scene = StoryScene::firstOrCreate(
            ['session_id' => $session->id, 'location_name' => $location],
            [
                'description'      => $story->generateScene($setting, $location, $session->world_state),
                'first_visited_at' => now(),
            ],
        );

        return $scene->description;
    }
}
