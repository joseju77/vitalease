<?php

use App\Support\Health\SchedulerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Failed indexing jobs older than a week are discarded; recover them earlier
// with `scout:import` (see ADR 0012).
Schedule::command('queue:prune-failed --hours=168')->daily();

Schedule::call(new SchedulerHeartbeat)->name('scheduler-heartbeat')->everyMinute();
