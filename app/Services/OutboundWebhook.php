<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class OutboundWebhook
{
    /**
     * Short on purpose. These POSTs happen inside scheduled runs, which are not queued
     * (there is no queue worker on the droplet), so a hanging endpoint would otherwise
     * hold up the checks themselves.
     */
    private const TIMEOUT_SECONDS = 5;

    /**
     * The transport half of webhook delivery, shared by everything that needs it
     * (incidents in STAT-35, certificates in STAT-38).
     *
     * Callers build their own payload, because what is safe to publish differs per event
     * and that decision belongs next to the data rather than in here.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): void
    {
        $url = config('services.monitor.incident_webhook_url');

        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            Http::timeout(self::TIMEOUT_SECONDS)->post($url, $payload);
        } catch (Throwable $exception) {
            // A dead webhook must never stop the run that is reporting through it.
            report($exception);
        }
    }
}
