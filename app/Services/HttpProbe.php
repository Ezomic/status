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
     * Probe every service concurrently.
     *
     * Sequentially, one slow round can outlast the scheduler tick, and combined with
     * withoutOverlapping() that means checks get skipped during exactly the outage
     * they exist to catch. Pooled, the run costs the slowest single request.
     *
     * @param  Collection<int, Service>  $services
     * @return array<int, ProbeResult> keyed by service id
     */
    public function probeMany(Collection $services): array
    {
        if ($services->isEmpty()) {
            return [];
        }

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
            $results[$service->id] = $this->toResult($response, $elapsed->get($service->id));
        }

        return $results;
    }

    public function probe(Service $service): ProbeResult
    {
        return $this->probeMany(collect([$service]))[$service->id];
    }

    private function toResult(mixed $response, ?float $transferSeconds): ProbeResult
    {
        // Guzzle does not fire on_stats when the connection never opens, so a failed
        // probe reports 0. Do not substitute the timeout: a DNS failure resolves in
        // milliseconds, and recording it as a full timeout spikes the latency chart.
        $responseTimeMs = $transferSeconds !== null
            ? (int) round($transferSeconds * 1000)
            : 0;

        if ($response instanceof Response) {
            return new ProbeResult($response->status(), $responseTimeMs);
        }

        if ($response instanceof Throwable) {
            return new ProbeResult(null, $responseTimeMs, $this->message($response));
        }

        return new ProbeResult(null, $responseTimeMs, 'No response');
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
