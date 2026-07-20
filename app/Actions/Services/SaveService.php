<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Models\Service;
use Carbon\CarbonImmutable;

class SaveService
{
    /** @param array<string, mixed> $data */
    public function handle(array $data, ?Service $service = null): Service
    {
        if ($service === null) {
            return Service::create($data);
        }

        $wasActive = $service->is_active;

        $service->update($data);

        // Pausing stops the checks, so an incident open at that moment would otherwise
        // hang open forever and report a permanent outage.
        if ($wasActive && ! $service->fresh()?->is_active) {
            $service->openIncident()?->update([
                'resolved_at' => CarbonImmutable::now(),
                'reason' => 'Monitoring disabled',
            ]);
        }

        return $service->refresh();
    }
}
