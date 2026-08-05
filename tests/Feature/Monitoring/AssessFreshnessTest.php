<?php

declare(strict_types=1);

use App\Actions\Monitoring\AssessFreshness;
use App\Models\Service;
use Carbon\CarbonImmutable;

$now = fn (): CarbonImmutable => CarbonImmutable::parse('2026-08-05 12:00:00');

it('does not call a freshly checked service stale', function () use ($now) {
    $service = Service::factory()->create([
        'interval_seconds' => 60,
        'last_checked_at' => $now()->subSeconds(90),
    ]);

    expect($service->isStaleAt($now()))->toBeFalse();
});

it('calls a service stale once it passes three intervals', function () use ($now) {
    // 60s interval, floored at the scheduler tick, so the tolerance is 180s.
    $service = Service::factory()->create([
        'interval_seconds' => 60,
        'last_checked_at' => $now()->subSeconds(181),
    ]);

    expect($service->isStaleAt($now()))->toBeTrue();
});

it('floors the tolerance at the scheduler tick for sub-minute intervals', function () use ($now) {
    // Cron runs schedule:run once a minute, so a 30 second service is really checked
    // every 60 seconds. Three raw intervals would be 90s and would flag a healthy one.
    $service = Service::factory()->create(['interval_seconds' => 30]);

    expect($service->staleAfterSeconds())->toBe(180);

    $service->forceFill(['last_checked_at' => $now()->subSeconds(120)])->save();

    expect($service->isStaleAt($now()))->toBeFalse();
});

it('scales the tolerance with a long interval', function () use ($now) {
    $service = Service::factory()->create([
        'interval_seconds' => 900,
        'last_checked_at' => $now()->subMinutes(40),
    ]);

    expect($service->staleAfterSeconds())->toBe(2700)
        ->and($service->isStaleAt($now()))->toBeFalse();
});

it('never calls a paused service stale', function () use ($now) {
    // Nobody is meant to be checking it, so overdue is the expected state.
    $service = Service::factory()->paused()->create([
        'last_checked_at' => $now()->subWeek(),
    ]);

    expect($service->isStaleAt($now()))->toBeFalse();
});

it('never calls a never-checked service stale', function () use ($now) {
    // It already reports Unknown; there is no state to have gone stale.
    $service = Service::factory()->create(['last_checked_at' => null]);

    expect($service->isStaleAt($now()))->toBeFalse();
});

it('reports the scheduler as stalled when every active service is overdue', function () use ($now) {
    $services = Service::factory()->count(3)->create([
        'interval_seconds' => 60,
        'last_checked_at' => $now()->subHour(),
    ]);

    $verdict = app(AssessFreshness::class)->handle($services, $now());

    expect($verdict['stalled'])->toBeTrue()
        ->and($verdict['stale_count'])->toBe(3)
        ->and($verdict['last_check_at'])->toBe($now()->subHour()->toIso8601String());
});

it('does not report the scheduler as stalled when only one service is overdue', function () use ($now) {
    // An unreachable host still gets checked, and that check records a failure. One
    // stale service is odd; all of them means the runner stopped.
    $services = collect([
        Service::factory()->create(['interval_seconds' => 60, 'last_checked_at' => $now()->subHour()]),
        Service::factory()->create(['interval_seconds' => 60, 'last_checked_at' => $now()->subSeconds(30)]),
    ]);

    $verdict = app(AssessFreshness::class)->handle($services, $now());

    expect($verdict['stalled'])->toBeFalse()
        ->and($verdict['stale_count'])->toBe(1);
});

it('ignores paused services when judging the scheduler', function () use ($now) {
    $services = collect([
        Service::factory()->paused()->create(['last_checked_at' => $now()->subWeek()]),
        Service::factory()->create(['interval_seconds' => 60, 'last_checked_at' => $now()->subSeconds(30)]),
    ]);

    $verdict = app(AssessFreshness::class)->handle($services, $now());

    expect($verdict['stalled'])->toBeFalse()
        ->and($verdict['stale_count'])->toBe(0);
});

it('is not stalled when there is nothing active to check', function () use ($now) {
    $services = collect([Service::factory()->paused()->create()]);

    expect(app(AssessFreshness::class)->handle($services, $now())['stalled'])->toBeFalse();
});
