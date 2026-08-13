<?php

declare(strict_types=1);

use App\Enums\ServiceState;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    Cache::flush();
    $this->user = User::factory()->create(['name' => 'Robbin']);
    $this->service = Service::factory()->create([
        'name' => 'Zero',
        'is_active' => true,
        'is_public' => true,
        'interval_seconds' => 60,
        'last_checked_at' => CarbonImmutable::now()->subSeconds(20),
        'current_state' => ServiceState::Down,
    ]);
    $this->incident = Incident::factory()->for($this->service)->create([
        'reason' => 'cURL error 6: Could not resolve host: secret-host.internal',
        'severity' => ServiceState::Down,
    ]);
});

it('shows an incident with its updates', function () {
    IncidentUpdate::factory()->for($this->incident)->create(['body' => 'Looking into it']);

    $this->actingAs($this->user)
        ->get(route('incidents.show', $this->incident))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('incidents/Show')
            ->where('incident.service', 'Zero')
            ->has('updates', 1)
            ->where('updates.0.body', 'Looking into it')
            ->where('updates.0.is_published', false));
});

it('posts an update and records who wrote it', function () {
    $this->actingAs($this->user)
        ->post(route('incident-updates.store', $this->incident), [
            'body' => 'Database is refusing connections.',
            'is_published' => true,
        ])
        ->assertRedirect(route('incidents.show', $this->incident));

    $update = IncidentUpdate::sole();

    expect($update->body)->toBe('Database is refusing connections.')
        ->and($update->is_published)->toBeTrue()
        ->and($update->user_id)->toBe($this->user->id);
});

it('requires a body', function () {
    $this->actingAs($this->user)
        ->post(route('incident-updates.store', $this->incident), ['body' => '', 'is_published' => false])
        ->assertSessionHasErrors('body');

    expect(IncidentUpdate::count())->toBe(0);
});

it('edits and unpublishes an update', function () {
    $update = IncidentUpdate::factory()->for($this->incident)->published()->create();

    $this->actingAs($this->user)
        ->put(route('incident-updates.update', $update), ['body' => 'Corrected wording', 'is_published' => false])
        ->assertRedirect(route('incidents.show', $this->incident));

    expect($update->refresh()->body)->toBe('Corrected wording')
        ->and($update->is_published)->toBeFalse();
});

it('deletes an update', function () {
    $update = IncidentUpdate::factory()->for($this->incident)->create();

    $this->actingAs($this->user)->delete(route('incident-updates.destroy', $update));

    expect(IncidentUpdate::count())->toBe(0);
});

it('acknowledges and unacknowledges an incident', function () {
    $this->actingAs($this->user)->post(route('incidents.acknowledge', $this->incident));

    expect($this->incident->refresh()->acknowledged_at)->not->toBeNull()
        ->and($this->incident->acknowledged_by_id)->toBe($this->user->id);

    // Acknowledging is not resolving: looking at something is not fixing it.
    expect($this->incident->resolved_at)->toBeNull();

    $this->actingAs($this->user)->post(route('incidents.acknowledge', $this->incident));

    expect($this->incident->refresh()->acknowledged_at)->toBeNull()
        ->and($this->incident->acknowledged_by_id)->toBeNull();
});

it('keeps an update when its author is deleted', function () {
    // The record of what happened during an outage outlives whoever wrote it.
    $update = IncidentUpdate::factory()->for($this->incident)->create(['user_id' => $this->user->id]);

    $this->user->delete();

    expect($update->refresh()->user_id)->toBeNull()
        ->and(IncidentUpdate::count())->toBe(1);
});

it('keeps guests out of everything', function () {
    $update = IncidentUpdate::factory()->for($this->incident)->create();

    $this->get(route('incidents.show', $this->incident))->assertRedirect(route('login'));
    $this->post(route('incidents.acknowledge', $this->incident))->assertRedirect(route('login'));
    $this->post(route('incident-updates.store', $this->incident), ['body' => 'x', 'is_published' => true])
        ->assertRedirect(route('login'));
    $this->put(route('incident-updates.update', $update), ['body' => 'x', 'is_published' => true])
        ->assertRedirect(route('login'));
    $this->delete(route('incident-updates.destroy', $update))->assertRedirect(route('login'));

    expect(IncidentUpdate::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The public half, and the leak rule it must not break
|--------------------------------------------------------------------------
*/

it('publishes only published updates, and never the incident reason', function () {
    IncidentUpdate::factory()->for($this->incident)->published()->create(['body' => 'Investigating the outage']);
    IncidentUpdate::factory()->for($this->incident)->create(['body' => 'Internal note, do not publish']);

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain('Investigating the outage')
        ->and($body)->not->toContain('Internal note, do not publish');

    // The whole reason STAT-5 exists: reasons carry internal hostnames.
    foreach (['secret-host.internal', 'cURL', 'Could not resolve'] as $secret) {
        expect($body)->not->toContain($secret);
    }
});

it('drops updates off the public page once the incident resolves', function () {
    // The public page reports the current state, not a history.
    IncidentUpdate::factory()->for($this->incident)->published()->create(['body' => 'Investigating the outage']);

    $this->incident->forceFill(['resolved_at' => CarbonImmutable::now()])->save();
    Cache::flush();

    expect($this->get(route('home'))->getContent())->not->toContain('Investigating the outage');
});

it('exposes only body and timestamp per published update', function () {
    IncidentUpdate::factory()->for($this->incident)->published()->create();

    $this->get(route('home'))
        ->assertInertia(function (AssertableInertia $page) {
            $service = collect($page->toArray()['props']['services'])
                ->firstWhere('name', 'Zero');

            expect(array_keys($service['updates'][0]))->toBe(['body', 'at']);
        });
});
