<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Idempotent by construction (see FlagStaleTickets), so a missed or doubled run
// is harmless. withoutOverlapping guards a run that outlives its window on a
// large queue; it uses the cache lock, and CACHE_STORE=database is already set.
// onOneServer() is deliberately absent -- it needs a cache shared between app
// servers and this project has no deployment topology decided yet. See
// docs/deployment-runbook.md.
Schedule::command('tickets:flag-stale')->dailyAt('02:00')->withoutOverlapping();
