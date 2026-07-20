<?php

declare(strict_types=1);

use App\Actions\Monitoring\RecordCheck;
use App\Enums\ServiceState;
use App\Models\Service;
use App\ValueObjects\ProbeResult;
use Carbon\CarbonImmutable;

it('stores the check and denormalises current state onto the service', function () {
    $at = CarbonImmutable::parse('2026-07-20 09:00:00');
    $service = Service::factory()->create(['expected_status_code' => 200, 'degraded_threshold_ms' => 1000]);

    $check = app(RecordCheck::class)->handle($service, new ProbeResult(200, 118), $at);

    expect($check->state)->toBe(ServiceState::Up)
        ->and($check->ok)->toBeTrue()
        ->and($check->response_time_ms)->toBe(118)
        ->and($check->checked_at->toIso8601String())->toBe($at->toIso8601String());

    expect($service->refresh())
        ->current_state->toBe(ServiceState::Up)
        ->last_response_time_ms->toBe(118)
        ->and($service->last_checked_at->toIso8601String())->toBe($at->toIso8601String());
});

it('marks a slow but successful response as degraded and still ok', function () {
    $service = Service::factory()->create(['degraded_threshold_ms' => 1000]);

    $check = app(RecordCheck::class)->handle($service, new ProbeResult(200, 2400), CarbonImmutable::now());

    expect($check->state)->toBe(ServiceState::Degraded)
        ->and($check->ok)->toBeTrue();
});

it('stores a null status code and the error when the probe never got a response', function () {
    $service = Service::factory()->create();

    $check = app(RecordCheck::class)->handle(
        $service,
        new ProbeResult(null, 5000, 'Operation timed out'),
        CarbonImmutable::now(),
    );

    expect($check->status_code)->toBeNull()
        ->and($check->state)->toBe(ServiceState::Down)
        ->and($check->ok)->toBeFalse()
        ->and($check->error)->toBe('Operation timed out');
});
