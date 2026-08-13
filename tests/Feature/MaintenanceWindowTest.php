<?php

declare(strict_types=1);

use App\Actions\Monitoring\EvaluateIncident;
use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    Cache::flush();
    Notification::fake();
    $this->user = User::factory()->create(['wants_incident_mail' => true]);
    $this->service = Service::factory()->create(['name' => 'Zero']);
});

/**
 * Feed a service failing checks, evaluating after each, as the command does.
 *
 * @param  list<ServiceState>  $states
 */
function feed(Service $service, array $states): void
{
    $action = app(EvaluateIncident::class);
    $at = CarbonImmutable::now()->subMinutes(count($states));

    foreach ($states as $index => $state) {
        $check = Check::factory()->for($service)->create([
            'state' => $state,
            'ok' => $state !== ServiceState::Down,
            'status_code' => $state === ServiceState::Down ? 500 : 200,
            'checked_at' => $at->addMinutes($index),
        ]);

        $action->handle($service->fresh(), $check);
    }
}

function windowCovering(Service $service): MaintenanceWindow
{
    $window = MaintenanceWindow::factory()->create();
    $window->services()->attach($service);

    return $window;
}

it('opens no incident and sends no alert for a failure inside a declared window', function () {
    windowCovering($this->service);

    feed($this->service, [ServiceState::Up, ServiceState::Down, ServiceState::Down]);

    expect(Incident::count())->toBe(0);
    Notification::assertNothingSent();
});

it('still opens an incident for the same failure outside any window', function () {
    // The control case: suppression must be scoped to the window, not permanent.
    MaintenanceWindow::factory()->past()->create()->services()->attach($this->service);

    feed($this->service, [ServiceState::Up, ServiceState::Down, ServiceState::Down]);

    expect(Incident::count())->toBe(1);
});

it('does not resolve an incident that was already open when the window began', function () {
    // A window is not a recovery, the same rule the detected-deploy path follows.
    feed($this->service, [ServiceState::Up, ServiceState::Down, ServiceState::Down]);
    $incident = Incident::sole();

    windowCovering($this->service);
    feed($this->service, [ServiceState::Up, ServiceState::Up]);

    expect($incident->refresh()->resolved_at)->toBeNull();
});

it('only suppresses the services the window covers', function () {
    $other = Service::factory()->create(['name' => 'Tracker']);
    windowCovering($this->service);

    feed($this->service, [ServiceState::Up, ServiceState::Down, ServiceState::Down]);
    feed($other, [ServiceState::Up, ServiceState::Down, ServiceState::Down]);

    expect(Incident::count())->toBe(1)
        ->and(Incident::sole()->service_id)->toBe($other->id);
});

it('still records the checks honestly during a window', function () {
    // The data must not lie about what the service was doing; only the incident logic
    // stands down.
    windowCovering($this->service);

    feed($this->service, [ServiceState::Down, ServiceState::Down]);

    expect($this->service->checks()->where('state', ServiceState::Down)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Managing windows
|--------------------------------------------------------------------------
*/

it('schedules a window across several services', function () {
    $other = Service::factory()->create();

    $this->actingAs($this->user)
        ->post(route('maintenance.store'), [
            'description' => 'Database upgrade',
            'starts_at' => CarbonImmutable::now()->addDay()->toDateTimeString(),
            'ends_at' => CarbonImmutable::now()->addDay()->addHours(2)->toDateTimeString(),
            'service_ids' => [$this->service->id, $other->id],
        ])
        ->assertRedirect(route('maintenance.index'));

    $window = MaintenanceWindow::sole();

    expect($window->description)->toBe('Database upgrade')
        ->and($window->services)->toHaveCount(2);
});

it('rejects a window that ends before it starts', function () {
    $this->actingAs($this->user)
        ->post(route('maintenance.store'), [
            'description' => 'Backwards',
            'starts_at' => CarbonImmutable::now()->addHours(2)->toDateTimeString(),
            'ends_at' => CarbonImmutable::now()->addHour()->toDateTimeString(),
            'service_ids' => [$this->service->id],
        ])
        ->assertSessionHasErrors('ends_at');

    expect(MaintenanceWindow::count())->toBe(0);
});

it('requires at least one service', function () {
    $this->actingAs($this->user)
        ->post(route('maintenance.store'), [
            'description' => 'Nothing covered',
            'starts_at' => CarbonImmutable::now()->toDateTimeString(),
            'ends_at' => CarbonImmutable::now()->addHour()->toDateTimeString(),
            'service_ids' => [],
        ])
        ->assertSessionHasErrors('service_ids');
});

it('lists windows with their phase', function () {
    windowCovering($this->service);
    MaintenanceWindow::factory()->upcoming()->create()->services()->attach($this->service);
    MaintenanceWindow::factory()->past()->create()->services()->attach($this->service);

    $this->actingAs($this->user)
        ->get(route('maintenance.index'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $phases = collect($page->toArray()['props']['windows'])->pluck('phase')->all();

            expect($phases)->toContain('open')
                ->and($phases)->toContain('upcoming')
                ->and($phases)->toContain('past');
        });
});

it('removes a window', function () {
    $window = windowCovering($this->service);

    $this->actingAs($this->user)->delete(route('maintenance.destroy', $window));

    expect(MaintenanceWindow::count())->toBe(0);
});

it('keeps guests out of managing windows', function () {
    $window = windowCovering($this->service);

    $this->get(route('maintenance.index'))->assertRedirect(route('login'));
    $this->post(route('maintenance.store'), [])->assertRedirect(route('login'));
    $this->delete(route('maintenance.destroy', $window))->assertRedirect(route('login'));

    expect(MaintenanceWindow::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The public half
|--------------------------------------------------------------------------
*/

it('announces an open window on the public page', function () {
    $this->service->forceFill(['is_public' => true, 'last_checked_at' => CarbonImmutable::now()])->save();
    windowCovering($this->service);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('maintenance.open', 1)
            ->where('maintenance.open.0.description', 'Database upgrade'));
});

it('warns about an upcoming window before it starts', function () {
    // The thing the detected-deploy path in STAT-18 cannot do.
    $this->service->forceFill(['is_public' => true, 'last_checked_at' => CarbonImmutable::now()])->save();
    MaintenanceWindow::factory()->upcoming()->create(['description' => 'Planned reboot'])
        ->services()->attach($this->service);

    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('maintenance.open', 0)
            ->has('maintenance.upcoming', 1)
            ->where('maintenance.upcoming.0.description', 'Planned reboot'));
});

it('leaves a finished window off the public page', function () {
    $this->service->forceFill(['is_public' => true, 'last_checked_at' => CarbonImmutable::now()])->save();
    MaintenanceWindow::factory()->past()->create()->services()->attach($this->service);

    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('maintenance.open', 0)
            ->has('maintenance.upcoming', 0));
});
