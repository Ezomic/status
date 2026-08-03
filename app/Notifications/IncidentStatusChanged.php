<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\IncidentChange;
use App\Models\Incident;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification for all three transitions rather than three near-identical
 * classes: the recipient, the routing and the incident payload are the same
 * every time, and only the wording differs.
 *
 * Deliberately not ShouldQueue. There is no queue worker on the droplet, so a
 * queued alert would sit in the jobs table and the outage would go unannounced,
 * which is the exact failure this ticket exists to fix. Sending happens inside
 * the scheduled check run, not a user request, so blocking on SMTP is fine.
 */
final class IncidentStatusChanged extends Notification
{
    public function __construct(
        private readonly Incident $incident,
        private readonly IncidentChange $change,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $service = $this->incident->service->name;
        $severity = $this->incident->severity->label();

        return match ($this->change) {
            IncidentChange::Opened => (new MailMessage)
                ->subject(sprintf('[%s] %s is %s', $severity, $service, strtolower($severity)))
                ->line(sprintf('%s started failing at %s.', $service, $this->startedAt()))
                ->line($this->incident->reason),

            IncidentChange::Escalated => (new MailMessage)
                ->subject(sprintf('[%s] %s got worse', $severity, $service))
                ->line(sprintf('%s has escalated to %s.', $service, strtolower($severity)))
                ->line($this->incident->reason),

            IncidentChange::Resolved => (new MailMessage)
                ->subject(sprintf('[Resolved] %s is back up', $service))
                ->line(sprintf('%s recovered after %s.', $service, $this->downtime()))
                ->line(sprintf('It had been %s since %s.', strtolower($severity), $this->startedAt())),
        };
    }

    private function startedAt(): string
    {
        return $this->incident->started_at->toDayDateTimeString();
    }

    private function downtime(): string
    {
        $resolvedAt = $this->incident->resolved_at;

        if ($resolvedAt === null) {
            return 'an unknown period';
        }

        // DIFF_ABSOLUTE so it reads "3 minutes" rather than "3 minutes before".
        return $this->incident->started_at->diffForHumans($resolvedAt, [
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
            'parts' => 2,
        ]);
    }
}
