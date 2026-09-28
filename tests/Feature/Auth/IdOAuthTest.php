<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.id.base_url' => 'https://id.test',
            'services.id.client_id' => 'client-123',
            'services.id.client_secret' => 'secret-456',
            'services.id.redirect_uri' => 'https://status.test/auth/callback',
        ]);
    }

    public function test_login_redirects_to_the_id_authorization_endpoint()
    {
        $response = $this->get(route('login'));

        $response->assertRedirectContains('https://id.test/oauth/authorize');
        $response->assertRedirectContains('client_id=client-123');
        $response->assertRedirectContains('code_challenge_method=S256');

        $this->assertNotNull(session('id_oauth.state'));
        $this->assertNotNull(session('id_oauth.verifier'));
    }

    public function test_callback_provisions_the_user_and_signs_them_in()
    {
        Http::fake([
            'https://id.test/oauth/token' => Http::response(['access_token' => 'token-abc']),
            'https://id.test/api/userinfo' => Http::response([
                'sub' => '42',
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
            ]),
        ]);

        $response = $this
            ->withSession(['id_oauth.state' => 'state-1', 'id_oauth.verifier' => 'verifier-1'])
            ->get(route('auth.callback', ['code' => 'auth-code', 'state' => 'state-1']));

        $response->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $user = User::where('id_sub', '42')->first();
        $this->assertNotNull($user);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertSame('Ada Lovelace', $user->name);
    }

    public function test_callback_rejects_a_mismatched_state()
    {
        Http::fake();

        $response = $this
            ->withSession(['id_oauth.state' => 'expected', 'id_oauth.verifier' => 'v'])
            ->get(route('auth.callback', ['code' => 'auth-code', 'state' => 'tampered']));

        $response->assertForbidden();
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_callback_denies_a_user_without_access()
    {
        Http::fake([
            'https://id.test/oauth/token' => Http::response(['access_token' => 'token-abc']),
            'https://id.test/api/userinfo' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $response = $this
            ->withSession(['id_oauth.state' => 'state-1', 'id_oauth.verifier' => 'v'])
            ->get(route('auth.callback', ['code' => 'auth-code', 'state' => 'state-1']));

        $response->assertForbidden();
        $this->assertGuest();
    }

    public function test_existing_user_is_matched_by_id_sub()
    {
        $user = User::factory()->create(['id_sub' => '42', 'email' => 'old@example.com']);

        Http::fake([
            'https://id.test/oauth/token' => Http::response(['access_token' => 'token-abc']),
            'https://id.test/api/userinfo' => Http::response([
                'sub' => '42',
                'name' => 'Renamed',
                'email' => 'new@example.com',
            ]),
        ]);

        $this->withSession(['id_oauth.state' => 's', 'id_oauth.verifier' => 'v'])
            ->get(route('auth.callback', ['code' => 'c', 'state' => 's']))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::count());
        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_existing_local_account_is_linked_by_email_on_first_login()
    {
        $user = User::factory()->create(['id_sub' => null, 'email' => 'admin@example.com']);

        Http::fake([
            'https://id.test/oauth/token' => Http::response(['access_token' => 'token-abc']),
            'https://id.test/api/userinfo' => Http::response([
                'sub' => '99',
                'name' => 'Admin',
                'email' => 'admin@example.com',
            ]),
        ]);

        $this->withSession(['id_oauth.state' => 's', 'id_oauth.verifier' => 'v'])
            ->get(route('auth.callback', ['code' => 'c', 'state' => 's']))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::count());
        $this->assertSame('99', $user->fresh()->id_sub);
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_logout_ends_the_session()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_signing_in_through_id_sets_no_remember_me_cookie()
    {
        Http::fake([
            'https://id.test/oauth/token' => Http::response(['access_token' => 'token-abc']),
            'https://id.test/api/userinfo' => Http::response([
                'sub' => '42',
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
            ]),
        ]);

        $this
            ->withSession(['id_oauth.state' => 'state-1', 'id_oauth.verifier' => 'verifier-1'])
            ->get(route('auth.callback', ['code' => 'auth-code', 'state' => 'state-1']))
            ->assertRedirect(route('dashboard'))
            ->assertCookieMissing($this->recallerName());

        $this->assertNull(User::where('id_sub', '42')->firstOrFail()->remember_token);
    }

    public function test_remember_me_cookies_already_issued_do_not_sign_the_user_back_in()
    {
        // Sign-ins before STAT-52 set these, hashing the null password as ''. Honouring
        // them would bring back 400-day sessions that an ID sign-out never reaches.
        $user = User::factory()->create(['remember_token' => 'issued-earlier']);
        $cookie = $user->id.'|issued-earlier|'.Auth::guard('web')->hashPasswordForCookie('');

        $this->withCookie($this->recallerName(), $cookie)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    private function recallerName(): string
    {
        return Auth::guard('web')->getRecallerName();
    }
}
