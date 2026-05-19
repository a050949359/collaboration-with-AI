<?php

namespace App\Jobs\Story;

use App\Models\Story\StorySegment;
use App\Models\Story\StorySession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class StoryOrchestrateJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 30;

    public function __construct(public readonly int $sessionId) {}

    public function handle(): void
    {
        $session = StorySession::find($this->sessionId);

        if ($session === null || $session->status !== 'active') {
            return;
        }

        // Use the most recent character segment to determine starting point.
        // This handles both normal rounds and resume-after-failure correctly.
        $lastSegment  = StorySegment::where('session_id', $session->id)
            ->whereHas('character', fn($q) => $q->where('type', 'llm')->where('is_narrator', true))
            ->orderByDesc('turn_number')
            ->with('character')
            ->first();
        $currentOrder = $lastSegment?->character?->turn_order ?? -1;

        // Active LLM characters after current turn_order, wrapping around
        $after  = $session->characters()->where('status', 'active')->where('type', 'llm')->where('is_narrator', true)
            ->orderBy('turn_order')->where('turn_order', '>', $currentOrder)->get();
        $before = $session->characters()->where('status', 'active')->where('type', 'llm')->where('is_narrator', true)
            ->orderBy('turn_order')->where('turn_order', '<=', $currentOrder)->get();

        $narrators = $after->merge($before);

        if ($narrators->isEmpty()) {
            Log::warning("StoryOrchestrate: no active LLM characters in session {$session->id}, pausing");
            $session->update(['status' => 'paused']);
            return;
        }

        $delay    = $session->advance_interval_minutes * 60; // seconds
        $rounds   = max(1, (int) $session->rounds_per_advance);
        $jobs     = [];
        $lastChar = null;

        for ($round = 0; $round < $rounds; $round++) {
            foreach ($narrators as $char) {
                $job = new StorySegmentJob($session->id, $char->id);
                if (!empty($jobs)) {
                    $job->delay($delay);
                }
                $jobs[]   = $job;
                $lastChar = $char;
            }
        }

        $jobs[] = (new StoryStateJob($session->id, $lastChar->id))->delay($delay);

        Log::info("StoryOrchestrate: session {$session->id} rounds={$rounds} chain=" . \count($jobs) . " jobs, narrators=" . $narrators->pluck('name')->implode(','));

        Bus::chain($jobs)
            ->catch(function (\Throwable $e) use ($session) {
                Log::error("StoryChain failed session={$session->id}: {$e->getMessage()}");
                // Only pause if still active — don't overwrite a completed session
                StorySession::where('id', $session->id)
                    ->where('status', 'active')
                    ->update(['status' => 'paused']);
            })
            ->dispatch();
    }
}
