<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Service;

class EvaluateIncident
{
    /**
     * Open or close an incident based on the two most recent checks.
     *
     * An incident's severity is the worst state seen since it opened: it escalates and
     * never de-escalates, because the record is of what happened, not of what is
     * happening now. Live state is Service::$current_state.
     */
    public function handle(Service $service, Check $check): ?Incident
    {
        $previous = $this->previousCheck($service, $check);
        $open = $service->openIncident();

        if ($check->state === ServiceState::Up) {
            if ($open === null || $previous === null || $previous->state !== ServiceState::Up) {
                return $open;
            }

            // Recovery happened at the first passing check, not at the one confirming it.
            $open->update(['resolved_at' => $previous->checked_at]);

            return $open;
        }

        if ($previous === null || $previous->state === ServiceState::Up) {
            return $open;
        }

        $confirmed = $check->state->isWorseThan($previous->state) ? $check : $previous;

        if ($open === null) {
            return $service->incidents()->create([
                'severity' => $confirmed->state,
                'started_at' => $previous->checked_at,
                'reason' => $this->reasonFor($service, $confirmed),
            ]);
        }

        if ($confirmed->state->isWorseThan($open->severity)) {
            $open->update([
                'severity' => $confirmed->state,
                'reason' => $this->reasonFor($service, $confirmed),
            ]);
        }

        return $open;
    }

    /**
     * Ordered by id, never checked_at: every check in a run shares one timestamp, so
     * ordering by checked_at is nondeterministic under a frozen clock.
     */
    private function previousCheck(Service $service, Check $check): ?Check
    {
        return $service->checks()
            ->where('id', '<', $check->id)
            ->orderByDesc('id')
            ->first();
    }

    private function reasonFor(Service $service, Check $check): string
    {
        if ($check->error !== null) {
            return $check->error;
        }

        if ($check->state === ServiceState::Degraded) {
            return sprintf(
                'Responded in %sms, over the %sms threshold',
                number_format($check->response_time_ms),
                number_format($service->degraded_threshold_ms),
            );
        }

        return sprintf(
            'Returned %s, expected %s',
            $check->status_code ?? 'no response',
            $service->expected_status_code,
        );
    }
}
