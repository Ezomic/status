<?php

declare(strict_types=1);

use App\Enums\HttpMethod;
use App\Models\Service;
use App\Models\User;
use App\Services\HttpProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('defaults to GET so nothing changes for existing services', function () {
    expect(Service::factory()->create()->http_method)->toBe(HttpMethod::Get);
});

it('issues a HEAD when configured to', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $service = Service::factory()->create(['http_method' => HttpMethod::Head]);

    app(HttpProbe::class)->probe($service);

    Http::assertSent(fn ($request) => $request->method() === 'HEAD');
});

it('issues a GET otherwise', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $service = Service::factory()->create(['http_method' => HttpMethod::Get]);

    app(HttpProbe::class)->probe($service);

    Http::assertSent(fn ($request) => $request->method() === 'GET');
});

it('sends configured headers', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $service = Service::factory()->create([
        'headers' => ['Authorization' => 'Bearer probe-secret', 'Accept' => 'application/json'],
    ]);

    app(HttpProbe::class)->probe($service);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer probe-secret')
        && $request->hasHeader('Accept', 'application/json'));
});

it('encrypts header values at rest', function () {
    // The services table becomes a secret store the moment someone puts an Authorization
    // value here, so the raw row must not contain it.
    $service = Service::factory()->create([
        'headers' => ['Authorization' => 'Bearer probe-secret'],
    ]);

    $raw = DB::table('services')->where('id', $service->id)->value('headers');

    expect($raw)->not->toContain('probe-secret')
        ->and($service->fresh()->headers)->toBe(['Authorization' => 'Bearer probe-secret']);
});

it('refuses HEAD combined with expected content', function () {
    // A HEAD response has no body, so the assertion could never pass and the service
    // would sit permanently down for a reason nobody could see (STAT-40 vs STAT-22).
    $this->actingAs($this->user)
        ->post(route('services.store'), validPayload([
            'http_method' => 'HEAD',
            'expected_body' => 'Sign in',
        ]))
        ->assertSessionHasErrors('expected_body');

    expect(Service::count())->toBe(0);
});

it('allows HEAD with no expected content', function () {
    $this->actingAs($this->user)
        ->post(route('services.store'), validPayload([
            'http_method' => 'HEAD',
            'expected_body' => '',
        ]))
        ->assertSessionHasNoErrors();

    expect(Service::sole()->http_method)->toBe(HttpMethod::Head);
});

it('rejects a method that is not GET or HEAD', function () {
    // A check runs every minute forever, so it must be safe to repeat.
    foreach (['POST', 'DELETE', 'PUT'] as $method) {
        $this->actingAs($this->user)
            ->post(route('services.store'), validPayload(['http_method' => $method]))
            ->assertSessionHasErrors('http_method');
    }

    expect(Service::count())->toBe(0);
});

it('never renders header values back to the browser', function () {
    // Only the names travel: an Authorization token must not return to the client just
    // because someone opened the edit form.
    $service = Service::factory()->create([
        'headers' => ['Authorization' => 'Bearer probe-secret'],
    ]);

    $body = $this->actingAs($this->user)
        ->get(route('services.show', $service))
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('probe-secret')
        ->and($body)->toContain('Authorization');
});

it('keeps header values out of every public payload', function () {
    config()->set('services.status_endpoint.token', 'secret-token');

    Service::factory()->create([
        'is_active' => true,
        'is_public' => true,
        'last_checked_at' => now(),
        'headers' => ['Authorization' => 'Bearer probe-secret'],
    ]);

    expect($this->get(route('home'))->getContent())->not->toContain('probe-secret');
    expect(
        $this->getJson(route('api.status'), ['Authorization' => 'Bearer secret-token'])->getContent()
    )->not->toContain('probe-secret');
});
