<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Transfer time per service for one chunk of probes, summed across redirect hops.
 *
 * Guzzle fires on_stats once per request it actually sends, and the HTTP client follows
 * redirects, so a service whose root redirects produces several callbacks. Keeping only
 * the last one measured the final hop and reported it as the whole request, which made
 * exactly the slowest services look fastest (STAT-30).
 */
class ProbeTimings
{
    /** @var array<int, float> */
    private array $seconds = [];

    /**
     * A hop with no measurable time still counts as a hop: it means the transfer happened
     * and Guzzle could not time it, which is not the same as never connecting.
     */
    public function record(int $serviceId, ?float $seconds): void
    {
        $this->seconds[$serviceId] = ($this->seconds[$serviceId] ?? 0.0) + ($seconds ?? 0.0);
    }

    /**
     * Null when nothing was recorded at all, which is how a connection that never opened
     * is told apart from one that opened and took no measurable time. toResult() turns
     * that into 0ms rather than the timeout, so a DNS failure does not spike the chart.
     */
    public function secondsFor(int $serviceId): ?float
    {
        return $this->seconds[$serviceId] ?? null;
    }
}
