<?php

declare(strict_types=1);

use App\Actions\Monitoring\BuildResponseSparklines;
use App\Models\Check;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Cache::flush();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns one averaged point per hour per service, oldest first', function () {
    $service = Service::factory()->create();
    $start = CarbonImmutable::parse('2026-08-05 10:00:00');

    CarbonImmutable::setTestNow($start->addHours(3));

    // Two checks in the 10:00 hour averaging 150ms, one in the 11:00 hour at 400ms.
    Check::factory()->for($service)->create(['response_time_ms' => 100, 'status_code' => 200, 'checked_at' => $start]);
    Check::factory()->for($service)->create(['response_time_ms' => 200, 'status_code' => 200, 'checked_at' => $start->addMinutes(30)]);
    Check::factory()->for($service)->create(['response_time_ms' => 400, 'status_code' => 200, 'checked_at' => $start->addHour()]);

    $sparklines = app(BuildResponseSparklines::class)->handle();

    expect($sparklines[$service->id])->toBe([150, 400]);
});

it('ignores checks that never got a response', function () {
    $service = Service::factory()->create();
    $now = CarbonImmutable::parse('2026-08-05 10:00:00');
    CarbonImmutable::setTestNow($now);

    // A failed connection records 0ms, which would drag the average toward an
    // impossibly fast request.
    Check::factory()->for($service)->create(['response_time_ms' => 0, 'status_code' => null, 'checked_at' => $now]);
    Check::factory()->for($service)->create(['response_time_ms' => 300, 'status_code' => 200, 'checked_at' => $now]);

    expect(app(BuildResponseSparklines::class)->handle()[$service->id])->toBe([300]);
});

it('excludes checks older than a day', function () {
    $service = Service::factory()->create();
    $now = CarbonImmutable::parse('2026-08-05 10:00:00');
    CarbonImmutable::setTestNow($now);

    Check::factory()->for($service)->create(['response_time_ms' => 999, 'status_code' => 200, 'checked_at' => $now->subDays(3)]);

    expect(app(BuildResponseSparklines::class)->handle())->toBe([]);
});

it('aggregates in SQL rather than hydrating every check', function () {
    $service = Service::factory()->create();
    $now = CarbonImmutable::parse('2026-08-05 23:30:00');
    CarbonImmutable::setTestNow($now);

    // A full day at a 60 second interval: what the real table looks like.
    $rows = [];

    for ($minute = 0; $minute < 1440; $minute++) {
        $rows[] = [
            'service_id' => $service->id,
            'status_code' => 200,
            'response_time_ms' => 100 + ($minute % 50),
            'ok' => true,
            'state' => 'up',
            'error' => null,
            'checked_at' => $now->subMinutes($minute)->toDateTimeString(),
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('checks')->insert($chunk);
    }

    $returned = 0;
    DB::listen(function ($query) use (&$returned): void {
        if (str_contains($query->sql, 'from "checks"')) {
            $returned++;
        }
    });

    $sparklines = app(BuildResponseSparklines::class)->handle();

    // 1440 checks in, at most 25 hourly buckets out, from a single query.
    expect($returned)->toBe(1)
        ->and(count($sparklines[$service->id]))->toBeLessThanOrEqual(25);
});

it('caches the payload so a second call issues no query', function () {
    $service = Service::factory()->create();
    $now = CarbonImmutable::parse('2026-08-05 10:00:00');
    CarbonImmutable::setTestNow($now);

    Check::factory()->for($service)->create(['response_time_ms' => 120, 'status_code' => 200, 'checked_at' => $now]);

    $action = app(BuildResponseSparklines::class);
    $action->handle();

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'from "checks"')) {
            $queries++;
        }
    });

    expect($action->handle()[$service->id])->toBe([120])
        ->and($queries)->toBe(0);
});
