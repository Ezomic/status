<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SpikeSignalState;
use App\Models\SpikeSignal;

class SpikeSignalWebhook
{
    public function __construct(private readonly OutboundWebhook $webhook) {}

    /**
     * Same transport and the same room as incident alerts, one message per transition. Carries
     * the app, the route and the counts a person needs to judge it, and nothing else from the
     * signal.
     */
    public function send(SpikeSignal $signal): void
    {
        $this->webhook->send([
            'event' => 'spike_'.$signal->state->value,
            'service' => $signal->application_name,
            'severity' => $signal->state === SpikeSignalState::Open ? 'degraded' : 'up',
            'text' => $this->text($signal),
            'started_at' => $signal->opened_at->toIso8601String(),
            'resolved_at' => $signal->resolved_at?->toIso8601String(),
        ]);
    }

    private function text(SpikeSignal $signal): string
    {
        $subject = $signal->route === null
            ? $signal->application_name
            : sprintf('%s (%s)', $signal->application_name, $signal->route);

        if ($signal->state === SpikeSignalState::Resolved) {
            return sprintf('%s: report spike over', $subject);
        }

        return sprintf(
            '%s: report spike, %d reports in %d minutes (baseline %s)',
            $subject,
            $signal->count,
            $signal->window_minutes,
            number_format($signal->baseline_per_window, 1, '.', ''),
        );
    }
}
