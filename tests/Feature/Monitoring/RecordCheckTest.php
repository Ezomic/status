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

it('records a maintenance check without calling it an outage', function () {
    $service = Service::factory()->create(['expected_status_code' => 200]);

    $check = app(RecordCheck::class)->handle(
        $service,
        new ProbeResult(503, 40, retryAfter: '15'),
        CarbonImmutable::now(),
    );

    expect($check->state)->toBe(ServiceState::Maintenance)
        ->and($check->ok)->toBeTrue()
        ->and($service->refresh()->current_state)->toBe(ServiceState::Maintenance);
});

it('explains a content failure instead of claiming the status code was wrong', function () {
    $service = Service::factory()->create(['expected_status_code' => 200, 'expected_body' => 'Sign in']);

    $check = app(RecordCheck::class)->handle(
        $service,
        new ProbeResult(200, 90, bodyMatched: false),
        CarbonImmutable::now(),
    );

    // Without this the reason would read "Returned 200, expected 200".
    expect($check->state)->toBe(ServiceState::Down)
        ->and($check->error)->toBe('Responded without the expected content');
});

it('keeps the transport error when there is one as well', function () {
    $service = Service::factory()->create(['expected_body' => 'Sign in']);

    $check = app(RecordCheck::class)->handle(
        $service,
        new ProbeResult(null, 0, 'cURL error 6: Could not resolve host', bodyMatched: false),
        CarbonImmutable::now(),
    );

    expect($check->error)->toBe('cURL error 6: Could not resolve host');
});

it('records no error for a passing content assertion', function () {
    $service = Service::factory()->create(['expected_status_code' => 200, 'expected_body' => 'Sign in']);

    $check = app(RecordCheck::class)->handle(
        $service,
        new ProbeResult(200, 90, bodyMatched: true),
        CarbonImmutable::now(),
    );

    expect($check->state)->toBe(ServiceState::Up)
        ->and($check->error)->toBeNull();
});
