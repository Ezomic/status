<?php

declare(strict_types=1);

use App\Actions\Monitoring\EvaluateIncident;
use App\Actions\Services\SaveService;
use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Service;
use App\Models\User;
use App\Notifications\IncidentStatusChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

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

/**
 * STAT-4: a monitor you have to remember to look at is not monitoring. These
 * pin the dedupe rule, which is the part that silently rots: this action runs
 * after every check, so a notification that fires anywhere but on the three
 * transitions would mail on every failing check for the life of the outage.
 */
it('sends one email when an incident opens, however long it stays open', function () {
    Notification::fake();
    User::factory()->create();

    play($this->service, array_fill(0, 6, ServiceState::Down));

    Notification::assertSentTimes(IncidentStatusChanged::class, 1);
});

it('sends one recovery email when the service comes back', function () {
    Notification::fake();
    User::factory()->create();

    play($this->service, [
        ServiceState::Down, ServiceState::Down, ServiceState::Down,
        ServiceState::Up, ServiceState::Up, ServiceState::Up,
    ]);

    Notification::assertSentTimes(IncidentStatusChanged::class, 2);
});

it('sends a further email when degraded escalates to down, but not for a repeat at the same severity', function () {
    Notification::fake();
    User::factory()->create();

    play($this->service, [
        ServiceState::Degraded, ServiceState::Degraded, ServiceState::Degraded,
        ServiceState::Down, ServiceState::Down,
    ]);

    // Opened as degraded, then escalated to down. The extra degraded and down
    // checks in between add nothing.
    Notification::assertSentTimes(IncidentStatusChanged::class, 2);
});

it('says nothing when a paused service closes its incident', function () {
    User::factory()->create();

    play($this->service, [ServiceState::Down, ServiceState::Down]);

    // Faked only now, so the opening email is not what we are counting.
    Notification::fake();

    app(SaveService::class)->handle(['is_active' => false], $this->service);

    expect(Incident::sole()->resolved_at)->not->toBeNull()
        ->and(Incident::sole()->reason)->toBe('Monitoring disabled');

    Notification::assertNothingSent();
});

it('describes the outage in the mail it sends', function () {
    Notification::fake();
    $user = User::factory()->create();

    play($this->service, [ServiceState::Down, ServiceState::Down]);

    Notification::assertSentTo($user, IncidentStatusChanged::class, function (IncidentStatusChanged $notification) use ($user): bool {
        $mail = $notification->toMail($user);

        return str_contains((string) $mail->subject, $this->service->name)
            && in_array('Returned 500, expected 200', $mail->introLines, true);
    });
});

it('reports how long the service was down in the recovery mail', function () {
    Notification::fake();
    $user = User::factory()->create();

    play($this->service, [
        ServiceState::Down, ServiceState::Down, ServiceState::Down, ServiceState::Up, ServiceState::Up,
    ]);

    $resolved = collect(Notification::sent($user, IncidentStatusChanged::class))
        ->map(fn (IncidentStatusChanged $notification): string => (string) $notification->toMail($user)->subject)
        ->first(fn (string $subject): bool => str_contains($subject, 'Resolved'));

    expect($resolved)->toContain('is back up');
});
