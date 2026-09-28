<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;

use function Pest\Laravel\assertGuest;

/**
 * Runs the STAT-53 data migration against rows written the way status wrote them before
 * it moved onto id-client.
 */
function moveUsersOntoIdClient(): void
{
    (require database_path('migrations/2026_09_28_100000_move_users_onto_id_client.php'))->up();
}

function userSignedInBeforeTheMove(?string $idSub): User
{
    $user = User::factory()->create(['idp_id' => null, 'remember_token' => 'issued-earlier']);
    $user->forceFill(['id_sub' => $idSub])->save();

    return $user;
}

it('carries the ID subject over, so a returning user keeps their account', function () {
    $user = userSignedInBeforeTheMove('42');
    $unlinked = userSignedInBeforeTheMove(null);

    moveUsersOntoIdClient();

    expect($user->fresh()?->idp_id)->toBe('42')
        ->and($unlinked->fresh()?->idp_id)->toBeNull();

    signInThroughId('42', 'changed@example.com');

    expect(User::count())->toBe(2)
        ->and($user->fresh()?->email)->toBe('changed@example.com');
});

/**
 * Status handed out remember cookies before STAT-52 and no ID sign-out ever reached
 * them. Honouring them now would restore sessions for people who have since signed out
 * at ID or lost access to Status.
 */
it('refuses every remember cookie handed out before the move', function () {
    $user = userSignedInBeforeTheMove('42');
    $cookie = $user->id.'|issued-earlier|'.Auth::guard('web')->hashPasswordForCookie('');

    moveUsersOntoIdClient();

    returnWithOnlyTheRememberCookie($cookie)->assertRedirect(route('login'));
    assertGuest();
});
