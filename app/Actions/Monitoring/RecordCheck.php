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
            'error' => $probe->error,
            'checked_at' => $checkedAt,
        ]);

        $service->forceFill([
            'current_state' => $state,
            'last_checked_at' => $checkedAt,
            'last_response_time_ms' => $probe->responseTimeMs,
        ])->save();

        return $check;
    }
}
