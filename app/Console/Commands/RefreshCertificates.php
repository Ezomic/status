<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\CertificateInspector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RefreshCertificates extends Command
{
    protected $signature = 'certificates:refresh';

    protected $description = 'Record when each service\'s TLS certificate expires';

    /**
     * Deliberately not part of the per-minute check run: a certificate changes every 90
     * days, so once a day is ample, and the handshake is a second connection per service.
     */
    public function handle(CertificateInspector $inspector): int
    {
        $services = Service::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Service $service): bool => $service->usesTls());

        if ($services->isEmpty()) {
            $this->components->info('No active https services.');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();

        foreach ($services as $service) {
            try {
                $expiresAt = $inspector->expiresAt($service);
            } catch (Throwable $exception) {
                // One unreadable host must not cost the others their refresh.
                report($exception);
                $expiresAt = null;
            }

            // A failed lookup nulls the expiry rather than leaving yesterday's date in
            // place: an unknown expiry reads as unknown, never as still-fine (STAT-23).
            $service->forceFill([
                'certificate_expires_at' => $expiresAt,
                'certificate_checked_at' => $now,
            ])->save();

            $this->components->twoColumnDetail(
                $service->name,
                $expiresAt === null
                    ? 'unknown'
                    : sprintf('%s (%d days)', $expiresAt->toDateString(), $service->certificateDaysRemaining($now)),
            );
        }

        return self::SUCCESS;
    }
}
