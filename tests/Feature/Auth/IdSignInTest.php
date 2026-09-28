<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Thijssensoftware\IdClient\Exceptions\AccessDeniedException;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\mock;
use function Pest\Laravel\post;

/**
 * Status signs in through id-client since STAT-53, and keeps what its own OAuth
 * controller did: ID decides who may sign in, a user is matched on their ID subject and
 * then on email, and anyone else ID lets through is provisioned.
 */
beforeEach(function () {
    config([
        'services.thijssensoftware.base_url' => 'https://id.test',
        'services.thijssensoftware.client_id' => 'client-123',
        'services.thijssensoftware.client_secret' => 'secret-456',
    ]);
});

it('sends a guest to ID with the callback that receives back-channel logouts', function () {
    $location = (string) get(route('login'))->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://id.test/oauth/authorize?')
        ->and($query['client_id'] ?? null)->toBe('client-123')
        ->and($query['redirect_uri'] ?? null)->toBe(url('/auth/sso/callback'));
});

it('provisions a user the first time ID signs them in', function () {
    signInThroughId('42', 'ada@example.com', 'Ada Lovelace')->assertRedirect(route('dashboard'));

    $user = User::query()->where('idp_id', '42')->sole();

    expect($user->email)->toBe('ada@example.com')
        ->and($user->name)->toBe('Ada Lovelace');
    assertAuthenticatedAs($user);
});

it('matches a returning user on their ID subject and takes their new email', function () {
    $user = User::factory()->create(['idp_id' => '42', 'email' => 'old@example.com']);

    signInThroughId('42', 'new@example.com', 'Renamed')->assertRedirect(route('dashboard'));

    expect(User::count())->toBe(1)
        ->and($user->fresh()?->email)->toBe('new@example.com')
        ->and($user->fresh()?->name)->toBe('Renamed');
    assertAuthenticatedAs($user);
});

it('links an account that predates SSO to its ID identity by email', function () {
    $user = User::factory()->create(['idp_id' => null, 'email' => 'admin@example.com']);

    signInThroughId('99', 'admin@example.com', 'Admin')->assertRedirect(route('dashboard'));

    expect(User::count())->toBe(1)
        ->and($user->fresh()?->idp_id)->toBe('99');
    assertAuthenticatedAs($user);
});

it('turns away someone ID has not given access to Status', function () {
    mock(Socialite::class)->shouldReceive('driver->user')
        ->andThrow(new AccessDeniedException('You do not have access to this application.'));

    get(route('sso.callback'))->assertForbidden();

    assertGuest();
    expect(User::count())->toBe(0);
});

it('signs out of Status and stops the remember cookie with it', function () {
    [, $recaller] = signInThroughIdRemembered();

    post(route('logout'))->assertRedirect('/');
    assertGuest();

    returnWithOnlyTheRememberCookie($recaller)->assertRedirect(route('login'));
    assertGuest();
});

it('reads the ID variables production already sets', function () {
    $env = ['ID_BASE_URL' => 'https://id.example/', 'ID_CLIENT_ID' => 'env-client', 'ID_CLIENT_SECRET' => 'env-secret'];

    foreach ($env as $name => $value) {
        $_SERVER[$name] = $value;
    }

    try {
        $services = require config_path('services.php');
    } finally {
        foreach (array_keys($env) as $name) {
            unset($_SERVER[$name]);
        }
    }

    expect($services['thijssensoftware'])->toBe([
        'base_url' => 'https://id.example',
        'client_id' => 'env-client',
        'client_secret' => 'env-secret',
    ])->and(config('services.thijssensoftware.redirect'))->toBe('/auth/sso/callback');
});
