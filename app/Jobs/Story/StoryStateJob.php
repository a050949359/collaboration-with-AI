<?php

namespace App\Jobs\Story;

use App\Enums\StorySessionStatus;
use App\Models\Story\StorySegment;
use App\Models\Story\StorySession;
use App\Services\Story\LlmStoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class StoryStateJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 60;

    /**
     * @param int $lastCharacterId  The last character that acted this round;
     *                              stored as current_character_id so the next
     *                              round starts from the character after it.
     */
    public function __construct(
        public readonly int $sessionId,
        public readonly int $lastCharacterId,
    ) {}

    public function handle(LlmStoryService $story): void
    {
        $session = StorySession::with(['items.holder', 'characters'])->find($this->sessionId);

        if ($session === null || $session->status !== StorySessionStatus::Active) {
            return;
        }

        $recentSegments = StorySegment::where('session_id', $session->id)
            ->orderByDesc('turn_number')
            ->limit(8)
            ->get()
            ->reverse()
            ->values();

        $lastSegment = $recentSegments->last();

        if ($lastSegment === null) {
            return;
        }

        $setting = is_array($session->setting)
            ? json_encode($session->setting, JSON_UNESCAPED_UNICODE)
            : (string) $session->setting;

        $items = $session->items->map(fn($item) => [
            'name'        => $item->name,
            'description' => $item->description,
            'holder'      => $item->holder?->name,
        ])->toArray();

        $newWorldState = $story->updateWorldState(
            setting: $setting,
            currentWorldState: $session->world_state,
            newSegment: $lastSegment->content,
            characterName: $lastSegment->character?->name ?? '旁白',
            items: $items,
        );

        $pendingScene = $this->extractLocation($newWorldState, $session->pending_scene_location);

        // Story has reached its conclusion deadline
        $storyCompleted = $session->needs_complete
            && $session->complete_deadline_turn !== null
            && $lastSegment->turn_number >= $session->complete_deadline_turn;

        $session->update([
            'world_state'            => $newWorldState,
            'pending_scene_location' => $pendingScene,
            'current_character_id'   => $this->lastCharacterId,
            'next_advance_at'        => now()->addMinutes($session->advance_interval_minutes),
            'state_last_turn'        => $lastSegment->turn_number,
            'status'                 => $storyCompleted ? StorySessionStatus::Completed : $session->status,
        ]);

        Log::info("StoryState: session {$session->id} state updated, next advance in {$session->advance_interval_minutes}min"
            . ($pendingScene ? " [new scene: {$pendingScene}]" : ''));
    }

    private function extractLocation(string $worldState, ?string $current): ?string
    {
        if (!preg_match('/【當前場景】[：:]\s*([^\n【]{2,30})/u', $worldState, $m)) {
            return null;
        }

        $location = trim($m[1]);

        return $location !== $current ? $location : null;
    }
}
