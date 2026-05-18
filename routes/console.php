<?php

use App\Jobs\StoryAdvanceJob;
use App\Models\Story\StorySession;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:release-expired')->everyMinute();

// Safety net: dispatch advance job for any active session whose next_advance_at is overdue
Schedule::call(function () {
    StorySession::where('status', 'active')
        ->where('next_advance_at', '<=', now())
        ->each(fn($session) => StoryAdvanceJob::dispatch($session->id));
})->everyFiveMinutes()->name('story-advance-watchdog')->withoutOverlapping();
