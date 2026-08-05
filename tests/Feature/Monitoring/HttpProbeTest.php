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
