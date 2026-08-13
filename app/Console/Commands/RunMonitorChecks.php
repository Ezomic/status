<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Monitoring\EvaluateIncident;
use App\Actions\Monitoring\RecordCheck;
use App\Models\Service;
use App\Services\Heartbeat;
use App\Services\HttpProbe;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class RunMonitorChecks extends Command
{
    protected $signature = 'monitor:run {--force : Check every active service, ignoring its interval}';

    protected $description = 'Probe every service that is due for a check';

    public function handle(
        HttpProbe $probe,
        RecordCheck $recordCheck,
        EvaluateIncident $evaluateIncident,
        Heartbeat $heartbeat,
    ): int {
        // One timestamp for the whole run, so a run is a queryable group and the
        // command behaves deterministically under a frozen clock.
        $now = CarbonImmutable::now();
        $due = $this->dueServices($now);

        if ($due->isEmpty()) {
            $this->components->info('No services are due.');

            // Still a healthy run: the runner ran, there was simply nothing to do. Not
            // pinging here would make a quiet minute indistinguishable from a dead cron.
            $heartbeat->ping();

            return self::SUCCESS;
        }

        $results = $probe->probeMany($due);
        $recorded = 0;

        foreach ($due as $service) {
            $result = $results[$service->id] ?? null;

            if ($result === null) {
                continue;
            }

            try {
                $check = $recordCheck->handle($service, $result, $now);
                $evaluateIncident->handle($service, $check);
                $recorded++;

                $this->components->twoColumnDetail(
                    $service->name,
                    sprintf('%s  %dms', $check->state->label(), $check->response_time_ms),
                );
            } catch (Throwable $exception) {
                // One unusable service must not cost the other twelve their check.
                report($exception);
                $this->components->error("{$service->name}: {$exception->getMessage()}");
            }
        }

        // Recording nothing at all, with services due, means the run was blind: a locked
        // database or similar. Withholding the ping is how that reaches someone, since
        // the app itself cannot report it (STAT-36). One flaky service does not count,
        // because that failure is already visible in the app.
        if ($recorded === 0) {
            $this->components->error('Nothing was recorded, so the heartbeat was withheld.');

            return self::FAILURE;
        }

        $heartbeat->ping();

        return self::SUCCESS;
    }

    /**
     * Due selection runs in PHP: the rule compares now against a per-row interval column,
     * which in SQL would need whereRaw. There are a dozen or so rows.
     *
     * @return Collection<int, Service>
     */
    private function dueServices(CarbonImmutable $now): Collection
    {
        return Service::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Service $service): bool => $this->option('force') || $service->isDueAt($now))
            ->values();
    }
}
