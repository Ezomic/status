<?php

use App\Console\Commands\RefreshCertificates;
use App\Console\Commands\RunMonitorChecks;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(RunMonitorChecks::class)
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

Schedule::command('model:prune')->dailyAt('03:10');

// Certificates change every 90 days, so a daily look is ample. Offset from the prune
// so the two do not contend for the SQLite write lock.
Schedule::command(RefreshCertificates::class)->dailyAt('03:30');
