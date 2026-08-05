<?php

declare(strict_types=1);

use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
});

/** A service that was checked a moment ago, so it is never mistaken for stale. */
function fresh(ServiceState $state, string $name, array $overrides = []): Service
{
    return Service::factory()->create(array_merge([
        'name' => $name,
        'current_state' => $state,
        'interval_seconds' => 60,
        'last_checked_at' => CarbonImmutable::now()->subSeconds(20),
    ], $overrides));
}

it('redirects guests to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('reports all clear when every service is up', function () {
    fresh(ServiceState::Up, 'Billr');
    fresh(ServiceState::Up, 'Zero');

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('verdict.tone', 'up')
            ->where('verdict.headline', 'All systems operational')
            ->where('counts.up', 2)
            ->where('counts.watched', 2)
            ->has('attention', 0)
            ->has('openIncidents', 0));
});

it('names the single service that is down', function () {
    fresh(ServiceState::Up, 'Billr');
    fresh(ServiceState::Down, 'Zero');

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('verdict.tone', 'down')
            ->where('verdict.headline', 'Zero is down')
            ->where('counts.down', 1));
});

it('counts services rather than naming them when several are down', function () {
    fresh(ServiceState::Down, 'Billr');
    fresh(ServiceState::Down, 'Zero');

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('verdict.headline', '2 services are down'));
});

it('lets down outrank slow in the headline', function () {
    fresh(ServiceState::Degraded, 'Billr');
    fresh(ServiceState::Down, 'Zero');

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('verdict.tone', 'down')
            ->where('counts.degraded', 1)
            ->where('counts.down', 1));
});

it('lets a dead scheduler outrank an outage', function () {
    // If nothing is being checked, the outage below it is a guess (STAT-19).
    Service::factory()->create([
        'current_state' => ServiceState::Down,
        'interval_seconds' => 60,
        'last_checked_at' => CarbonImmutable::now()->subHour(),
    ]);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('verdict.tone', 'stale')
            ->where('verdict.headline', 'Checks are not running')
            ->where('freshness.stalled', true)
            ->where('counts.stale', 1));
});

it('treats a deploy as operational rather than an outage', function () {
    fresh(ServiceState::Up, 'Billr');
    fresh(ServiceState::Maintenance, 'Zero');

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('verdict.tone', 'maintenance')
            ->where('counts.maintenance', 1));
});

it('says so when nothing is being watched', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('verdict.tone', 'unknown')
            ->where('verdict.headline', 'Nothing is being watched')
            ->where('counts.watched', 0));
});

it('excludes paused services from the watched counts', function () {
    fresh(ServiceState::Up, 'Billr');
    Service::factory()->paused()->create(['name' => 'Old', 'current_state' => ServiceState::Down]);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('verdict.tone', 'up')
            ->where('counts.watched', 1)
            ->where('counts.paused', 1)
            ->where('counts.down', 0)
            ->has('attention', 0));
});

it('lists services needing attention worst first', function () {
    fresh(ServiceState::Up, 'Fine');
    fresh(ServiceState::Degraded, 'Slow');
    fresh(ServiceState::Down, 'Broken');

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('attention', 2)
            ->where('attention.0.name', 'Broken')
            ->where('attention.1.name', 'Slow'));
});

it('carries the strip and sparkline for each service needing attention', function () {
    $service = fresh(ServiceState::Down, 'Broken');
    Check::factory()->for($service)->create([
        'status_code' => 200,
        'response_time_ms' => 120,
        'checked_at' => CarbonImmutable::now(),
    ]);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('attention.0.strip', 60)
            ->has('attention.0.sparkline'));
});

it('surfaces open incidents and leaves resolved ones out', function () {
    $service = fresh(ServiceState::Down, 'Zero');
    Incident::factory()->for($service)->create(['reason' => 'Returned 500, expected 200']);
    Incident::factory()->for($service)->resolved()->create();

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('openIncidents', 1)
            ->where('openIncidents.0.service', 'Zero')
            ->where('openIncidents.0.reason', 'Returned 500, expected 200')
            ->where('verdict.detail', 'Returned 500, expected 200'));
});

it('explains a failure that has not yet been confirmed into an incident', function () {
    // One failing check does not open an incident; the headline should not imply one.
    fresh(ServiceState::Down, 'Zero');

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('openIncidents', 0)
            ->where('verdict.detail', 'No incident is open yet: a failure opens one once a second check confirms it.'));
});

it('adds no uncached scan of the checks table', function () {
    // The strip and sparkline actions are cached; the dashboard must not reach past
    // them into the checks table itself (STAT-21 exists because that got expensive).
    foreach (range(1, 3) as $i) {
        $service = fresh(ServiceState::Down, "Service {$i}");
        Check::factory()->for($service)->count(20)->create(['checked_at' => CarbonImmutable::now()]);
    }

    $this->actingAs($this->user)->get(route('dashboard'));

    $checkQueries = 0;
    DB::listen(function ($query) use (&$checkQueries): void {
        if (str_contains($query->sql, 'from "checks"')) {
            $checkQueries++;
        }
    });

    $this->actingAs($this->user)->get(route('dashboard'))->assertOk();

    expect($checkQueries)->toBe(0);
});
