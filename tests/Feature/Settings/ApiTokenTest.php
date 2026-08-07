<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    Cache::flush();
    $this->user = User::factory()->create();
});

it('lists the acting user\'s tokens and nobody else\'s', function () {
    $this->user->createToken('Mine');
    User::factory()->create()->createToken('Someone else\'s');

    $this->actingAs($this->user)
        ->get(route('tokens.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Tokens')
            ->has('tokens', 1)
            ->where('tokens.0.name', 'Mine'));
});

it('reveals the plaintext exactly once', function () {
    $this->actingAs($this->user)
        ->post(route('tokens.store'), ['name' => 'ID portal'])
        ->assertRedirect(route('tokens.index'));

    // First render after creation: the plaintext is there to be copied.
    $plain = null;

    $this->actingAs($this->user)
        ->get(route('tokens.index'))
        ->assertInertia(function (AssertableInertia $page) use (&$plain) {
            $created = $page->toArray()['props']['createdToken'];

            expect($created['name'])->toBe('ID portal')
                ->and($created['plain'])->toBeString()
                ->and($created['plain'])->not->toBeEmpty();

            $plain = $created['plain'];
        });

    // Reload: gone, and not recoverable from anywhere.
    $this->actingAs($this->user)
        ->get(route('tokens.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('createdToken', null)
            ->has('tokens', 1));

    expect($plain)->not->toBeNull();
});

it('never persists the plaintext', function () {
    $this->actingAs($this->user)->post(route('tokens.store'), ['name' => 'ID portal']);

    $plain = session('createdToken')['plain'];
    $stored = PersonalAccessToken::sole();

    // Sanctum stores a hash of the part after the pipe, so neither the whole token nor
    // its secret half may appear in the row.
    [$id, $secret] = explode('|', $plain, 2);

    expect($stored->token)->not->toBe($plain)
        ->and($stored->token)->not->toBe($secret)
        ->and($stored->token)->toBe(hash('sha256', $secret))
        ->and($id)->toBe((string) $stored->id);
});

it('requires a name', function () {
    $this->actingAs($this->user)
        ->post(route('tokens.store'), ['name' => ''])
        ->assertSessionHasErrors('name');

    expect(PersonalAccessToken::count())->toBe(0);
});

it('revokes a token', function () {
    $token = $this->user->createToken('Doomed')->accessToken;

    $this->actingAs($this->user)
        ->delete(route('tokens.destroy', $token->id))
        ->assertRedirect(route('tokens.index'));

    expect(PersonalAccessToken::count())->toBe(0);
});

it('cannot revoke a token belonging to someone else', function () {
    $theirs = User::factory()->create()->createToken('Theirs')->accessToken;

    $this->actingAs($this->user)
        ->delete(route('tokens.destroy', $theirs->id))
        ->assertNotFound();

    expect(PersonalAccessToken::count())->toBe(1);
});

it('keeps guests out entirely', function () {
    $token = $this->user->createToken('Mine')->accessToken;

    $this->get(route('tokens.index'))->assertRedirect(route('login'));
    $this->post(route('tokens.store'), ['name' => 'x'])->assertRedirect(route('login'));
    $this->delete(route('tokens.destroy', $token->id))->assertRedirect(route('login'));

    expect(PersonalAccessToken::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The tokens have to actually authenticate something
|--------------------------------------------------------------------------
*/

it('authenticates the status endpoint with a personal access token', function () {
    config()->set('services.status_endpoint.token', 'shared-token');
    $plain = $this->user->createToken('ID portal')->plainTextToken;

    $this->getJson(route('api.status'), ['Authorization' => "Bearer {$plain}"])
        ->assertOk()
        ->assertJsonStructure(['services']);
});

it('still accepts the shared config token so ID-13 keeps working', function () {
    config()->set('services.status_endpoint.token', 'shared-token');

    $this->getJson(route('api.status'), ['Authorization' => 'Bearer shared-token'])
        ->assertOk();
});

it('rejects a revoked token', function () {
    config()->set('services.status_endpoint.token', 'shared-token');
    $created = $this->user->createToken('ID portal');
    $plain = $created->plainTextToken;

    $created->accessToken->delete();

    $this->getJson(route('api.status'), ['Authorization' => "Bearer {$plain}"])
        ->assertUnauthorized();
});

it('rejects a made-up token', function () {
    config()->set('services.status_endpoint.token', 'shared-token');

    $this->getJson(route('api.status'), ['Authorization' => 'Bearer 1|totally-invented'])
        ->assertUnauthorized();
});

it('records when a token was last used', function () {
    config()->set('services.status_endpoint.token', 'shared-token');
    $created = $this->user->createToken('ID portal');

    expect($created->accessToken->last_used_at)->toBeNull();

    CarbonImmutable::setTestNow('2026-08-07 12:00:00');
    $this->getJson(route('api.status'), ['Authorization' => "Bearer {$created->plainTextToken}"])->assertOk();
    CarbonImmutable::setTestNow();

    expect(PersonalAccessToken::sole()->last_used_at?->toDateTimeString())
        ->toBe('2026-08-07 12:00:00');
});
