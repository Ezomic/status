<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class Heartbeat
{
    /**
     * Five seconds, not the fifteen a service check gets. This request is bookkeeping,
     * not a measurement, and it runs inside the scheduled check run.
     */
    private const TIMEOUT_SECONDS = 5;

    /**
     * Tell an outside observer the runner is alive (STAT-36).
     *
     * STAT-19 taught the app to notice its own staleness and say so in the UI and on
     * api/status, but a monitor cannot report its own death: if cron, PHP or the SQLite
     * lock breaks, nothing runs and nothing is sent. Something outside this process has
     * to notice the silence, which is what a heartbeat service (healthchecks.io and
     * friends) does.
     *
     * Deliberately quiet and forgiving. No configuration means no request at all, so
     * local and CI are untouched, and any failure is reported and swallowed: the
     * heartbeat must never be able to break the run it is reporting on.
     */
    public function ping(): void
    {
        $url = config('services.monitor.heartbeat_url');

        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            Http::timeout(self::TIMEOUT_SECONDS)->get($url);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
