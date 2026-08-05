<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Monitoring\AssessFreshness;
use App\Actions\Monitoring\BuildResponseSparklines;
use App\Actions\Monitoring\BuildUptimeStrip;
use App\Enums\ServiceState;
use App\Models\Incident;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The state of the estate without a click (STAT-17).
     *
     * Both strip and sparkline actions are cached, and everything else is derived from
     * the services already in memory, so this adds no uncached scan of the checks table.
     */
    public function index(
        BuildUptimeStrip $buildUptimeStrip,
        BuildResponseSparklines $buildSparklines,
        AssessFreshness $assessFreshness,
    ): Response {
        $now = CarbonImmutable::now();
        $services = Service::query()->orderBy('name')->get();
        $active = $services->filter(fn (Service $service): bool => $service->is_active);

        $strips = $buildUptimeStrip->handle();
        $sparklines = $buildSparklines->handle();

        $openIncidents = Incident::query()
            ->whereNull('resolved_at')
            ->with('service:id,name')
            ->orderByDesc('started_at')
            ->get();

        return Inertia::render('Dashboard', [
            'freshness' => $assessFreshness->handle($services, $now),
            'counts' => $this->counts($active, $services, $now),
            'verdict' => $this->verdict($active, $openIncidents, $now),
            'attention' => $active
                ->filter(fn (Service $service): bool => $service->current_state !== ServiceState::Up)
                ->sortBy(fn (Service $service): int => -$service->current_state->severity())
                ->map(fn (Service $service): array => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'host' => $service->host(),
                    'state' => $service->current_state->value,
                    'state_label' => $service->current_state->label(),
                    'is_stale' => $service->isStaleAt($now),
                    'last_response_time_ms' => $service->last_response_time_ms ?: null,
                    'strip' => $strips[$service->id] ?? [],
                    'sparkline' => $sparklines[$service->id] ?? [],
                ])
                ->values(),
            'openIncidents' => $openIncidents
                ->map(fn (Incident $incident): array => [
                    'id' => $incident->id,
                    'service' => $incident->service->name,
                    'service_id' => $incident->service_id,
                    'severity' => $incident->severity->value,
                    'reason' => $incident->reason,
                    'started_at' => $incident->started_at->toIso8601String(),
                    'resolved_at' => null,
                ])
                ->values(),
        ]);
    }

    /**
     * @param  Collection<int, Service>  $active
     * @param  Collection<int, Service>  $all
     * @return array<string, int>
     */
    private function counts(Collection $active, Collection $all, CarbonImmutable $now): array
    {
        return [
            'total' => $all->count(),
            'watched' => $active->count(),
            'paused' => $all->count() - $active->count(),
            'up' => $active->filter(fn (Service $s): bool => $s->current_state === ServiceState::Up)->count(),
            'degraded' => $active->filter(fn (Service $s): bool => $s->current_state === ServiceState::Degraded)->count(),
            'down' => $active->filter(fn (Service $s): bool => $s->current_state === ServiceState::Down)->count(),
            'maintenance' => $active->filter(fn (Service $s): bool => $s->current_state === ServiceState::Maintenance)->count(),
            'unknown' => $active->filter(fn (Service $s): bool => $s->current_state === ServiceState::Unknown)->count(),
            'stale' => $active->filter(fn (Service $s): bool => $s->isStaleAt($now))->count(),
        ];
    }

    /**
     * The headline. Ordered by what an operator needs to hear first: a dead runner
     * outranks an outage, because if nothing is being checked then every state below
     * is a guess (STAT-19).
     *
     * @param  Collection<int, Service>  $active
     * @param  Collection<int, Incident>  $openIncidents
     * @return array{tone: string, headline: string, detail: string}
     */
    private function verdict(Collection $active, Collection $openIncidents, CarbonImmutable $now): array
    {
        if ($active->isEmpty()) {
            return [
                'tone' => 'unknown',
                'headline' => 'Nothing is being watched',
                'detail' => 'Add a service and Status will start checking it within a minute.',
            ];
        }

        if ($active->every(fn (Service $service): bool => $service->isStaleAt($now))) {
            return [
                'tone' => 'stale',
                'headline' => 'Checks are not running',
                'detail' => 'Every state below is the last one recorded, not the current one.',
            ];
        }

        $down = $active->filter(fn (Service $s): bool => $s->current_state === ServiceState::Down);
        $degraded = $active->filter(fn (Service $s): bool => $s->current_state === ServiceState::Degraded);

        if ($down->isNotEmpty()) {
            $only = $down->count() === 1;

            return [
                'tone' => 'down',
                'headline' => $only
                    ? sprintf('%s is down', (string) $down->first()->name)
                    : sprintf('%d services are down', $down->count()),
                'detail' => $this->worstReason($openIncidents, withServiceName: ! $only),
            ];
        }

        if ($degraded->isNotEmpty()) {
            $only = $degraded->count() === 1;

            return [
                'tone' => 'degraded',
                'headline' => $only
                    ? sprintf('%s is slow', (string) $degraded->first()->name)
                    : sprintf('%d services are slow', $degraded->count()),
                'detail' => $this->worstReason($openIncidents, withServiceName: ! $only),
            ];
        }

        $maintenance = $active->filter(fn (Service $s): bool => $s->current_state === ServiceState::Maintenance);

        if ($maintenance->isNotEmpty()) {
            return [
                'tone' => 'maintenance',
                'headline' => 'Everything is up, one deploy in progress',
                'detail' => $maintenance->count() === 1
                    ? sprintf('%s is in maintenance.', (string) $maintenance->first()->name)
                    : sprintf('%d services are in maintenance.', $maintenance->count()),
            ];
        }

        return [
            'tone' => 'up',
            'headline' => 'All systems operational',
            'detail' => sprintf(
                '%d %s checked on schedule, nothing failing.',
                $active->count(),
                $active->count() === 1 ? 'service' : 'services',
            ),
        ];
    }

    /**
     * The name is prefixed only when the headline did not already say it, so a single
     * outage does not read "Zero is down / Zero: returned 500".
     *
     * @param  Collection<int, Incident>  $openIncidents
     */
    private function worstReason(Collection $openIncidents, bool $withServiceName): string
    {
        $worst = $openIncidents
            ->sortByDesc(fn (Incident $incident): int => $incident->severity->severity())
            ->first();

        if ($worst === null) {
            return 'No incident is open yet: a failure opens one once a second check confirms it.';
        }

        return $withServiceName
            ? sprintf('%s: %s', $worst->service->name, $worst->reason)
            : $worst->reason;
    }
}
