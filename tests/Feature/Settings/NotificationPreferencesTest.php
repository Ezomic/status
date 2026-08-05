<?php

declare(strict_types=1);

use App\Actions\Monitoring\EvaluateIncident;
use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Service;
use App\Models\User;
use App\Notifications\IncidentStatusChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

/**
 * Drive a service through a sequence of states, evaluating after each, as the command does.
 *
 * @param  list<ServiceState>  $states
 */
function drive(Service $service, array $states): void
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

        $action->handle($service, $check);
    }
}

it('defaults a new user to no incident mail', function () {
    // Users appear automatically at first SSO login, so off is the only safe default.
    // Read back from the database: create() does not hydrate column defaults it did
    // not itself write.
    expect(User::factory()->create()->fresh()->wants_incident_mail)->toBeFalse();
});

it('sends nothing to a user who has not opted in', function () {
    Notification::fake();
    User::factory()->create(['wants_incident_mail' => false]);

    drive(Service::factory()->create(), [ServiceState::Up, ServiceState::Down, ServiceState::Down]);

    Notification::assertNothingSent();
});

it('sends all three transitions to an opted-in user', function () {
    Notification::fake();
    $wants = User::factory()->create(['wants_incident_mail' => true]);

    drive(Service::factory()->create(['degraded_threshold_ms' => 1000]), [
        ServiceState::Up,
        ServiceState::Degraded, ServiceState::Degraded,   // opened
        ServiceState::Down,                               // escalated
        ServiceState::Up, ServiceState::Up,               // resolved
    ]);

    Notification::assertSentToTimes($wants, IncidentStatusChanged::class, 3);
});

it('mails only the users who opted in', function () {
    Notification::fake();
    $wants = User::factory()->create(['wants_incident_mail' => true]);
    $doesNot = User::factory()->create(['wants_incident_mail' => false]);

    drive(Service::factory()->create(), [ServiceState::Up, ServiceState::Down, ServiceState::Down]);

    Notification::assertSentTo($wants, IncidentStatusChanged::class);
    Notification::assertNotSentTo($doesNot, IncidentStatusChanged::class);
});

it('shows the current preference on the settings page', function () {
    $user = User::factory()->create(['wants_incident_mail' => true]);

    $this->actingAs($user)
        ->get(route('notifications.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Notifications')
            ->where('auth.user.wants_incident_mail', true));
});

it('lets a user opt in and out', function () {
    $user = User::factory()->create(['wants_incident_mail' => false]);

    $this->actingAs($user)
        ->patch(route('notifications.update'), ['wants_incident_mail' => true])
        ->assertRedirect(route('notifications.edit'))
        ->assertSessionHasNoErrors();

    expect($user->refresh()->wants_incident_mail)->toBeTrue();

    $this->actingAs($user)
        ->patch(route('notifications.update'), ['wants_incident_mail' => false])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->wants_incident_mail)->toBeFalse();
});

it('only ever changes the acting user\'s own preference', function () {
    $actor = User::factory()->create(['wants_incident_mail' => false]);
    $other = User::factory()->create(['wants_incident_mail' => false]);

    $this->actingAs($actor)
        ->patch(route('notifications.update'), ['wants_incident_mail' => true])
        ->assertSessionHasNoErrors();

    expect($actor->refresh()->wants_incident_mail)->toBeTrue()
        ->and($other->refresh()->wants_incident_mail)->toBeFalse();
});

it('rejects a non-boolean preference', function () {
    $this->actingAs(User::factory()->create())
        ->patch(route('notifications.update'), ['wants_incident_mail' => 'maybe'])
        ->assertSessionHasErrors('wants_incident_mail');
});

it('keeps guests out of notification settings', function () {
    $this->get(route('notifications.edit'))->assertRedirect(route('login'));
    $this->patch(route('notifications.update'), ['wants_incident_mail' => true])
        ->assertRedirect(route('login'));
});
