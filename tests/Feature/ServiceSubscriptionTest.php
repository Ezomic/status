<?php

declare(strict_types=1);

use App\Actions\Monitoring\EvaluateCertificateAlert;
use App\Actions\Monitoring\EvaluateIncident;
use App\Models\Check;
use App\Models\Service;
use App\Models\User;
use App\Notifications\CertificateExpiring;
use App\Notifications\IncidentStatusChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

function failTwice(Service $service): Check
{
    Check::factory()->for($service)->down()->create(['checked_at' => CarbonImmutable::now()->subMinute()]);

    return Check::factory()->for($service)->down()->create(['checked_at' => CarbonImmutable::now()]);
}

it('mails everyone with the switch on when nobody has narrowed anything', function () {
    Notification::fake();

    $service = Service::factory()->create();
    $subscriber = User::factory()->create(['wants_incident_mail' => true]);
    $optedOut = User::factory()->create(['wants_incident_mail' => false]);

    app(EvaluateIncident::class)->handle($service, failTwice($service));

    Notification::assertSentTo($subscriber, IncidentStatusChanged::class);
    Notification::assertNotSentTo($optedOut, IncidentStatusChanged::class);
});

it('mails only the users subscribed to the failing service', function () {
    Notification::fake();

    $failing = Service::factory()->create(['name' => 'Zero']);
    $other = Service::factory()->create(['name' => 'Billr']);

    $watching = User::factory()->create(['wants_incident_mail' => true]);
    $watching->subscribedServices()->attach($failing);

    $watchingSomethingElse = User::factory()->create(['wants_incident_mail' => true]);
    $watchingSomethingElse->subscribedServices()->attach($other);

    app(EvaluateIncident::class)->handle($failing, failTwice($failing));

    Notification::assertSentTo($watching, IncidentStatusChanged::class);
    Notification::assertNotSentTo($watchingSomethingElse, IncidentStatusChanged::class);
});

it('keeps the master switch above the subscription', function () {
    // Subscribed to the very service that fails, but mail is off. The switch wins:
    // a subscription is a filter, not a second way to opt in.
    Notification::fake();

    $service = Service::factory()->create();
    $user = User::factory()->create(['wants_incident_mail' => false]);
    $user->subscribedServices()->attach($service);

    app(EvaluateIncident::class)->handle($service, failTwice($service));

    Notification::assertNothingSent();
});

it('includes a service added after someone narrowed their choices only for unnarrowed users', function () {
    Notification::fake();

    $existing = Service::factory()->create(['name' => 'Zero']);

    $narrowed = User::factory()->create(['wants_incident_mail' => true]);
    $narrowed->subscribedServices()->attach($existing);

    $unnarrowed = User::factory()->create(['wants_incident_mail' => true]);

    $new = Service::factory()->create(['name' => 'Latch']);

    app(EvaluateIncident::class)->handle($new, failTwice($new));

    Notification::assertSentTo($unnarrowed, IncidentStatusChanged::class);
    Notification::assertNotSentTo($narrowed, IncidentStatusChanged::class);
});

it('narrows certificate alerts the same way', function () {
    Notification::fake();

    $expiring = Service::factory()->create([
        'url' => 'https://a.test',
        'certificate_expires_at' => CarbonImmutable::now()->addDays(3),
    ]);
    $other = Service::factory()->create(['url' => 'https://b.test']);

    $watching = User::factory()->create(['wants_incident_mail' => true]);
    $watching->subscribedServices()->attach($expiring);

    $watchingSomethingElse = User::factory()->create(['wants_incident_mail' => true]);
    $watchingSomethingElse->subscribedServices()->attach($other);

    app(EvaluateCertificateAlert::class)->handle($expiring, CarbonImmutable::now());

    Notification::assertSentTo($watching, CertificateExpiring::class);
    Notification::assertNotSentTo($watchingSomethingElse, CertificateExpiring::class);
});

