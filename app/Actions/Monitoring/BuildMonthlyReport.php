<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Enums\CheckSource;
use App\Enums\ServiceState;
use App\Models\Incident;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BuildMonthlyReport
{
    /**
     * Checks are pruned after 90 days, so this is the whole window there is.
     *
     * Four months rather than three because the oldest is always partial: 90 days back
     * from the middle of a month lands in the middle of another one. The page says so
     * rather than letting a truncated month read as a bad one.
     */
    public const MONTHS_AVAILABLE = 4;

    /**
     * A month of uptime, latency and incidents for every service.
     *
     * Four queries regardless of how many services or checks exist. Percentiles are
     * computed inside SQLite with a window function rather than by pulling response times
     * into PHP: a month of one minute checks is about 43k rows per service, and STAT-21
     * already established what hydrating that costs.
     *
     * @return array<string, mixed>
     */
    public function handle(CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();
        $end = $start->addMonth();

        $availability = $this->availability($start, $end);
        $latency = $this->latency($start, $end);
        $incidents = $this->incidents($start, $end);

        $services = Service::query()
            ->orderByRaw('"group" is null, "group"')
            ->orderBy('name')
            ->get();

        return [
            'month' => $start->format('Y-m'),
            'label' => $start->format('F Y'),
            'partial' => $this->isPartial($start),
            'services' => $services->map(function (Service $service) use ($availability, $latency, $incidents): array {
                $measured = $availability[$service->id]['measured'] ?? 0;
                $down = $availability[$service->id]['down'] ?? 0;

                // No measurable checks is no data: not a perfect month, and not a failed
                // one. Only a service that was actually observed gets a number.
                $uptime = $measured > 0
                    ? round((($measured - $down) / $measured) * 100, 3)
                    : null;

                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'group' => $service->group,
                    'uptime' => $uptime,
                    'target' => $service->uptime_target,
                    'met' => $uptime === null || $service->uptime_target === null
                        ? null
                        : $uptime >= $service->uptime_target,
                    'checks' => $measured,
                    'p50' => $latency[$service->id]['p50'] ?? null,
                    'p95' => $latency[$service->id]['p95'] ?? null,
                    'incidents' => $incidents[$service->id]['count'] ?? 0,
                    'downtime_seconds' => $incidents[$service->id]['seconds'] ?? 0,
                    'unresolved' => $incidents[$service->id]['unresolved'] ?? false,
                ];
            })->values()->all(),
        ];
    }

    /**
     * The months worth offering, newest first, bounded by what retention keeps.
     *
     * @return list<array{month: string, label: string}>
     */
    public function availableMonths(CarbonImmutable $now): array
    {
        $months = [];

        foreach ($this->window($now) as $month) {
            $months[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('F Y'),
            ];
        }

        return $months;
    }

    /**
     * Match a requested month against the window instead of parsing it, so an unusable
     * value cannot reach the report at all and there is nothing to fall over on.
     */
    public function resolveMonth(?string $requested, CarbonImmutable $now): CarbonImmutable
    {
        foreach ($this->window($now) as $month) {
            if ($month->format('Y-m') === $requested) {
                return $month;
            }
        }

        return $now->startOfMonth();
    }

    /** @return list<CarbonImmutable> */
    private function window(CarbonImmutable $now): array
    {
        $months = [];

        for ($i = 0; $i < self::MONTHS_AVAILABLE; $i++) {
            $months[] = $now->startOfMonth()->subMonths($i);
        }

        return $months;
    }

    /**
     * Maintenance leaves the ratio entirely, matching the service page: availability is
     * not measurable while a service is deliberately offline (STAT-18).
     *
     * @return array<int, array{measured: int, down: int}>
     */
    private function availability(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('checks')
            ->where('source', CheckSource::Internal->value)
            ->selectRaw('service_id, count(*) as measured, sum(case when state = ? then 1 else 0 end) as down', [
                ServiceState::Down->value,
            ])
            ->where('checked_at', '>=', $start)
            ->where('checked_at', '<', $end)
            ->where('state', '!=', ServiceState::Maintenance->value)
            ->groupBy('service_id')
            ->get();

        $availability = [];

        foreach ($rows as $row) {
            $availability[$this->toInt($row->service_id)] = [
                'measured' => $this->toInt($row->measured),
                'down' => $this->toInt($row->down),
            ];
        }

        return $availability;
    }

    /**
     * p50 and p95 in one query, for every service at once.
     *
     * cume_dist() gives each response time its position in its service's distribution, so
     * the smallest value at or past 0.5 is the median and at or past 0.95 is the p95. The
     * ranking happens in SQLite and only one row per service comes back.
     *
     * Checks with no status code are excluded: a failed connection records 0ms, which is
     * not a fast response and would drag the median down during exactly the month worth
     * reporting on.
     *
     * @return array<int, array{p50: int|null, p95: int|null}>
     */
    private function latency(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $ranked = DB::table('checks')
            ->where('source', CheckSource::Internal->value)
            ->selectRaw('service_id, response_time_ms, cume_dist() over (partition by service_id order by response_time_ms) as position')
            ->whereNotNull('status_code')
            ->where('checked_at', '>=', $start)
            ->where('checked_at', '<', $end);

        $rows = DB::query()
            ->fromSub($ranked, 'ranked')
            ->selectRaw('service_id, min(case when position >= 0.5 then response_time_ms end) as p50, min(case when position >= 0.95 then response_time_ms end) as p95')
            ->groupBy('service_id')
            ->get();

        $latency = [];

        foreach ($rows as $row) {
            $latency[$this->toInt($row->service_id)] = [
                'p50' => $this->toIntOrNull($row->p50),
                'p95' => $this->toIntOrNull($row->p95),
            ];
        }

        return $latency;
    }

    /**
     * Counted by when an incident started, so one outage is reported in one month even if
     * it ran past midnight on the last day. Duration is clamped to now for the same
     * reason: an incident still open would otherwise report future time.
     *
     * @return array<int, array{count: int, seconds: int, unresolved: bool}>
     */
    private function incidents(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cutoff = CarbonImmutable::now()->min($end);

        // Hydrated rather than aggregated in SQL: there are dozens of these, not tens of
        // thousands, and the model already knows how to read the timestamps.
        $rows = Incident::query()
            ->select('id', 'service_id', 'started_at', 'resolved_at')
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->get();

        $incidents = [];

        foreach ($rows as $incident) {
            $current = $incidents[$incident->service_id] ?? ['count' => 0, 'seconds' => 0, 'unresolved' => false];
            $until = $incident->resolved_at ?? $cutoff;

            $incidents[$incident->service_id] = [
                'count' => $current['count'] + 1,
                'seconds' => $current['seconds'] + (int) max(0, $until->diffInSeconds($incident->started_at, true)),
                'unresolved' => $current['unresolved'] || $incident->resolved_at === null,
            ];
        }

        return $incidents;
    }

    /**
     * A month reads as partial while it is still running, and also at the far edge of
     * retention where pruning has already eaten into it.
     */
    private function isPartial(CarbonImmutable $start): bool
    {
        $now = CarbonImmutable::now();

        return $start->isSameMonth($now) || $start->lt($now->subDays(90)->startOfMonth()->addMonth());
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function toIntOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
