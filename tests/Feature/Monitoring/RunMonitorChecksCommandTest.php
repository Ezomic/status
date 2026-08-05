<?php

declare(strict_types=1);

use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Each test registers its own fake. Http::fake() merges rather than replaces, so a
 * shared catch-all in beforeEach would shadow any narrower stub a test sets later.
 */
function fakeAllUp(): void
{
    Http::fake(['*' => Http::response('ok', 200)]);
}

it('checks a service that has never been checked', function () {
    fakeAllUp();

    $service = Service::factory()->create(['last_checked_at' => null]);

    $this->artisan('monitor:run')->assertSuccessful();

    expect($service->checks()->count())->toBe(1);
});

it('skips a service whose interval has not elapsed', function () {
    fakeAllUp();

    $service = Service::factory()->create([
        'interval_seconds' => 300,
        'last_checked_at' => CarbonImmutable::now()->subMinute(),
    ]);

    $this->artisan('monitor:run')->assertSuccessful();

    expect($service->checks()->count())->toBe(0);
});

it('checks a service whose interval has elapsed', function () {
    fakeAllUp();

    $service = Service::factory()->create([
        'interval_seconds' => 60,
        'last_checked_at' => CarbonImmutable::now()->subMinutes(5),
    ]);

    $this->artisan('monitor:run')->assertSuccessful();

    expect($service->checks()->count())->toBe(1);
});

it('checks a service before its interval elapsed when forced', function () {
    fakeAllUp();

    $service = Service::factory()->create([
        'interval_seconds' => 3600,
        'last_checked_at' => CarbonImmutable::now(),
    ]);

    $this->artisan('monitor:run', ['--force' => true])->assertSuccessful();

    expect($service->checks()->count())->toBe(1);
});

it('skips inactive services', function () {
    fakeAllUp();

    $paused = Service::factory()->paused()->create();

    $this->artisan('monitor:run')->assertSuccessful();

    expect($paused->checks()->count())->toBe(0);
});

it('records one check per due service in a single run', function () {
    fakeAllUp();

    Service::factory()->count(3)->create();

    $this->artisan('monitor:run')->assertSuccessful();

    expect(Check::count())->toBe(3);
});

it('stamps every check in a run with the same timestamp', function () {
    fakeAllUp();

    Service::factory()->count(3)->create();

    $this->artisan('monitor:run')->assertSuccessful();

    expect(Check::query()->distinct()->pluck('checked_at'))->toHaveCount(1);
});

it('keeps checking other services when one is unreachable', function () {
    Http::fake([
        '*dead.test*' => fn () => throw new ConnectionException('unreachable'),
        '*' => Http::response('ok', 200),
    ]);

    $dead = Service::factory()->create(['url' => 'https://dead.test']);
    $alive = Service::factory()->create(['url' => 'https://alive.test']);

    $this->artisan('monitor:run')->assertSuccessful();

    expect($dead->checks()->sole()->state)->toBe(ServiceState::Down)
        ->and($alive->checks()->sole()->state)->toBe(ServiceState::Up);
});

it('opens an incident on the second consecutive failing run', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $service = Service::factory()->create(['interval_seconds' => 60]);

    $this->artisan('monitor:run')->assertSuccessful();
    expect(Incident::count())->toBe(0);

    $this->travel(2)->minutes();
    $this->artisan('monitor:run')->assertSuccessful();

    expect(Incident::count())->toBe(1)
        ->and($service->refresh()->current_state)->toBe(ServiceState::Down);
});

it('reports that nothing is due when every service was just checked', function () {
    fakeAllUp();

    Service::factory()->create([
        'interval_seconds' => 3600,
        'last_checked_at' => CarbonImmutable::now(),
    ]);

    $this->artisan('monitor:run')->expectsOutputToContain('No services are due.')->assertSuccessful();
});

it('opens an incident with a readable reason when an app answers 200 but is broken', function () {
    // The case the ticket exists for: the web server is fine, the app is not.
    Http::fake(['*broken.test*' => Http::response('Whoops, something went wrong', 200)]);

    $service = Service::factory()->create([
        'url' => 'https://broken.test',
        'expected_status_code' => 200,
        'expected_body' => 'Sign in',
        'last_checked_at' => null,
    ]);

    $this->artisan('monitor:run --force')->assertSuccessful();
    $this->artisan('monitor:run --force')->assertSuccessful();

    expect($service->checks()->pluck('state')->all())
        ->toBe([ServiceState::Down, ServiceState::Down])
        ->and(Incident::sole()->reason)->toBe('Responded without the expected content');
});

it('leaves a healthy app alone when its content is present', function () {
    Http::fake(['*app.test*' => Http::response('<h1>Sign in</h1>', 200)]);

    $service = Service::factory()->create([
        'url' => 'https://app.test',
        'expected_status_code' => 200,
        'expected_body' => 'Sign in',
        'last_checked_at' => null,
    ]);

    $this->artisan('monitor:run --force')->assertSuccessful();
    $this->artisan('monitor:run --force')->assertSuccessful();

    expect($service->refresh()->current_state)->toBe(ServiceState::Up)
        ->and(Incident::count())->toBe(0);
});
