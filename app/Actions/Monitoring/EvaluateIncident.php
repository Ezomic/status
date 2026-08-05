<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Enums\IncidentChange;
use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Service;
use App\Models\User;
use App\Notifications\IncidentStatusChanged;
use Illuminate\Support\Facades\Notification;

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
        // A maintenance window is neutral: it neither opens nor escalates nor resolves
        // anything (STAT-18). Returning here is what stops a deploy from being reported
        // as an outage, and a deploy that happens mid-outage from being reported as a
        // recovery. previousCheck() skips maintenance for the same reason, so the
        // confirmation pair either side of a deploy is built from real observations.
        if ($check->state === ServiceState::Maintenance) {
            return $service->openIncident();
        }

        $previous = $this->previousCheck($service, $check);
        $open = $service->openIncident();

        if ($check->state === ServiceState::Up) {
            if ($open === null || $previous === null || $previous->state !== ServiceState::Up) {
                return $open;
            }

            // Recovery happened at the first passing check, not at the one confirming it.
            $open->update(['resolved_at' => $previous->checked_at]);

            $this->announce($open, IncidentChange::Resolved);

            return $open;
        }

        if ($previous === null || $previous->state === ServiceState::Up) {
            return $open;
        }

        $confirmed = $check->state->isWorseThan($previous->state) ? $check : $previous;

        if ($open === null) {
            $incident = $service->incidents()->create([
                'severity' => $confirmed->state,
                'started_at' => $previous->checked_at,
                'reason' => $this->reasonFor($service, $confirmed),
            ]);

            $this->announce($incident, IncidentChange::Opened);

            return $incident;
        }

        if ($confirmed->state->isWorseThan($open->severity)) {
            $open->update([
                'severity' => $confirmed->state,
                'reason' => $this->reasonFor($service, $confirmed),
            ]);

            $this->announce($open, IncidentChange::Escalated);
        }

        return $open;
    }

    /**
     * Only the three branches above announce anything, which is what keeps this
     * to one email per transition: this action runs after every check, and an
     * incident that stays open simply does not re-enter any of them. It also
     * means the housekeeping close in SaveService, which resolves an incident
     * when monitoring is paused, stays silent — that is not a recovery.
     */
    private function announce(Incident $incident, IncidentChange $change): void
    {
        // Opted-in users only, not User::all() (STAT-24). Since STAT-7 users are ID SSO
        // shadow copies created on first login, so mailing everyone meant anyone who ever
        // signed in silently started receiving every outage email with no way out.
        Notification::send(
            User::query()->wantsIncidentMail()->get(),
            new IncidentStatusChanged($incident, $change),
        );
    }

    /**
     * Ordered by id, never checked_at: every check in a run shares one timestamp, so
     * ordering by checked_at is nondeterministic under a frozen clock.
     *
     * Maintenance checks are skipped so a deploy cannot break the two-check
     * confirmation: without this, "down, deploy, down" would open an incident dated
     * to the deploy, and "down, deploy, up, up" would resolve off the wrong check.
     */
    private function previousCheck(Service $service, Check $check): ?Check
    {
        return $service->checks()
            ->where('id', '<', $check->id)
            ->where('state', '!=', ServiceState::Maintenance)
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
