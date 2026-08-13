<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IncidentChange;
use App\Models\Incident;

class IncidentWebhook
{
    public function __construct(private readonly OutboundWebhook $webhook) {}

    /**
     * POST an incident transition to a configured endpoint (STAT-35).
     *
     * Worth having even though STAT-4 already sends mail: production still has
     * MAIL_MAILER=log pending the credential decision in STAT-16, so no alert reaches
     * anyone today. A webhook needs no mailbox and no SMTP credentials.
     *
     * Fires from the same three branches in EvaluateIncident that send mail, so it
     * inherits the dedupe: one message per transition, not one per failing check.
     */
    public function send(Incident $incident, IncidentChange $change): void
    {
        // Transport lives in OutboundWebhook, shared with certificate alerts (STAT-38).
        // What stays here is the payload, because what is safe to publish is a decision
        // about this data rather than about HTTP.
        $this->webhook->send($this->payload($incident, $change));
    }

    /**
     * Deliberately leak-safe, on the same reasoning as STAT-5.
     *
     * A webhook usually points at a chat room, which is semi-public at best, so this
     * carries no URL, host, status code or response time, and above all not
     * Incident::$reason, which is a raw cURL string containing the full internal
     * hostname ("Could not resolve host: internal-thing"). Someone reading the room
     * gets the service, the state and the timing, which is what a notification is for.
     *
     * @return array<string, mixed>
     */
    private function payload(Incident $incident, IncidentChange $change): array
    {
        return [
            'event' => $change->value,
            'service' => $incident->service->name,
            'severity' => $incident->severity->value,
            'text' => $this->text($incident, $change),
            'started_at' => $incident->started_at->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
        ];
    }

    private function text(Incident $incident, IncidentChange $change): string
    {
        $service = $incident->service->name;
        $severity = strtolower($incident->severity->label());

        return match ($change) {
            IncidentChange::Opened => sprintf('%s is %s', $service, $severity),
            IncidentChange::Escalated => sprintf('%s got worse: now %s', $service, $severity),
            IncidentChange::Resolved => sprintf('%s is back up', $service),
        };
    }
}
