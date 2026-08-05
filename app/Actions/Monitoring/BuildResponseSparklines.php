<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\Check;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class BuildResponseSparklines
{
    /**
     * One average response time per hour per service over the last day, oldest first.
     *
     * Aggregated in SQL rather than in PHP (STAT-21). Selecting the raw rows and
     * thinning them afterwards meant hydrating every check from the last 24 hours on
     * every page load, roughly 13,000 models at a dozen services on a 60 second
     * interval. Grouping by the hour caps it at 24 rows per service.
     *
     * Bucketing by substr() rather than strftime() keeps this portable; the strip
     * query next to it already relies on date().
     *
     * @return array<int, list<int>> keyed by service id
     */
    public function handle(): array
    {
        return Cache::remember('response-sparklines', 60, fn (): array => $this->build());
    }

    /** @return array<int, list<int>> */
    private function build(): array
    {
        // Latency views plot only checks that got a response: a failed connection
        // records 0ms, which would otherwise drag the average toward an impossibly
        // fast request.
        $rows = Check::query()
            ->selectRaw('service_id')
            ->selectRaw('substr(checked_at, 1, 13) as hour')
            ->selectRaw('avg(response_time_ms) as average_ms')
            ->where('checked_at', '>=', CarbonImmutable::now()->subDay())
            ->whereNotNull('status_code')
            ->groupBy('service_id', 'hour')
            ->orderBy('service_id')
            ->orderBy('hour')
            ->toBase()
            ->get();

        $series = [];

        foreach ($rows as $row) {
            $series[$this->toInt($row->service_id)][] = $this->toInt($row->average_ms);
        }

        return $series;
    }

    /** avg() comes back as a float or a numeric string depending on the driver. */
    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value) : 0;
    }
}
