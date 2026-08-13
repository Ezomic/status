<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Enums\ServiceState;
use App\Models\IncidentUpdate;
use App\Models\MaintenanceWindow;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class BuildPublicStatus
{
    /**
     * The only payload any unauthenticated surface may use (STAT-5, ID-13).
     *
     * Names and states, nothing else. Never a URL, host, response time, status code,
     * check history or incident reason. Incident reasons matter most here: they are raw
     * cURL error strings that carry the full hostname, so "Could not resolve host:
     * internal-thing.example" would publish infrastructure detail to the world.
     *
     * Shared by the page and the JSON endpoint on purpose, so the leak-safety tests
     * cover both and neither can drift into exposing something the other does not.
     *
     * @return array{
     *     services: list<array{slug: string|null, name: string, state: string, stale: bool, last_checked_at: string|null, updates: list<array{body: string, at: string|null}>}>,
     *     maintenance: array{open: list<array{description: string, ends_at: string}>, upcoming: list<array{description: string, starts_at: string, ends_at: string}>},
     *     verdict: array{tone: string, headline: string},
     *     last_checked_at: string|null
     * }
     */
    public function handle(CarbonImmutable $now): array
    {
        return Cache::remember(
            'public-status',
            30,
            fn (): array => $this->build($now),
        );
    }

    /**
     * @return array{
     *     services: list<array{slug: string|null, name: string, state: string, stale: bool, last_checked_at: string|null, updates: list<array{body: string, at: string|null}>}>,
     *     maintenance: array{open: list<array{description: string, ends_at: string}>, upcoming: list<array{description: string, starts_at: string, ends_at: string}>},
     *     verdict: array{tone: string, headline: string},
     *     last_checked_at: string|null
     * }
     */
    private function build(CarbonImmutable $now): array
    {
        $services = Service::query()->public()->orderBy('name')->get();

        $serviceIds = [];

        foreach ($services as $service) {
            $serviceIds[] = $service->id;
        }

        $updates = $this->publishedUpdates($serviceIds);

        $rows = [];

        foreach ($services as $service) {
            $stale = $service->isStaleAt($now);

            $rows[] = [
                'slug' => $service->slug,
                'name' => $service->name,
                // A frozen state must not be served as current (STAT-19). If the runner
                // stopped, the honest answer is that we do not know.
                'state' => $stale
                    ? ServiceState::Unknown->value
                    : $service->current_state->value,
                'stale' => $stale,
                'last_checked_at' => $service->last_checked_at?->toIso8601String(),
                'updates' => $updates[$service->id] ?? [],
            ];
        }

        $lastChecked = $services->pluck('last_checked_at')->filter()->max();

        return [
            'services' => $rows,
            'maintenance' => $this->windows($now),
            'verdict' => $this->verdict($rows),
            'last_checked_at' => $lastChecked instanceof CarbonImmutable
                ? $lastChecked->toIso8601String()
                : null,
        ];
    }

    /**
     * Published updates for services with an incident still open (STAT-34).
     *
     * Only human-written bodies are ever published. Incident::$reason is deliberately not
     * here and must never be: it is a raw cURL string carrying the full internal hostname,
     * which is the whole reason STAT-5 keeps it off unauthenticated surfaces. Once an
     * incident resolves its updates drop off the page, because this reports the current
     * state rather than a history.
     *
     * @param  list<int>  $serviceIds
     * @return array<int, list<array{body: string, at: string|null}>>
     */
    private function publishedUpdates(array $serviceIds): array
    {
        if ($serviceIds === []) {
            return [];
        }

        $rows = IncidentUpdate::query()
            ->published()
            ->whereHas('incident', fn ($query) => $query
                ->whereIn('service_id', $serviceIds)
                ->whereNull('resolved_at'))
            ->with('incident:id,service_id')
            ->orderBy('created_at')
            ->get();

        $byService = [];

        foreach ($rows as $update) {
            $byService[$update->incident->service_id][] = [
                'body' => $update->body,
                'at' => $update->created_at?->toIso8601String(),
            ];
        }

        return $byService;
    }

    /**
     * Declared windows a reader should know about (STAT-37).
     *
     * Open ones explain why something may be unavailable right now; upcoming ones warn
     * before it happens, which is the part the detected-deploy path in STAT-18 cannot do.
     * Descriptions are written for readers, so they carry no host or URL.
     *
     * @return array{open: list<array{description: string, ends_at: string}>, upcoming: list<array{description: string, starts_at: string, ends_at: string}>}
     */
    private function windows(CarbonImmutable $now): array
    {
        $open = [];
        $upcoming = [];

        foreach (MaintenanceWindow::query()->openAt($now)->orderBy('ends_at')->get() as $window) {
            $open[] = [
                'description' => $window->description,
                'ends_at' => $window->ends_at->toIso8601String(),
            ];
        }

        foreach (MaintenanceWindow::query()->upcomingAt($now)->orderBy('starts_at')->limit(3)->get() as $window) {
            $upcoming[] = [
                'description' => $window->description,
                'starts_at' => $window->starts_at->toIso8601String(),
                'ends_at' => $window->ends_at->toIso8601String(),
            ];
        }

        return ['open' => $open, 'upcoming' => $upcoming];
    }

    /**
     * Worst reported state wins. Unknown sits above up rather than beside it: a service
     * nobody can currently confirm should not be folded into "all systems operational",
     * even though it is not an outage either.
     *
     * @param  list<array{slug: string|null, name: string, state: string, stale: bool, last_checked_at: string|null, updates: list<array{body: string, at: string|null}>}>  $rows
     * @return array{tone: string, headline: string}
     */
    private function verdict(array $rows): array
    {
        if ($rows === []) {
            return ['tone' => 'unknown', 'headline' => 'No services are being reported'];
        }

        $count = fn (ServiceState $state): int => count(array_filter(
            $rows,
            fn (array $row): bool => $row['state'] === $state->value,
        ));

        $total = count($rows);
        $down = $count(ServiceState::Down);
        $degraded = $count(ServiceState::Degraded);
        $maintenance = $count(ServiceState::Maintenance);
        $unknown = $count(ServiceState::Unknown);

        $of = function (int $n, string $word) use ($total): string {
            if ($n === $total) {
                return $total === 1
                    ? sprintf('The service is %s', $word)
                    : sprintf('All %d services are %s', $total, $word);
            }

            return sprintf('%d of %d services %s %s', $n, $total, $n === 1 ? 'is' : 'are', $word);
        };

        return match (true) {
            $down > 0 => ['tone' => 'down', 'headline' => $of($down, 'down')],
            $degraded > 0 => ['tone' => 'degraded', 'headline' => $of($degraded, 'slow')],
            $maintenance > 0 => ['tone' => 'maintenance', 'headline' => $of($maintenance, 'under maintenance')],
            // When nothing can be confirmed the runner has stopped, and "all N services
            // are not currently confirmed" is a clumsy way to say we cannot tell you.
            $unknown > 0 && $unknown === $total => ['tone' => 'unknown', 'headline' => 'Current status unavailable'],
            $unknown > 0 => ['tone' => 'unknown', 'headline' => $of($unknown, 'not currently confirmed')],
            default => ['tone' => 'up', 'headline' => 'All systems operational'],
        };
    }
}
