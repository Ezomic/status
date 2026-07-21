<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class IdOAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put('id_oauth.state', $state);
        $request->session()->put('id_oauth.verifier', $verifier);

        $query = http_build_query([
            'client_id' => (string) config('services.id.client_id'),
            'redirect_uri' => (string) config('services.id.redirect_uri'),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
            'code_challenge' => $this->codeChallenge($verifier),
            'code_challenge_method' => 'S256',
        ]);

        return redirect()->away(config('services.id.base_url').'/oauth/authorize?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull('id_oauth.state');
        $verifier = $request->session()->pull('id_oauth.verifier');

        abort_if(
            $request->input('state') === null || ! hash_equals((string) $expectedState, (string) $request->input('state')),
            Response::HTTP_FORBIDDEN,
            'Invalid authentication state.'
        );

        abort_if($request->missing('code'), Response::HTTP_FORBIDDEN, 'Authorization was denied.');

        $token = Http::asForm()->post(config('services.id.base_url').'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => (string) config('services.id.client_id'),
            'client_secret' => (string) config('services.id.client_secret'),
            'redirect_uri' => (string) config('services.id.redirect_uri'),
            'code_verifier' => (string) $verifier,
            'code' => (string) $request->input('code'),
        ]);

        abort_unless($token->successful(), Response::HTTP_FORBIDDEN, 'Could not exchange authorization code.');

        $profile = Http::withToken($token->json('access_token'))
            ->acceptJson()
            ->get(config('services.id.base_url').'/api/userinfo');

        abort_unless($profile->successful(), Response::HTTP_FORBIDDEN, 'You do not have access to Status.');

        $sub = (string) $profile->json('sub');
        $email = (string) $profile->json('email');

        // Match on the id subject, falling back to email so a pre-existing local
        // account is linked to its id identity on first SSO login rather than
        // colliding on the unique email.
        $user = User::where('id_sub', $sub)->first()
            ?? User::where('email', $email)->first()
            ?? new User;

        $user->forceFill([
            'name' => (string) $profile->json('name'),
            'email' => $email,
            'id_sub' => $sub,
        ])->save();

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
