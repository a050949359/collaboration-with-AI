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
            ->whereNotNull('character_id')
            ->orderByDesc('turn_number')
            ->with('character')
            ->first();
        $currentOrder = $lastSegment?->character?->turn_order ?? -1;

        // Active LLM characters after current turn_order, wrapping around
        $after  = $session->characters()->where('status', 'active')->where('type', 'llm')
            ->orderBy('turn_order')->where('turn_order', '>', $currentOrder)->get();
        $before = $session->characters()->where('status', 'active')->where('type', 'llm')
            ->orderBy('turn_order')->where('turn_order', '<=', $currentOrder)->get();

        $chars = $after->merge($before)->take($session->chars_per_round);

        if ($chars->isEmpty()) {
            Log::warning("StoryOrchestrate: no active LLM characters in session {$session->id}, pausing");
            $session->update(['status' => 'paused']);
            return;
        }

        $delay = $session->advance_interval_minutes * 60; // seconds

        $jobs = [];

        foreach ($chars as $i => $char) {
            $job = new StorySegmentJob($session->id, $char->id);
            if ($i > 0) {
                $job->delay($delay);
            }
            $jobs[] = $job;
        }

        $jobs[] = (new StoryStateJob($session->id, $chars->last()->id))->delay($delay);

        if ($session->needs_event) {
            array_unshift($jobs, new StoryEventJob($session->id));
        }

        Log::info("StoryOrchestrate: session {$session->id} chain=" . count($jobs) . " jobs, chars=" . $chars->pluck('name')->implode(','));

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
