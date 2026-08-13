<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Service;
use App\ValueObjects\ProbeResult;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

class HttpProbe
{
    /**
     * How many services to probe at once when nothing is configured.
     *
     * Picked by measuring the real estate rather than by taste. Recorded latency for one
     * service against the number probed alongside it, and the wall time of a full round:
     *
     *   concurrency  1 -> median   70ms, round 2180ms
     *   concurrency  2 -> median   83ms, round 1577ms
     *   concurrency  3 -> median  108ms, round 1868ms
     *   concurrency  4 -> median  107ms, round 1359ms
     *   concurrency  6 -> median  192ms, round 2771ms
     *   concurrency 10 -> median  162ms, round  940ms, single service 340ms
     *
     * Latency climbs with concurrency and wall time falls, so this is a straight trade.
     * Three keeps the measurement within about 1.5x of the floor while bounding the worst
     * case: eleven services in chunks of three is four sequential rounds, so an estate
     * that is entirely timing out still finishes near the one minute tick rather than
     * taking eleven timeouts back to back.
     */
    public const DEFAULT_CONCURRENCY = 3;

    /**
     * Probe every service, a few at a time.
     *
     * Still concurrent, because sequentially one slow round can outlast the scheduler
     * tick, and combined with withoutOverlapping() that means checks get skipped during
     * exactly the outage they exist to catch (STAT-1).
     *
     * But not all at once. Every monitored app lives on the same droplet behind the same
     * php-fpm pool, so probing them simultaneously makes them compete for workers and
     * that contention lands in the recorded time. A local sidecar with no TLS and no
     * framework boot measured 8ms alone and over 1000ms inside a full pooled run, which
     * pushed healthy services past degraded_threshold_ms (STAT-29).
     *
     * @param  Collection<int, Service>  $services
     * @return array<int, ProbeResult> keyed by service id
     */
    public function probeMany(Collection $services): array
    {
        if ($services->isEmpty()) {
            return [];
        }

        $results = [];

        foreach ($services->chunk($this->concurrency()) as $chunk) {
            foreach ($this->probeChunk($chunk) as $serviceId => $result) {
                $results[$serviceId] = $result;
            }
        }

        return $results;
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return array<int, ProbeResult>
     */
    private function probeChunk(Collection $services): array
    {
        /** @var Collection<int, float> $elapsed */
        $elapsed = collect();

        $responses = Http::pool(fn (Pool $pool): array => $services
            ->map(fn (Service $service) => $pool
                ->as((string) $service->id)
                ->timeout($service->timeout_seconds)
                ->withOptions([
                    'on_stats' => function (TransferStats $stats) use ($elapsed, $service): void {
                        $elapsed->put($service->id, $stats->getTransferTime() ?? 0.0);
                    },
                ])
                ->get($service->url))
            ->all());

        $results = [];

        foreach ($services as $service) {
            $response = $responses[(string) $service->id] ?? null;
            $results[$service->id] = $this->toResult($response, $elapsed->get($service->id), $service);
        }

        return $results;
    }

    /**
     * Tunable without a deploy, because the right number depends on how much the box can
     * take and that changes as apps are added to it.
     */
    private function concurrency(): int
    {
        $configured = config('services.monitor.concurrency');

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : self::DEFAULT_CONCURRENCY;
    }

    public function probe(Service $service): ProbeResult
    {
        return $this->probeMany(collect([$service]))[$service->id];
    }

    private function toResult(mixed $response, ?float $transferSeconds, Service $service): ProbeResult
    {
        // Guzzle does not fire on_stats when the connection never opens, so a failed
        // probe reports 0. Do not substitute the timeout: a DNS failure resolves in
        // milliseconds, and recording it as a full timeout spikes the latency chart.
        $responseTimeMs = $transferSeconds !== null
            ? (int) round($transferSeconds * 1000)
            : 0;

        if ($response instanceof Response) {
            $retryAfter = $response->header('Retry-After');

            return new ProbeResult(
                $response->status(),
                $responseTimeMs,
                retryAfter: $retryAfter === '' ? null : $retryAfter,
                bodyMatched: $this->bodyMatches($response, $service),
            );
        }

        if ($response instanceof Throwable) {
            return new ProbeResult(null, $responseTimeMs, $this->message($response));
        }

        return new ProbeResult(null, $responseTimeMs, 'No response');
    }

    /**
     * Null when the service opted out, so classify() can tell "did not run" from "ran
     * and failed". The body is read here and discarded: only the verdict travels on.
     */
    private function bodyMatches(Response $response, Service $service): ?bool
    {
        $expected = $service->expected_body;

        if ($expected === null || $expected === '') {
            return null;
        }

        return str_contains($response->body(), $expected);
    }

    /** Guzzle appends a docs link and the full URL to every cURL error; neither reads well in an incident list. */
    private function message(Throwable $exception): string
    {
        $message = (string) preg_replace(
            ['/\s*\(see https:\/\/curl\.se\/[^)]*\)/', '/\s+for https?:\/\/\S+$/'],
            '',
            $exception->getMessage(),
        );

        return mb_substr(trim($message), 0, 255);
    }
}
