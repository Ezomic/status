<?php

declare(strict_types=1);

use App\Enums\ServiceState;
use App\Models\Incident;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Cache::flush();
});

/** A public, actively-checked service that will not be mistaken for stale. */
function published(ServiceState $state, string $name, array $overrides = []): Service
{
    return Service::factory()->create(array_merge([
        'name' => $name,
        'current_state' => $state,
        'is_active' => true,
        'is_public' => true,
        'interval_seconds' => 60,
        'last_checked_at' => CarbonImmutable::now()->subSeconds(20),
    ], $overrides));
}

it('renders for a visitor who is not signed in', function () {
    published(ServiceState::Up, 'Tracker');

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PublicStatus')
            ->where('verdict.tone', 'up')
            ->where('verdict.headline', 'All systems operational')
            ->has('services', 1));
});

it('shows only services that opted in', function () {
    published(ServiceState::Up, 'Public one');
    Service::factory()->create(['name' => 'Private one', 'is_public' => false]);
    Service::factory()->create(['name' => 'Paused one', 'is_public' => true, 'is_active' => false]);

    $response = $this->get(route('home'))->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('services', 1)
        ->where('services.0.name', 'Public one'));

    $this->assertStringNotContainsString('Private one', $response->getContent());
    $this->assertStringNotContainsString('Paused one', $response->getContent());
});

/**
 * The load-bearing test for this ticket. Inertia serialises props into the HTML, so the
 * whole document is checked, not just a JSON slice.
 */
it('leaks no hostname, url, timing, status code or incident reason', function () {
    $service = published(ServiceState::Down, 'Portal', [
        'url' => 'https://secret-host.internal/health-check-path',
        'expected_body' => 'super-secret-marker',
        'last_response_time_ms' => 1337,
    ]);

    // The worst offender: reasons are raw cURL errors carrying the full hostname.
    Incident::factory()->for($service)->create([
        'reason' => 'cURL error 6: Could not resolve host: secret-host.internal',
        'severity' => ServiceState::Down,
    ]);

    $body = $this->get(route('home'))->assertOk()->getContent();

    foreach ([
        'secret-host.internal',
        'health-check-path',
        'super-secret-marker',
        'cURL',
        'Could not resolve host',
        '1337',
    ] as $secret) {
        expect($body)->not->toContain($secret);
    }
});

it('exposes only the whitelisted keys per service', function () {
    published(ServiceState::Up, 'Tracker');

    $this->get(route('home'))
        ->assertInertia(function (AssertableInertia $page) {
            $service = $page->toArray()['props']['services'][0];

            expect(array_keys($service))
                ->toBe(['slug', 'name', 'state', 'stale', 'last_checked_at']);
        });
});

it('derives the headline from the worst state present', function () {
    published(ServiceState::Up, 'Fine');
    published(ServiceState::Degraded, 'Slow');
    published(ServiceState::Down, 'Broken');

    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('verdict.tone', 'down')
            ->where('verdict.headline', '1 of 3 services is down'));
});

it('reports maintenance without calling it an outage', function () {
    published(ServiceState::Up, 'Fine');
    published(ServiceState::Maintenance, 'Deploying');

    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('verdict.tone', 'maintenance')
            ->where('verdict.headline', '1 of 2 services is under maintenance'));
});

it('says all services when every one of them is down', function () {
    published(ServiceState::Down, 'A');
    published(ServiceState::Down, 'B');

    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('verdict.headline', 'All 2 services are down'));
});

it('does not present a frozen state as current', function () {
    // The runner stopped an hour ago; the recorded state says up (STAT-19).
    published(ServiceState::Up, 'Tracker', [
        'last_checked_at' => CarbonImmutable::now()->subHour(),
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('services.0.state', 'unknown')
            ->where('services.0.stale', true)
            ->where('verdict.tone', 'unknown')
            ->where('verdict.headline', 'Current status unavailable'));
});

it('says so when nothing is published', function () {
    Service::factory()->create(['is_public' => false]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('services', 0)
            ->where('verdict.tone', 'unknown')
            ->where('verdict.headline', 'No services are being reported'));
});

it('keeps the machine endpoint and the page in agreement', function () {
    config()->set('services.status_endpoint.token', 'secret-token');
    published(ServiceState::Degraded, 'Tracker');

    $page = $this->get(route('home'))->assertOk();
    $json = $this->getJson(route('api.status'), ['Authorization' => 'Bearer secret-token'])->assertOk();

    // Same builder, so the same states: neither surface can drift from the other.
    expect($json->json('services'))
        ->toBe($page->inertiaPage()['props']['services']);
});

it('names the unconfirmed ones when only some are stale', function () {
    published(ServiceState::Up, 'Fine');
    published(ServiceState::Up, 'Frozen', ['last_checked_at' => CarbonImmutable::now()->subHour()]);

    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('verdict.tone', 'unknown')
            ->where('verdict.headline', '1 of 2 services is not currently confirmed'));
});

it('lets a real outage outrank an unconfirmed service', function () {
    published(ServiceState::Down, 'Broken');
    published(ServiceState::Up, 'Frozen', ['last_checked_at' => CarbonImmutable::now()->subHour()]);

    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('verdict.tone', 'down'));
});
