<?php

namespace App\Jobs\Story;

use App\Models\Story\StorySegment;
use App\Models\Story\StorySession;
use App\Services\AI\GeminiStoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class StoryEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(public readonly int $sessionId) {}

    public function handle(GeminiStoryService $story): void
    {
        $session = StorySession::with(['items.holder'])->find($this->sessionId);

        if ($session === null || $session->status !== 'active') {
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

        $eventContent = $story->generateExternalEvent(
            setting: $setting,
            worldState: $session->world_state,
            items: $items,
            recentSegments: $recentSegments,
        );

        $turnNumber = (StorySegment::where('session_id', $session->id)->max('turn_number') ?? 0) + 1;

        StorySegment::create([
            'session_id'        => $session->id,
            'character_id'      => null,
            'content'           => $eventContent,
            'turn_number'       => $turnNumber,
            'is_player_written' => false,
            'is_event'          => true,
        ]);

        $session->update([
            'needs_event'             => false,
            'rounds_without_progress' => 0,
        ]);

        Log::info("StoryEvent: session {$session->id} turn {$turnNumber} external event injected");
    }
}
