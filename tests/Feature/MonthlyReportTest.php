<?php

declare(strict_types=1);

use App\Actions\Monitoring\BuildMonthlyReport;
use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Response times 1..100 at a known minute each, so the percentile positions are not a
 * matter of opinion: p50 is 50 and p95 is 95.
 *
 * @param  list<int>  $times
 */
function withLatency(Service $service, array $times, CarbonImmutable $month): void
{
    foreach ($times as $index => $ms) {
        Check::factory()->for($service)->create([
            'state' => ServiceState::Up,
            'ok' => true,
            'status_code' => 200,
            'response_time_ms' => $ms,
            'checked_at' => $month->startOfMonth()->addMinutes($index),
        ]);
    }
}

it('pins p50 and p95 against a known set of checks', function () {
    $month = CarbonImmutable::parse('2026-08-01');
    $service = Service::factory()->create();

    withLatency($service, range(1, 100), $month);

    $report = app(BuildMonthlyReport::class)->handle($month);

    expect($report['services'][0]['p50'])->toBe(50)
        ->and($report['services'][0]['p95'])->toBe(95);
});

it('ignores failed connections when computing percentiles', function () {
    // A connection that never opened records 0ms, which is not a fast response. Left in,
    // it would pull the median down during exactly the month worth reporting on.
    $month = CarbonImmutable::parse('2026-08-01');
    $service = Service::factory()->create();

    withLatency($service, [100, 200, 300], $month);

    Check::factory()->for($service)->count(20)->create([
        'state' => ServiceState::Down,
        'ok' => false,
        'status_code' => null,
        'response_time_ms' => 0,
        'checked_at' => $month->startOfMonth()->addHour(),
    ]);

    $report = app(BuildMonthlyReport::class)->handle($month);

    expect($report['services'][0]['p50'])->toBe(200);
});

it('computes uptime excluding maintenance', function () {
    $month = CarbonImmutable::parse('2026-08-01');
    $service = Service::factory()->create();
    $at = $month->startOfMonth();

    Check::factory()->for($service)->count(9)->create(['state' => ServiceState::Up, 'ok' => true, 'checked_at' => $at]);
    Check::factory()->for($service)->create(['state' => ServiceState::Down, 'ok' => false, 'status_code' => 500, 'checked_at' => $at]);
    Check::factory()->for($service)->count(5)->create([
        'state' => ServiceState::Maintenance,
        'ok' => false,
        'status_code' => 503,
        'checked_at' => $at,
    ]);

    $report = app(BuildMonthlyReport::class)->handle($month);

    expect($report['services'][0]['uptime'])->toBe(90.0)
        ->and($report['services'][0]['checks'])->toBe(10);
});

it('reads a month with no data as no data rather than zero percent', function () {
    $month = CarbonImmutable::parse('2026-08-01');
    Service::factory()->create();

    $report = app(BuildMonthlyReport::class)->handle($month);

    expect($report['services'][0]['uptime'])->toBeNull()
        ->and($report['services'][0]['p50'])->toBeNull()
        ->and($report['services'][0]['met'])->toBeNull()
        ->and($report['services'][0]['checks'])->toBe(0);
});

it('leaves a month out of the report when its checks belong to another month', function () {
    $service = Service::factory()->create();

    withLatency($service, [100], CarbonImmutable::parse('2026-07-01'));

    $report = app(BuildMonthlyReport::class)->handle(CarbonImmutable::parse('2026-08-01'));

    expect($report['services'][0]['uptime'])->toBeNull();
});

it('reads a target as met or missed', function () {
    $month = CarbonImmutable::parse('2026-08-01');
    $at = $month->startOfMonth();

    $meets = Service::factory()->create(['name' => 'Meets', 'uptime_target' => 90]);
    $misses = Service::factory()->create(['name' => 'Misses', 'uptime_target' => 99.9]);

    foreach ([$meets, $misses] as $service) {
        Check::factory()->for($service)->count(9)->create(['state' => ServiceState::Up, 'ok' => true, 'checked_at' => $at]);
        Check::factory()->for($service)->create(['state' => ServiceState::Down, 'ok' => false, 'status_code' => 500, 'checked_at' => $at]);
    }

    $report = app(BuildMonthlyReport::class)->handle($month);

    expect($report['services'][0]['met'])->toBeTrue()
        ->and($report['services'][1]['met'])->toBeFalse();
});

it('reports no verdict for a service with no target', function () {
    // Nobody agreed a number, so the month cannot have been missed.
    $month = CarbonImmutable::parse('2026-08-01');
    $service = Service::factory()->create(['uptime_target' => null]);

    Check::factory()->for($service)->create(['state' => ServiceState::Down, 'ok' => false, 'status_code' => 500, 'checked_at' => $month->startOfMonth()]);

    $report = app(BuildMonthlyReport::class)->handle($month);

    expect($report['services'][0]['uptime'])->toBe(0.0)
        ->and($report['services'][0]['met'])->toBeNull();
});

