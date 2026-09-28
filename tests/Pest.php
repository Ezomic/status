<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\mock;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // A request with no matching fake otherwise escapes to the real network,
        // which silently turns a broken fake into a passing-looking test.
        Http::preventStrayRequests();
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A complete, valid service payload. Shared because several suites post services and
 * every new required field would otherwise have to be added to each copy by hand.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Tracker',
        'url' => 'https://tracker.thijssensoftware.nl',
        'http_method' => 'GET',
        'expected_status_code' => 200,
        'interval_seconds' => 60,
        'timeout_seconds' => 5,
        'degraded_threshold_ms' => 1000,
        'is_active' => true,
    ], $overrides);
}

/**
 * Signs in through a faked ID. Only the Socialite driver, which is what talks to ID, is
 * faked, so id-client's callback and status's own User model run for real.
 *
 * @return TestResponse<Response>
 */
function signInThroughId(string $sub = 'idp-1', string $email = 'ada@example.com', string $name = 'Ada Lovelace'): TestResponse
{
    $idUser = (new SocialiteUser)->map(['id' => $sub, 'email' => $email, 'name' => $name]);
    $idUser->setToken('id-access-token');

    mock(Socialite::class)->shouldReceive('driver->user')->andReturn($idUser);

    return get(route('sso.callback'));
}

/**
 * Signs in through ID and hands back the remember cookie exactly as the browser received it.
 *
 * @return array{User, string}
 */
function signInThroughIdRemembered(): array
{
    $recaller = signInThroughId()->assertRedirect()->getCookie(Auth::guard()->getRecallerName());

    expect($recaller)->not->toBeNull();

    return [User::query()->where('idp_id', 'idp-1')->sole(), (string) $recaller?->getValue()];
}

/**
 * A back-channel event from ID, signed the way id-client's LogoutController checks it.
 *
 * @return TestResponse<Response>
 */
function signedIdEvent(string $event, User $user, string $signedWith = 'test-logout-secret'): TestResponse
{
    config(['id-client.logout_secret' => 'test-logout-secret']);

    $body = (string) json_encode(['event' => $event, 'sub' => $user->idp_id, 'issued_at' => now()->getTimestamp()]);

    return call('POST', route('sso.logout'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ID_SIGNATURE' => hash_hmac('sha256', $body, $signedWith),
    ], content: $body);
}

/**
 * A browser whose session has expired, so the remember cookie is all it still carries.
 *
 * @return TestResponse<Response>
 */
function returnWithOnlyTheRememberCookie(string $recaller): TestResponse
{
    test()->flushSession();
    Auth::forgetGuards();

    return test()->withCookie(Auth::guard()->getRecallerName(), $recaller)->get(route('dashboard'));
}
