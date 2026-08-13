<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Enums\CertificateAlert;
use App\Models\Service;
use App\Models\User;
use App\Notifications\CertificateExpiring;
use App\Services\CertificateInspector;
use App\Services\OutboundWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

class EvaluateCertificateAlert
{
    public function __construct(private readonly OutboundWebhook $webhook) {}

    /**
     * Alert on the transition into a certificate state, never on the state persisting
     * (STAT-38).
     *
     * certificates:refresh runs daily, so alerting on the state itself would mail every
     * morning for the last month of a certificate's life. This mirrors how IncidentChange
     * keeps incident mail to one message per transition.
     */
    public function handle(Service $service, CarbonImmutable $now): ?CertificateAlert
    {
        $days = $service->certificateDaysRemaining($now);

        // Unknown expiry: STAT-23 nulls it when a lookup fails so it reads as unknown
        // rather than as still-fine. Do not alert, and do not re-arm either, because
        // "we could not look" is not evidence the certificate was renewed.
        if ($days === null) {
            return $service->certificate_alerted;
        }

        $target = match (true) {
            $days < 0 => CertificateAlert::Expired,
            $days <= CertificateInspector::WARN_WITHIN_DAYS => CertificateAlert::Expiring,
            default => null,
        };

        if ($target === $service->certificate_alerted) {
            return $target;
        }

        $service->forceFill(['certificate_alerted' => $target])->save();

        // Comfortably valid again, so a renewal happened: re-armed silently, because
        // nobody needs an email saying a certificate is fine.
        if ($target === null) {
            return null;
        }

        $this->announce($service, $target, $days);

        return $target;
    }

    private function announce(Service $service, CertificateAlert $alert, int $days): void
    {
        Notification::send(
            User::query()->wantsIncidentMail()->subscribedTo($service)->get(),
            new CertificateExpiring($service, $alert, $days),
        );

        // Production mail still goes to the log pending STAT-16, so the webhook is the
        // channel that can actually deliver this today (STAT-35).
        $this->webhook->send([
            'event' => "certificate.{$alert->value}",
            'service' => $service->name,
            'days_remaining' => $days,
            'expires_at' => $service->certificate_expires_at?->toIso8601String(),
            'text' => $alert === CertificateAlert::Expired
                ? sprintf('%s certificate has expired', $service->name)
                : sprintf('%s certificate expires in %d days', $service->name, $days),
        ]);
    }
}
