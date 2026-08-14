<?php

declare(strict_types=1);

use App\Actions\Monitoring\BuildMonthlyReport;
use App\Actions\Monitoring\BuildResponseSparklines;
use App\Actions\Monitoring\BuildUptimeStrip;
use App\Actions\Monitoring\EvaluateIncident;
use App\Actions\Monitoring\RecordCheck;
use App\Enums\CheckSource;
use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Service;
use App\Models\User;
use App\ValueObjects\ProbeResult;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('counts a check as taken from the droplet unless it says otherwise', function () {
    // Every one of the 142k checks recorded before this column existed was taken by
    // monitor:run, so the default has to be the truth for them rather than unknown.
    $service = Service::factory()->create();

    $check = Check::query()->create([
        'service_id' => $service->id,
        'status_code' => 200,
        'response_time_ms' => 100,
        'ok' => true,
        'state' => ServiceState::Up,
        'checked_at' => CarbonImmutable::now(),
    ]);

    expect($check->refresh()->source)->toBe(CheckSource::Internal);
});

it('records a probe as internal', function () {
    $service = Service::factory()->create();

    $check = app(RecordCheck::class)->handle(
        $service,
        new ProbeResult(200, 120),
        CarbonImmutable::now(),
    );

    expect($check->source)->toBe(CheckSource::Internal);
});

it('keeps an external check out of the uptime ratio', function () {
    $service = Service::factory()->create();
    $at = CarbonImmutable::now()->subMinutes(5);

    Check::factory()->for($service)->count(4)->create(['state' => ServiceState::Up, 'ok' => true, 'checked_at' => $at]);

    // Four internal up checks is 100%. Four external down checks must not make it 50%.
    Check::factory()->for($service)->external()->count(4)->create([
        'state' => ServiceState::Down,
        'ok' => false,
        'status_code' => 500,
        'checked_at' => $at,
    ]);

    $this->actingAs($this->user)
        ->get(route('services.show', $service))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('uptime.day', 100)
            ->has('recentChecks', 4));
});

it('keeps an external check off the uptime strip', function () {
    $service = Service::factory()->create();

    Check::factory()->for($service)->count(3)->create(['state' => ServiceState::Up, 'ok' => true, 'checked_at' => CarbonImmutable::today()]);
    Check::factory()->for($service)->external()->count(9)->create([
        'state' => ServiceState::Down,
        'ok' => false,
        'status_code' => 500,
        'checked_at' => CarbonImmutable::today(),
    ]);

    $today = collect(app(BuildUptimeStrip::class)->handle()[$service->id])->last();

    expect($today['state'])->toBe('up')
        ->and($today['uptime'])->toEqual(100);
});

it('keeps an external check out of the latency sparkline', function () {
    $service = Service::factory()->create();
    $at = CarbonImmutable::now()->subMinutes(10);

    Check::factory()->for($service)->create(['response_time_ms' => 100, 'status_code' => 200, 'checked_at' => $at]);
    Check::factory()->for($service)->external()->create(['response_time_ms' => 5000, 'status_code' => 200, 'checked_at' => $at]);

    $points = app(BuildResponseSparklines::class)->handle()[$service->id] ?? [];

    expect($points)->toHaveCount(1)
        ->and($points[0])->toEqual(100);
});

it('keeps an external check out of the monthly report', function () {
    $month = CarbonImmutable::parse('2026-08-01');
    $service = Service::factory()->create();

    foreach (range(1, 10) as $index => $ms) {
        Check::factory()->for($service)->create([
            'response_time_ms' => $ms * 10,
            'status_code' => 200,
            'state' => ServiceState::Up,
            'checked_at' => $month->startOfMonth()->addMinutes($index),
        ]);
    }

    // Wildly different numbers from outside. The report is about the droplet's own view
    // until STAT-46 gives the page somewhere to show both.
    Check::factory()->for($service)->external()->count(10)->create([
        'response_time_ms' => 9000,
        'status_code' => 200,
        'state' => ServiceState::Down,
        'ok' => false,
        'checked_at' => $month->startOfMonth()->addHour(),
    ]);

    $report = app(BuildMonthlyReport::class)->handle($month);

    expect($report['services'][0]['uptime'])->toBe(100.0)
        ->and($report['services'][0]['checks'])->toBe(10)
        ->and($report['services'][0]['p95'])->toBe(100);
});

it('does not open an incident from external checks', function () {
    // An outage decided by a source polling every few minutes is a different question,
    // and it belongs with STAT-46 rather than being inherited by accident.
    $service = Service::factory()->create();

    Check::factory()->for($service)->external()->down()->create(['checked_at' => CarbonImmutable::now()->subMinute()]);
    $second = Check::factory()->for($service)->external()->down()->create(['checked_at' => CarbonImmutable::now()]);

    app(EvaluateIncident::class)->handle($service, $second);

    expect($service->openIncident())->toBeNull();
});

it('does not let an internal failure be confirmed by an external one', function () {
    // The direction that a source filter on previousCheck() alone would miss: the
    // previous check is internal and matches, so only standing down on the incoming
    // check's source stops a mixed pair opening an incident.
    $service = Service::factory()->create();

    Check::factory()->for($service)->down()->create(['checked_at' => CarbonImmutable::now()->subMinutes(2)]);
    $external = Check::factory()->for($service)->external()->down()->create(['checked_at' => CarbonImmutable::now()]);

    app(EvaluateIncident::class)->handle($service, $external);

    expect($service->openIncident())->toBeNull();
});

it('does not let an external check confirm an internal one', function () {
    // The two-check confirmation must be built from two observations of the same kind,
    // or a single internal failure plus one external reading opens an incident.
    $service = Service::factory()->create();

    Check::factory()->for($service)->external()->down()->create(['checked_at' => CarbonImmutable::now()->subMinutes(2)]);
    $internal = Check::factory()->for($service)->down()->create(['checked_at' => CarbonImmutable::now()]);

    app(EvaluateIncident::class)->handle($service, $internal);

    expect($service->openIncident())->toBeNull();
});

it('still opens an incident from two internal checks', function () {
    // The guard above must not have switched incident detection off altogether.
    $service = Service::factory()->create();

    Check::factory()->for($service)->down()->create(['checked_at' => CarbonImmutable::now()->subMinute()]);
    $second = Check::factory()->for($service)->down()->create(['checked_at' => CarbonImmutable::now()]);

    app(EvaluateIncident::class)->handle($service, $second);

    expect($service->openIncident())->not->toBeNull();
});
