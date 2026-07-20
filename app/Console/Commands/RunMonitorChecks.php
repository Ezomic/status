<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Monitoring\EvaluateIncident;
use App\Actions\Monitoring\RecordCheck;
use App\Models\Service;
use App\Services\HttpProbe;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class RunMonitorChecks extends Command
{
    protected $signature = 'monitor:run {--force : Check every active service, ignoring its interval}';

    protected $description = 'Probe every service that is due for a check';

    public function handle(HttpProbe $probe, RecordCheck $recordCheck, EvaluateIncident $evaluateIncident): int
    {
        // One timestamp for the whole run, so a run is a queryable group and the
        // command behaves deterministically under a frozen clock.
        $now = CarbonImmutable::now();
        $due = $this->dueServices($now);

        if ($due->isEmpty()) {
            $this->components->info('No services are due.');

            return self::SUCCESS;
        }

        $results = $probe->probeMany($due);

        foreach ($due as $service) {
            $result = $results[$service->id] ?? null;

            if ($result === null) {
                continue;
            }

            try {
                $check = $recordCheck->handle($service, $result, $now);
                $evaluateIncident->handle($service, $check);

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
