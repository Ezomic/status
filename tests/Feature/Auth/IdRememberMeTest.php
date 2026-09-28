<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;

/**
 * An ID sign-in keeps a browser signed in until the user signs out, so the 120 minute
 * idle session never surfaces as a logout (owner decision, 2026-09-28). That is only
 * safe because an ID sign-out or a revoked grant now reaches status through id-client's
 * back-channel receiver, which STAT-52 found missing. STAT-53.
 */

/**
 * The next request from a session that is still live. A fresh guard, as on a real
 * request, so the user is read back with the stamp ID's sign-out left on the row.
 *
 * @return TestResponse<Response>
 */
function nextRequestInTheSameSession(): TestResponse
{
    Auth::forgetGuards();

    return get(route('dashboard'));
}

it('restores the session from the remember cookie alone', function () {
    [$user, $recaller] = signInThroughIdRemembered();

    returnWithOnlyTheRememberCookie($recaller)->assertOk();

    assertAuthenticatedAs($user);
    expect(Auth::guard()->viaRemember())->toBeTrue();
});

it('refuses the remember cookie once ID signs the user out', function () {
    [$user, $recaller] = signInThroughIdRemembered();
    returnWithOnlyTheRememberCookie($recaller)->assertOk();

    signedIdEvent('logout', $user)->assertOk()->assertJson(['status' => 'ok']);

    returnWithOnlyTheRememberCookie($recaller)->assertRedirect(route('login'));
    assertGuest();
});

it('ends a session the remember cookie restored once ID signs the user out', function () {
    [$user, $recaller] = signInThroughIdRemembered();
    returnWithOnlyTheRememberCookie($recaller)->assertOk();

    signedIdEvent('logout', $user)->assertOk();

    nextRequestInTheSameSession()->assertRedirect('/');
    assertGuest();
});

it('refuses the remember cookie once ID revokes access to Status', function () {
    [$user, $recaller] = signInThroughIdRemembered();

    signedIdEvent('access.revoked', $user)->assertOk();

    returnWithOnlyTheRememberCookie($recaller)->assertRedirect(route('login'));
    assertGuest();
});

it('keeps the remember cookie when a sign-out is not signed with the shared secret', function () {
    [$user, $recaller] = signInThroughIdRemembered();

    signedIdEvent('logout', $user, signedWith: 'someone-else')->assertUnauthorized();

    returnWithOnlyTheRememberCookie($recaller)->assertOk();
    assertAuthenticatedAs($user);
});
