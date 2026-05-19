<?php

use App\Jobs\Story\StoryOrchestrateJob;
use App\Models\Story\StorySession;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:release-expired')->everyMinute();

// StoryClock: sole entry point — claims next_advance_at immediately to prevent duplicates
Schedule::call(function () {
    StorySession::where('status', 'active')
        ->where('next_advance_at', '<=', now())
        ->each(function (StorySession $session) {
            $session->update([
                'next_advance_at' => now()->addMinutes($session->advance_interval_minutes),
            ]);
            StoryOrchestrateJob::dispatch($session->id);
        });
})->hourly()->name('story-clock')->withoutOverlapping();
