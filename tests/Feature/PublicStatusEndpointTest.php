<?php

namespace Tests\Feature;

use App\Enums\ServiceState;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.status_endpoint.token', 'secret-token');
        Cache::flush();
    }

    public function test_it_rejects_a_missing_or_wrong_token(): void
    {
        $this->getJson(route('api.status'))->assertUnauthorized();

        $this->getJson(route('api.status'), ['Authorization' => 'Bearer nope'])
            ->assertUnauthorized();
    }

    public function test_it_is_disabled_when_no_token_is_configured(): void
    {
        config()->set('services.status_endpoint.token', null);

        $this->getJson(route('api.status'), ['Authorization' => 'Bearer secret-token'])
            ->assertUnauthorized();
    }

    public function test_it_returns_only_public_active_services_with_leak_safe_fields(): void
    {
        $public = Service::factory()->create([
            'name' => 'Portal',
            'url' => 'https://secret-host.internal/health',
            'is_active' => true,
            'is_public' => true,
            'current_state' => ServiceState::Up,
            'last_checked_at' => now(),
            'last_response_time_ms' => 137,
        ]);

        Service::factory()->create(['is_public' => false]);                    // not opted in
        Service::factory()->create(['is_public' => true, 'is_active' => false]); // paused

        $response = $this->getJson(route('api.status'), ['Authorization' => 'Bearer secret-token'])
            ->assertOk()
            ->assertJsonCount(1, 'services')
            ->assertJsonPath('services.0.slug', $public->slug)
            ->assertJsonPath('services.0.name', 'Portal')
            ->assertJsonPath('services.0.state', 'up')
            ->assertJsonPath('services.0.last_checked_at', $public->last_checked_at->toIso8601String());

        // Leak-safe: no URL/host, response time, or incident detail anywhere.
        $this->assertStringNotContainsString('secret-host.internal', $response->getContent());
        $this->assertStringNotContainsString('137', $response->getContent());
        $this->assertSame(
            ['slug', 'name', 'state', 'last_checked_at'],
            array_keys($response->json('services.0')),
        );
    }
}
