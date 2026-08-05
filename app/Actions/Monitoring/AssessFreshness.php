<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AssessFreshness
{
    /**
     * Whether the checks themselves are still running (STAT-19).
     *
     * The scheduler is a single cron line on the droplet. If cron, PHP or the SQLite
     * lock breaks, monitor:run stops, last_checked_at freezes, and every view keeps
     * rendering the last known state indefinitely. A monitor that fails silently into
     * all green is the one failure mode that matters here.
     *
     * A single stale service is odd but survivable. Every active service stale at once
     * means nobody is running the checks, because an unreachable host still gets
     * checked and that check records a failure.
     *
     * @param  Collection<int, Service>  $services
     * @return array{stalled: bool, stale_count: int, last_check_at: string|null}
     */
    public function handle(Collection $services, CarbonImmutable $now): array
    {
        $active = $services->filter(fn (Service $service): bool => $service->is_active);
        $stale = $active->filter(fn (Service $service): bool => $service->isStaleAt($now));

        $lastCheck = $active
            ->pluck('last_checked_at')
            ->filter()
            ->max();

        return [
            'stalled' => $active->isNotEmpty() && $stale->count() === $active->count(),
            'stale_count' => $stale->count(),
            'last_check_at' => $lastCheck instanceof CarbonImmutable
                ? $lastCheck->toIso8601String()
                : null,
        ];
    }
}
