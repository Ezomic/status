<?php

declare(strict_types=1);

use App\Enums\ServiceState;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    Cache::flush();
    $this->user = User::factory()->create();
});

function grouped(?string $group, string $name): Service
{
    return Service::factory()->create([
        'name' => $name,
        'group' => $group,
        'is_active' => true,
        'is_public' => true,
        'current_state' => ServiceState::Up,
        'interval_seconds' => 60,
        'last_checked_at' => CarbonImmutable::now()->subSeconds(20),
    ]);
}

it('orders grouped services before ungrouped ones', function () {
    // Ungrouped services fall to the end rather than disappearing.
    grouped(null, 'Loose one');
    grouped('Core', 'Identity');
    grouped('Apps', 'Billr');

    $this->get(route('home'))
        ->assertInertia(function (AssertableInertia $page) {
            $names = collect($page->toArray()['props']['services'])->pluck('name')->all();

            // Apps before Core alphabetically, ungrouped last.
            expect($names)->toBe(['Billr', 'Identity', 'Loose one']);
        });
});

it('sorts alphabetically inside a group', function () {
    grouped('Core', 'Zero');
    grouped('Core', 'Identity');

    $this->get(route('home'))
        ->assertInertia(function (AssertableInertia $page) {
            expect(collect($page->toArray()['props']['services'])->pluck('name')->all())
                ->toBe(['Identity', 'Zero']);
        });
});

it('carries the group on the public payload', function () {
    grouped('Core', 'Identity');

    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('services.0.group', 'Core'));
});

it('renders the group as a heading on the public page', function () {
    grouped('Core', 'Identity');

    expect($this->get(route('home'))->getContent())->toContain('Core');
});

it('leaves the verdict estate-wide rather than per group', function () {
    // Grouping is presentation. It must not change how severity is judged.
    grouped('Core', 'Fine');
    $broken = grouped('Apps', 'Broken');
    $broken->forceFill(['current_state' => ServiceState::Down])->save();

    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('verdict.tone', 'down')
            ->where('verdict.headline', '1 of 2 services is down'));
});

it('accepts and clears a group through the form', function () {
    $this->actingAs($this->user)
        ->post(route('services.store'), validPayload(['group' => 'Core']))
        ->assertSessionHasNoErrors();

    $service = Service::sole();
    expect($service->group)->toBe('Core');

    $this->actingAs($this->user)
        ->put(route('services.update', $service), validPayload(['group' => '']))
        ->assertSessionHasNoErrors();

    expect($service->refresh()->group)->toBeNull();
});

it('rejects a group name longer than the column', function () {
    $this->actingAs($this->user)
        ->post(route('services.store'), validPayload(['group' => str_repeat('a', 256)]))
        ->assertSessionHasErrors('group');
});

it('keeps the leak rule while carrying group names', function () {
    // Group names are reader-facing, so they get the same treatment as service names:
    // present, while everything internal stays absent (STAT-5).
    $service = grouped('Core', 'Portal');
    $service->forceFill([
        'url' => 'https://secret-host.internal/health',
        'last_response_time_ms' => 1337,
    ])->save();

    $body = $this->get(route('home'))->getContent();

    expect($body)->toContain('Core')
        ->and($body)->not->toContain('secret-host.internal')
        ->and($body)->not->toContain('1337');
});
