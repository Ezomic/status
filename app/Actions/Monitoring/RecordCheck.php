<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Service;
use App\ValueObjects\ProbeResult;
use Carbon\CarbonImmutable;

class RecordCheck
{
    public function handle(Service $service, ProbeResult $probe, CarbonImmutable $checkedAt): Check
    {
        $state = ServiceState::classify(
            $probe,
            $service->expected_status_code,
            $service->degraded_threshold_ms,
        );

        $check = $service->checks()->create([
            'status_code' => $probe->statusCode,
            'response_time_ms' => $probe->responseTimeMs,
            'ok' => $state !== ServiceState::Down,
            'state' => $state,
            'error' => $this->errorFor($probe, $state),
            'checked_at' => $checkedAt,
        ]);

        $service->forceFill([
            'current_state' => $state,
            'last_checked_at' => $checkedAt,
            'last_response_time_ms' => $probe->responseTimeMs,
        ])->save();

        return $check;
    }

    /**
     * A content failure has no transport error and a status code that matched, so
     * without this the incident reason would read "Returned 200, expected 200".
     * Written after classify() so it can never influence the state, and deliberately
     * generic: the response body must not reach an incident reason (STAT-22).
     */
    private function errorFor(ProbeResult $probe, ServiceState $state): ?string
    {
        if ($probe->error !== null) {
            return $probe->error;
        }

        if ($state === ServiceState::Down && $probe->bodyMatched === false) {
            return 'Responded without the expected content';
        }

        return null;
    }
}
