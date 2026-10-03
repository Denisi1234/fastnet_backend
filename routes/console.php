<?php

use App\Console\Commands\WarmPropertyCache;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-warm Redis cache every 30 minutes
// This keeps popular city searches pre-loaded so users hit cache, not DB
Schedule::command('cache:warm')->everyThirtyMinutes();

// Daily data safety net: snapshot everything the build created (DB rows +
// media) and keep the last 7. If a volume is ever deleted or a bad deploy
// wipes state, any morning's snapshot restores it as-is via snapshot:import.
Schedule::command('snapshot:export --prune=7')->dailyAt('03:00');
