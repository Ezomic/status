<?php

declare(strict_types=1);

use App\Actions\Monitoring\EvaluateIncident;
use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Service;
use Carbon\CarbonImmutable;

/**
 * Feed a service a sequence of check states, evaluating after each one, exactly as the
 * command does. Checks are one minute apart so durations read back sensibly.
 *
 * @param  list<ServiceState>  $states
 */
function play(Service $service, array $states): Service
{
    $action = app(EvaluateIncident::class);
    $at = CarbonImmutable::now()->subMinutes(count($states));

    foreach ($states as $index => $state) {
        $check = Check::factory()->for($service)->create([
            'state' => $state,
            'ok' => $state !== ServiceState::Down,
            'status_code' => $state === ServiceState::Down ? 500 : 200,
            'response_time_ms' => $state === ServiceState::Degraded ? 2400 : 120,
            'checked_at' => $at->addMinutes($index),
        ]);

        $action->handle($service, $check);
    }

    return $service->refresh();
}

beforeEach(function () {
    $this->service = Service::factory()->create(['degraded_threshold_ms' => 1000]);
});

it('does not open an incident on a single failure', function () {
    play($this->service, [ServiceState::Up, ServiceState::Down]);

    expect(Incident::count())->toBe(0);
});

it('does not open an incident on the very first check', function () {
    play($this->service, [ServiceState::Down]);

    expect(Incident::count())->toBe(0);
});

it('opens an incident after two consecutive failures', function () {
    play($this->service, [ServiceState::Up, ServiceState::Down, ServiceState::Down]);

    expect(Incident::count())->toBe(1)
        ->and($this->service->openIncident()->severity)->toBe(ServiceState::Down);
});

it('backdates started_at to the first failing check', function () {
    play($this->service, [ServiceState::Up, ServiceState::Down, ServiceState::Down]);

    $firstFailure = $this->service->checks()->where('state', ServiceState::Down)->orderBy('id')->first();

    expect($this->service->openIncident()->started_at->toIso8601String())
        ->toBe($firstFailure->checked_at->toIso8601String());
});

it('does not open a second incident while one is already open', function () {
    play($this->service, [ServiceState::Down, ServiceState::Down, ServiceState::Down, ServiceState::Down]);

    expect(Incident::count())->toBe(1);
});

it('does not close an incident on a single passing check', function () {
    play($this->service, [ServiceState::Down, ServiceState::Down, ServiceState::Up]);

    expect($this->service->openIncident())->not->toBeNull();
});

it('closes an incident after two consecutive passes', function () {
    play($this->service, [ServiceState::Down, ServiceState::Down, ServiceState::Up, ServiceState::Up]);

    expect($this->service->openIncident())->toBeNull()
        ->and(Incident::sole()->resolved_at)->not->toBeNull();
});

it('sets resolved_at to the first passing check', function () {
    play($this->service, [ServiceState::Down, ServiceState::Down, ServiceState::Up, ServiceState::Up]);

    $firstPass = $this->service->checks()->where('state', ServiceState::Up)->orderBy('id')->first();

    expect(Incident::sole()->resolved_at->toIso8601String())
        ->toBe($firstPass->checked_at->toIso8601String());
});

it('never opens an incident for a service that flaps', function () {
    play($this->service, [
        ServiceState::Down, ServiceState::Up,
        ServiceState::Down, ServiceState::Up,
        ServiceState::Down, ServiceState::Up,
    ]);

    expect(Incident::count())->toBe(0);
});

it('keeps an incident open through a false recovery', function () {
    play($this->service, [
        ServiceState::Down, ServiceState::Down,
        ServiceState::Up,
        ServiceState::Down,
    ]);

    expect($this->service->openIncident())->not->toBeNull();
});

it('opens a degraded incident for two consecutive slow checks', function () {
    play($this->service, [ServiceState::Degraded, ServiceState::Degraded]);

    expect($this->service->openIncident()->severity)->toBe(ServiceState::Degraded);
});

it('escalates a degraded incident on the first confirming down check', function () {
    play($this->service, [ServiceState::Degraded, ServiceState::Degraded, ServiceState::Down]);

    expect(Incident::count())->toBe(1)
        ->and($this->service->openIncident()->severity)->toBe(ServiceState::Down);
});

it('does not de-escalate a down incident when the service recovers to degraded', function () {
    play($this->service, [
        ServiceState::Down, ServiceState::Down,
        ServiceState::Degraded, ServiceState::Degraded,
    ]);

    expect($this->service->openIncident()->severity)->toBe(ServiceState::Down);
});

it('opens at the worst severity in the confirming window', function () {
    play($this->service, [ServiceState::Degraded, ServiceState::Down]);

    expect($this->service->openIncident()->severity)->toBe(ServiceState::Down);
});

it('opens a new incident after a previous one resolved', function () {
    play($this->service, [
        ServiceState::Down, ServiceState::Down,
        ServiceState::Up, ServiceState::Up,
        ServiceState::Down, ServiceState::Down,
    ]);

    expect(Incident::count())->toBe(2)
        ->and(Incident::whereNull('resolved_at')->count())->toBe(1);
});

it('is idempotent when handed the same check twice', function () {
    play($this->service, [ServiceState::Down, ServiceState::Down]);

    $latest = $this->service->checks()->orderByDesc('id')->first();
    app(EvaluateIncident::class)->handle($this->service, $latest);

    expect(Incident::count())->toBe(1);
});

it('resolves ties on checked_at by check id', function () {
    $at = CarbonImmutable::now();

    foreach ([ServiceState::Down, ServiceState::Down] as $state) {
        $check = Check::factory()->for($this->service)->create([
            'state' => $state,
            'ok' => false,
            'status_code' => 500,
            'checked_at' => $at,
        ]);

        app(EvaluateIncident::class)->handle($this->service, $check);
    }

    expect(Incident::count())->toBe(1);
});

it('records a readable reason', function () {
    play($this->service, [ServiceState::Down, ServiceState::Down]);

    expect(Incident::sole()->reason)->toBe('Returned 500, expected 200');
});

it('records a threshold reason for a degraded incident', function () {
    play($this->service, [ServiceState::Degraded, ServiceState::Degraded]);

    expect(Incident::sole()->reason)->toBe('Responded in 2,400ms, over the 1,000ms threshold');
});
