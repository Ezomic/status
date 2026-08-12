<?php

declare(strict_types=1);

use App\Models\Service;
use App\Services\HttpProbe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('records the status code of a successful response', function () {
    Http::fake(['*up.test*' => Http::response('ok', 200)]);

    $service = Service::factory()->create(['url' => 'https://up.test/health']);

    $result = app(HttpProbe::class)->probe($service);

    expect($result->statusCode)->toBe(200)
        ->and($result->error)->toBeNull();
});

it('records an unexpected status code rather than treating it as an error', function () {
    Http::fake(['*broken.test*' => Http::response('nope', 503)]);

    $service = Service::factory()->create(['url' => 'https://broken.test']);

    $result = app(HttpProbe::class)->probe($service);

    expect($result->statusCode)->toBe(503)
        ->and($result->error)->toBeNull();
});

it('returns a failed result instead of throwing when the connection fails', function () {
    Http::fake(['*gone.test*' => fn () => throw new ConnectionException(
        'cURL error 28: Operation timed out (see https://curl.se/libcurl/c/libcurl-errors.html) for https://gone.test'
    )]);

    $service = Service::factory()->create(['url' => 'https://gone.test', 'timeout_seconds' => 5]);

    $result = app(HttpProbe::class)->probe($service);

    expect($result->statusCode)->toBeNull()
        ->and($result->error)->toBe('cURL error 28: Operation timed out')
        ->and($result->responseTimeMs)->toBe(0);
});

it('maps pooled responses back to the correct service', function () {
    Http::fake([
        '*one.test*' => Http::response('', 200),
        '*two.test*' => Http::response('', 500),
        '*three.test*' => fn () => throw new ConnectionException('unreachable'),
    ]);

    $one = Service::factory()->create(['url' => 'https://one.test']);
    $two = Service::factory()->create(['url' => 'https://two.test']);
    $three = Service::factory()->create(['url' => 'https://three.test']);

    $results = app(HttpProbe::class)->probeMany(collect([$one, $two, $three]));

    expect($results[$one->id]->statusCode)->toBe(200)
        ->and($results[$two->id]->statusCode)->toBe(500)
        ->and($results[$three->id]->statusCode)->toBeNull()
        ->and($results[$three->id]->error)->toContain('unreachable');
});

it('returns nothing when there is nothing to probe', function () {
    expect(app(HttpProbe::class)->probeMany(collect()))->toBe([]);
});

it('captures the Retry-After header so a deploy can be told apart from an outage', function () {
    // Exactly what `artisan down --retry=15` serves.
    Http::fake(['*deploying.test*' => Http::response('Service Unavailable', 503, ['Retry-After' => '15'])]);

    $service = Service::factory()->create(['url' => 'https://deploying.test']);

    $result = app(HttpProbe::class)->probe($service);

    expect($result->statusCode)->toBe(503)
        ->and($result->retryAfter)->toBe('15')
        ->and($result->error)->toBeNull();
});

it('leaves retryAfter null when the response does not carry the header', function () {
    Http::fake(['*broken.test*' => Http::response('nope', 503)]);

    $service = Service::factory()->create(['url' => 'https://broken.test']);

    expect(app(HttpProbe::class)->probe($service)->retryAfter)->toBeNull();
});

it('reports the expected content as present when the body contains it', function () {
    Http::fake(['*app.test*' => Http::response('<html><body>Sign in to Tracker</body></html>', 200)]);

    $service = Service::factory()->create(['url' => 'https://app.test', 'expected_body' => 'Sign in']);

    expect(app(HttpProbe::class)->probe($service)->bodyMatched)->toBeTrue();
});

it('reports the expected content as missing when the body does not contain it', function () {
    // A Laravel app that boots with an unreachable database still answers 200.
    Http::fake(['*app.test*' => Http::response('<html><body>Server Error</body></html>', 200)]);

    $service = Service::factory()->create(['url' => 'https://app.test', 'expected_body' => 'Sign in']);

    $result = app(HttpProbe::class)->probe($service);

    expect($result->statusCode)->toBe(200)
        ->and($result->bodyMatched)->toBeFalse();
});

it('does not run the content assertion when the service opted out', function () {
    Http::fake(['*app.test*' => Http::response('anything at all', 200)]);

    foreach ([null, ''] as $expected) {
        $service = Service::factory()->create(['url' => 'https://app.test', 'expected_body' => $expected]);

        expect(app(HttpProbe::class)->probe($service)->bodyMatched)->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| Chunked concurrency (STAT-29)
|--------------------------------------------------------------------------
*/

it('probes every service even though the pool is chunked', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    config()->set('services.monitor.concurrency', 2);

    // Seven services across chunks of two: the last chunk is a remainder of one, which
    // is where an off-by-one would drop a service silently.
    $services = Service::factory()->count(7)->create();

    $results = app(HttpProbe::class)->probeMany($services);

    expect($results)->toHaveCount(7);

    foreach ($services as $service) {
        expect($results)->toHaveKey($service->id)
            ->and($results[$service->id]->statusCode)->toBe(200);
    }
});

it('keeps each result attached to the right service across chunk boundaries', function () {
    config()->set('services.monitor.concurrency', 2);

    Http::fake([
        '*one.test*' => Http::response('', 200),
        '*two.test*' => Http::response('', 201),
        '*three.test*' => Http::response('', 202),
        '*four.test*' => Http::response('', 203),
        '*five.test*' => Http::response('', 204),
    ]);

    $services = collect(['one', 'two', 'three', 'four', 'five'])
        ->map(fn (string $host): Service => Service::factory()->create(['url' => "https://{$host}.test"]));

    $results = app(HttpProbe::class)->probeMany($services);

    // Distinct status per host, so a mix-up between chunks cannot pass.
    expect($results[$services[0]->id]->statusCode)->toBe(200)
        ->and($results[$services[1]->id]->statusCode)->toBe(201)
        ->and($results[$services[2]->id]->statusCode)->toBe(202)
        ->and($results[$services[3]->id]->statusCode)->toBe(203)
        ->and($results[$services[4]->id]->statusCode)->toBe(204);
});

it('probes sequentially when concurrency is one', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    config()->set('services.monitor.concurrency', 1);

    $services = Service::factory()->count(3)->create();

    expect(app(HttpProbe::class)->probeMany($services))->toHaveCount(3);
});

it('falls back to the default when concurrency is missing or nonsense', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $services = Service::factory()->count(4)->create();

    foreach ([null, 0, -5, 'many', ''] as $configured) {
        config()->set('services.monitor.concurrency', $configured);

        expect(app(HttpProbe::class)->probeMany($services))->toHaveCount(4);
    }

    expect(HttpProbe::DEFAULT_CONCURRENCY)->toBe(3);
});
