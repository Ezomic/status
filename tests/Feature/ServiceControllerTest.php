<?php

declare(strict_types=1);

use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

/** @return array<string, mixed> */
function validPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Tracker',
        'url' => 'https://tracker.thijssensoftware.nl',
        'expected_status_code' => 200,
        'interval_seconds' => 60,
        'timeout_seconds' => 5,
        'degraded_threshold_ms' => 1000,
        'is_active' => true,
    ], $overrides);
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('lists services alphabetically with their current state', function () {
    Service::factory()->create(['name' => 'Zero', 'current_state' => ServiceState::Down]);
    Service::factory()->create(['name' => 'Billr', 'current_state' => ServiceState::Up]);

    $this->actingAs($this->user)
        ->get(route('services.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('services/Index')
            ->has('services', 2)
            ->where('services.0.name', 'Billr')
            ->where('services.0.state', 'up')
            ->where('services.1.name', 'Zero')
            ->where('services.1.state', 'down')
        );
});

it('surfaces open incidents on the list', function () {
    $service = Service::factory()->create(['name' => 'Stocks']);
    Incident::factory()->for($service)->degraded()->create();
    Incident::factory()->for($service)->resolved()->create();

    $this->actingAs($this->user)
        ->get(route('services.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('openIncidents', 1)
            ->where('openIncidents.0.service', 'Stocks')
            ->where('openIncidents.0.severity', 'degraded')
        );
});

it('builds a 60 day strip with one slot per day', function () {
    $service = Service::factory()->create();
    Check::factory()->for($service)->down()->create(['checked_at' => CarbonImmutable::today()]);

    $this->actingAs($this->user)
        ->get(route('services.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('services.0.strip', 60)
            ->where('services.0.strip.59.state', 'down')
            ->where('services.0.strip.0.state', 'none')
        );
});

it('shows a service with its uptime windows and recent checks', function () {
    $service = Service::factory()->create();
    Check::factory()->for($service)->count(3)->create(['checked_at' => CarbonImmutable::now()->subMinutes(5)]);
    Check::factory()->for($service)->down()->create(['checked_at' => CarbonImmutable::now()->subMinutes(2)]);

    $this->actingAs($this->user)
        ->get(route('services.show', $service))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('services/Show')
            ->where('service.id', $service->id)
            ->where('uptime.day', 75)
            ->has('recentChecks', 4)
            ->has('responseTimes', 4)
        );
});

it('reports null uptime for a service that has never been checked', function () {
    $service = Service::factory()->create();

    $this->actingAs($this->user)
        ->get(route('services.show', $service))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('uptime.day', null));
});

it('adds a service', function () {
    $this->actingAs($this->user)
        ->post(route('services.store'), validPayload())
        ->assertSessionHasNoErrors();

    expect(Service::sole())
        ->name->toBe('Tracker')
        ->current_state->toBe(ServiceState::Unknown);
});

it('rejects a url without a scheme', function () {
    $this->actingAs($this->user)
        ->post(route('services.store'), validPayload(['url' => 'tracker.thijssensoftware.nl']))
        ->assertSessionHasErrors('url');

    expect(Service::count())->toBe(0);
});

it('rejects an interval under thirty seconds', function () {
    $this->actingAs($this->user)
        ->post(route('services.store'), validPayload(['interval_seconds' => 5]))
        ->assertSessionHasErrors('interval_seconds');
});

it('updates a service', function () {
    $service = Service::factory()->create(['name' => 'Old name']);

    $this->actingAs($this->user)
        ->put(route('services.update', $service), validPayload(['name' => 'New name']))
        ->assertSessionHasNoErrors();

    expect($service->refresh()->name)->toBe('New name');
});

it('resolves an open incident when a service is paused', function () {
    $service = Service::factory()->create();
    Incident::factory()->for($service)->create();

    $this->actingAs($this->user)
        ->put(route('services.update', $service), validPayload(['is_active' => false]))
        ->assertSessionHasNoErrors();

    expect($service->refresh()->is_active)->toBeFalse()
        ->and($service->openIncident())->toBeNull()
        ->and(Incident::sole()->reason)->toBe('Monitoring disabled');
});

it('leaves an open incident alone when a paused service is edited again', function () {
    $service = Service::factory()->paused()->create();
    Incident::factory()->for($service)->create(['reason' => 'Returned 500, expected 200']);

    $this->actingAs($this->user)
        ->put(route('services.update', $service), validPayload(['is_active' => false]))
        ->assertSessionHasNoErrors();

    expect(Incident::sole()->reason)->toBe('Returned 500, expected 200');
});

it('deletes a service and its checks', function () {
    $service = Service::factory()->create();
    Check::factory()->for($service)->count(2)->create();

    $this->actingAs($this->user)
        ->delete(route('services.destroy', $service))
        ->assertRedirect(route('services.index'));

    expect(Service::count())->toBe(0)
        ->and(Check::count())->toBe(0);
});

it('lists incidents with open ones first', function () {
    $service = Service::factory()->create();
    Incident::factory()->for($service)->resolved()->create(['started_at' => CarbonImmutable::now()->subDay()]);
    Incident::factory()->for($service)->create(['started_at' => CarbonImmutable::now()->subWeek()]);

    $this->actingAs($this->user)
        ->get(route('incidents.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Incidents')
            ->has('incidents', 2)
            ->where('incidents.0.resolved_at', null)
        );
});

it('keeps guests out', function () {
    $service = Service::factory()->create();

    $this->get(route('services.index'))->assertRedirect(route('login'));
    $this->get(route('services.show', $service))->assertRedirect(route('login'));
    $this->get(route('incidents.index'))->assertRedirect(route('login'));
    $this->post(route('services.store'), validPayload())->assertRedirect(route('login'));

    expect(Service::count())->toBe(1);
});

it('shows a maintenance day on the strip without counting it as downtime', function () {
    $service = Service::factory()->create(['current_state' => ServiceState::Maintenance]);

    // A day with maintenance and nothing else wrong reads as maintenance, and has no
    // measurable uptime at all (STAT-18).
    Check::factory()->for($service)->count(3)->create([
        'state' => ServiceState::Maintenance,
        'status_code' => 503,
        'checked_at' => now(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('services.show', $service))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('service.state_label', 'Maintenance')
            ->where('uptime.day', null));
});

it('keeps maintenance out of the uptime ratio', function () {
    $service = Service::factory()->create();

    // 1 down and 1 up out of 2 measurable checks is 50%, not 25% of four.
    Check::factory()->for($service)->create(['state' => ServiceState::Up, 'checked_at' => now()]);
    Check::factory()->for($service)->create(['state' => ServiceState::Down, 'status_code' => 500, 'checked_at' => now()]);
    Check::factory()->for($service)->count(2)->create([
        'state' => ServiceState::Maintenance,
        'status_code' => 503,
        'checked_at' => now(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('services.show', $service))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('uptime.day', 50));
});

it('does not let a short deploy repaint an otherwise healthy day on the strip', function () {
    $service = Service::factory()->create(['current_state' => ServiceState::Up]);

    Check::factory()->for($service)->count(20)->create(['state' => ServiceState::Up, 'checked_at' => now()]);
    Check::factory()->for($service)->count(3)->create([
        'state' => ServiceState::Maintenance,
        'status_code' => 503,
        'checked_at' => now(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('services.show', $service))
        ->assertInertia(function (AssertableInertia $page) {
            $today = collect($page->toArray()['props']['service']['strip'])->last();

            // Reads as up, but still flags that a deploy happened.
            expect($today['state'])->toBe('up')
                ->and($today['maintenance'])->toBeTrue()
                ->and($today['uptime'])->toEqual(100);
        });
});

it('accepts an expected content string', function () {
    $this->actingAs($this->user)
        ->post(route('services.store'), validPayload(['expected_body' => 'Sign in']))
        ->assertSessionHasNoErrors();

    expect(Service::sole()->expected_body)->toBe('Sign in');
});

it('stores no expected content when the field is left empty', function () {
    // ConvertEmptyStringsToNull turns the empty input into null, so an untouched
    // field opts the service out rather than asserting on an empty string.
    $this->actingAs($this->user)
        ->post(route('services.store'), validPayload(['expected_body' => '']))
        ->assertSessionHasNoErrors();

    expect(Service::sole()->expected_body)->toBeNull();
});

it('rejects an expected content string longer than the column', function () {
    $this->actingAs($this->user)
        ->post(route('services.store'), validPayload(['expected_body' => str_repeat('a', 256)]))
        ->assertSessionHasErrors('expected_body');
});

it('surfaces the expected content on the service page', function () {
    $service = Service::factory()->create(['expected_body' => 'Sign in']);

    $this->actingAs($this->user)
        ->get(route('services.show', $service))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('service.expected_body', 'Sign in'));
});

it('flags a service whose checks have gone overdue', function () {
    Service::factory()->create([
        'interval_seconds' => 60,
        'last_checked_at' => CarbonImmutable::now()->subHour(),
        'current_state' => ServiceState::Up,
    ]);

    $this->actingAs($this->user)
        ->get(route('services.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('services.0.is_stale', true)
            ->where('freshness.stalled', true)
            ->where('freshness.stale_count', 1));
});

it('reports nothing stale when every service was just checked', function () {
    Service::factory()->count(2)->create([
        'interval_seconds' => 60,
        'last_checked_at' => CarbonImmutable::now()->subSeconds(20),
    ]);

    $this->actingAs($this->user)
        ->get(route('services.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('services.0.is_stale', false)
            ->where('freshness.stalled', false)
            ->where('freshness.stale_count', 0));
});

it('shows a certificate that is comfortably valid', function () {
    $service = Service::factory()->create([
        'certificate_expires_at' => CarbonImmutable::now()->addDays(62),
    ]);

    $this->actingAs($this->user)
        ->get(route('services.show', $service))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('service.certificate_days_remaining', 62)
            ->where('service.certificate_warn_within_days', 30));
});

it('reports an unknown certificate expiry as null rather than as expiring', function () {
    $service = Service::factory()->create(['certificate_expires_at' => null]);

    $this->actingAs($this->user)
        ->get(route('services.show', $service))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('service.certificate_expires_at', null)
            ->where('service.certificate_days_remaining', null));
});

it('reports an unreadable certificate as looked-at but unknown', function () {
    // The distinction the page needs: inspected and unreadable is not the same as
    // never inspected, and neither may read as expiring.
    $service = Service::factory()->create([
        'url' => 'https://a.test',
        'certificate_expires_at' => null,
        'certificate_checked_at' => CarbonImmutable::now(),
    ]);

    $this->actingAs($this->user)
        ->get(route('services.show', $service))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('service.uses_tls', true)
            ->where('service.certificate_expires_at', null)
            ->where('service.certificate_days_remaining', null)
            ->whereNot('service.certificate_checked_at', null));
});

it('marks an http service as not using tls', function () {
    $service = Service::factory()->create(['url' => 'http://a.test']);

    $this->actingAs($this->user)
        ->get(route('services.show', $service))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('service.uses_tls', false));
});