it('counts incidents by the month they started in and sums their duration', function () {
    $month = CarbonImmutable::parse('2026-08-01');
    $service = Service::factory()->create();

    Incident::factory()->for($service)->create([
        'started_at' => $month->startOfMonth()->addDay(),
        'resolved_at' => $month->startOfMonth()->addDay()->addMinutes(30),
    ]);
    Incident::factory()->for($service)->create([
        'started_at' => $month->startOfMonth()->addDays(2),
        'resolved_at' => $month->startOfMonth()->addDays(2)->addMinutes(15),
    ]);
    Incident::factory()->for($service)->create([
        'started_at' => CarbonImmutable::parse('2026-07-15'),
        'resolved_at' => CarbonImmutable::parse('2026-07-15')->addHour(),
    ]);

    $report = app(BuildMonthlyReport::class)->handle($month);

    expect($report['services'][0]['incidents'])->toBe(2)
        ->and($report['services'][0]['downtime_seconds'])->toBe(45 * 60)
        ->and($report['services'][0]['unresolved'])->toBeFalse();
});

it('clamps an unresolved incident to now rather than reporting future time', function () {
    CarbonImmutable::setTestNow('2026-08-13 12:00:00');

    $service = Service::factory()->create();
    Incident::factory()->for($service)->create([
        'started_at' => CarbonImmutable::parse('2026-08-13 11:00:00'),
        'resolved_at' => null,
    ]);

    $report = app(BuildMonthlyReport::class)->handle(CarbonImmutable::parse('2026-08-01'));

    expect($report['services'][0]['downtime_seconds'])->toBe(3600)
        ->and($report['services'][0]['unresolved'])->toBeTrue();

    CarbonImmutable::setTestNow();
});

it('builds the whole report in a handful of queries regardless of how many checks exist', function () {
    // The point of computing percentiles in SQL. Three grouped queries plus the service
    // list, whether there are 400 checks or 400,000.
    $month = CarbonImmutable::parse('2026-08-01');
    $services = Service::factory()->count(4)->create();

    foreach ($services as $service) {
        withLatency($service, range(1, 100), $month);
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    app(BuildMonthlyReport::class)->handle($month);

    expect(count($queries))->toBeLessThanOrEqual(6);
});

it('hydrates only a row per service, not a row per check', function () {
    $month = CarbonImmutable::parse('2026-08-01');
    $service = Service::factory()->create();

    withLatency($service, range(1, 300), $month);

    $rows = 0;
    DB::listen(function () use (&$rows) {
        $rows++;
    });

    $report = app(BuildMonthlyReport::class)->handle($month);

    // 300 checks in, one row of numbers out.
    expect($report['services'])->toHaveCount(1)
        ->and($rows)->toBeLessThanOrEqual(6);
});

it('shows the report to an operator with the months it can offer', function () {
    CarbonImmutable::setTestNow('2026-08-13 12:00:00');

    Service::factory()->create(['name' => 'Zero', 'group' => 'Apps']);

    $this->actingAs(User::factory()->create())
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports')
            ->has('months', BuildMonthlyReport::MONTHS_AVAILABLE)
            ->where('months.0.month', '2026-08')
            ->where('report.month', '2026-08')
            ->where('report.partial', true)
            ->where('report.services.0.name', 'Zero')
            ->where('report.services.0.group', 'Apps'));

    CarbonImmutable::setTestNow();
});

it('reads a completed month as complete', function () {
    CarbonImmutable::setTestNow('2026-08-13 12:00:00');

    $this->actingAs(User::factory()->create())
        ->get(route('reports.index', ['month' => '2026-07']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.month', '2026-07')
            ->where('report.partial', false));

    CarbonImmutable::setTestNow();
});

it('flags the oldest month as partial because pruning has already eaten into it', function () {
    // Checks are dropped after 90 days, so the far end of the window is a truncated
    // month rather than a bad one.
    CarbonImmutable::setTestNow('2026-08-13 12:00:00');

    $this->actingAs(User::factory()->create())
        ->get(route('reports.index', ['month' => '2026-05']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.month', '2026-05')
            ->where('report.partial', true));

    CarbonImmutable::setTestNow();
});

it('falls back to the current month for a month outside the window', function () {
    CarbonImmutable::setTestNow('2026-08-13 12:00:00');

    $this->actingAs(User::factory()->create())
        ->get(route('reports.index', ['month' => '2019-01']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('report.month', '2026-08'));

    CarbonImmutable::setTestNow();
});

it('does not fall over on a nonsense month parameter', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('reports.index', ['month' => 'not-a-month']))
        ->assertOk();
});

it('accepts an uptime target through the service form', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('services.store'), validPayload(['uptime_target' => 99.5]))
        ->assertSessionHasNoErrors();

    expect(Service::sole()->uptime_target)->toBe(99.5);
});

it('rejects an uptime target above a hundred percent', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('services.store'), validPayload(['uptime_target' => 101]))
        ->assertSessionHasErrors('uptime_target');
});

it('keeps guests out of the report', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});