it('opens the settings form on every service when nothing is subscribed', function () {
    Service::factory()->create(['name' => 'Zero', 'group' => 'Apps']);

    $this->actingAs(User::factory()->create())
        ->get(route('notifications.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Notifications')
            ->where('allServices', true)
            ->has('subscribedServiceIds', 0)
            ->where('services.0.name', 'Zero')
            ->where('services.0.group', 'Apps'));
});

it('stores a narrowed selection', function () {
    $user = User::factory()->create(['wants_incident_mail' => true]);
    $wanted = Service::factory()->create();
    Service::factory()->create();

    $this->actingAs($user)
        ->patch(route('notifications.update'), [
            'wants_incident_mail' => true,
            'all_services' => false,
            'services' => [$wanted->id],
        ])
        ->assertSessionHasNoErrors();

    expect($user->subscribedServices()->pluck('services.id')->all())->toBe([$wanted->id]);
});

it('stores every service as no rows at all', function () {
    $user = User::factory()->create(['wants_incident_mail' => true]);
    $service = Service::factory()->create();
    $user->subscribedServices()->attach($service);

    $this->actingAs($user)
        ->patch(route('notifications.update'), [
            'wants_incident_mail' => true,
            'all_services' => true,
            'services' => [$service->id],
        ])
        ->assertSessionHasNoErrors();

    // A row per service would go stale the moment a service is added, so "all" is the
    // absence of rows even when the form happens to post the current selection.
    expect($user->subscribedServices()->count())->toBe(0);
});

it('rejects a narrowed selection that names nothing', function () {
    $user = User::factory()->create(['wants_incident_mail' => true]);
    Service::factory()->create();

    $this->actingAs($user)
        ->patch(route('notifications.update'), [
            'wants_incident_mail' => true,
            'all_services' => false,
            'services' => [],
        ])
        ->assertSessionHasErrors('services');
});

it('rejects a service that does not exist', function () {
    $this->actingAs(User::factory()->create(['wants_incident_mail' => true]))
        ->patch(route('notifications.update'), [
            'wants_incident_mail' => true,
            'all_services' => false,
            'services' => [9999],
        ])
        ->assertSessionHasErrors('services.0');
});

it('drops subscriptions when a service is deleted', function () {
    $user = User::factory()->create(['wants_incident_mail' => true]);
    $service = Service::factory()->create();
    $other = Service::factory()->create();
    $user->subscribedServices()->attach([$service->id, $other->id]);

    $service->delete();

    expect($user->subscribedServices()->pluck('services.id')->all())->toBe([$other->id]);
});

it('widens someone back to everything if their last subscribed service is deleted', function () {
    // The accepted cost of encoding "all" as no rows: delete the only service someone
    // narrowed to and they read as unnarrowed again. Pinned rather than fixed, because
    // the alternative is a column to distinguish "all" from "none" and the alternative
    // to that state is switching mail off, which already exists.
    Notification::fake();

    $user = User::factory()->create(['wants_incident_mail' => true]);
    $onlyOne = Service::factory()->create();
    $user->subscribedServices()->attach($onlyOne);

    $onlyOne->delete();

    $another = Service::factory()->create();
    app(EvaluateIncident::class)->handle($another, failTwice($another));

    Notification::assertSentTo($user, IncidentStatusChanged::class);
});

it('keeps one user out of another user subscriptions', function () {
    $mine = User::factory()->create(['wants_incident_mail' => true]);
    $theirs = User::factory()->create(['wants_incident_mail' => true]);
    $service = Service::factory()->create();
    $theirs->subscribedServices()->attach($service);

    $this->actingAs($mine)
        ->patch(route('notifications.update'), [
            'wants_incident_mail' => true,
            'all_services' => false,
            'services' => [$service->id],
        ])
        ->assertSessionHasNoErrors();

    expect($theirs->subscribedServices()->count())->toBe(1)
        ->and($mine->subscribedServices()->count())->toBe(1);
});
