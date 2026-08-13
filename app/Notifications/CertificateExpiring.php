<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\CertificateAlert;
use App\Models\Service;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One class for both transitions, matching IncidentStatusChanged: the recipient, the
 * routing and the payload are identical and only the wording differs.
 *
 * Deliberately not ShouldQueue, for the same reason as IncidentStatusChanged. There is no
 * queue worker on the droplet, so a queued alert would sit in the jobs table unsent.
 * Sending happens inside the scheduled certificates:refresh run, not a user request.
 */
final class CertificateExpiring extends Notification
{
    public function __construct(
        private readonly Service $service,
        private readonly CertificateAlert $alert,
        private readonly int $daysRemaining,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->service->name;
        $on = $this->service->certificate_expires_at?->toFormattedDateString() ?? 'an unknown date';

        return match ($this->alert) {
            CertificateAlert::Expiring => (new MailMessage)
                ->subject(sprintf('[Certificate] %s expires in %d days', $name, $this->daysRemaining))
                ->line(sprintf('The TLS certificate for %s expires on %s.', $name, $on))
                ->line('certbot renews inside this window, so if this has not resolved on its own then renewal is not working.'),

            CertificateAlert::Expired => (new MailMessage)
                ->subject(sprintf('[Certificate] %s has expired', $name))
                ->line(sprintf('The TLS certificate for %s expired on %s.', $name, $on))
                ->line('Visitors are seeing a browser warning until this is renewed.'),
        };
    }
}
