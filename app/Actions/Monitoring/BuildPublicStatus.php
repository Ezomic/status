<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Enums\ServiceState;
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
     *     services: list<array{slug: string|null, name: string, state: string, stale: bool, last_checked_at: string|null}>,
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
     *     services: list<array{slug: string|null, name: string, state: string, stale: bool, last_checked_at: string|null}>,
     *     verdict: array{tone: string, headline: string},
     *     last_checked_at: string|null
     * }
     */
    private function build(CarbonImmutable $now): array
    {
        $services = Service::query()->public()->orderBy('name')->get();

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
            ];
        }

        $lastChecked = $services->pluck('last_checked_at')->filter()->max();

        return [
            'services' => $rows,
            'verdict' => $this->verdict($rows),
            'last_checked_at' => $lastChecked instanceof CarbonImmutable
                ? $lastChecked->toIso8601String()
                : null,
        ];
    }

    /**
     * Worst reported state wins. Unknown sits above up rather than beside it: a service
     * nobody can currently confirm should not be folded into "all systems operational",
     * even though it is not an outage either.
     *
     * @param  list<array{slug: string|null, name: string, state: string, stale: bool, last_checked_at: string|null}>  $rows
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
