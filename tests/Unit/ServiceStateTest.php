<?php

declare(strict_types=1);

use App\Enums\ServiceState;
use App\ValueObjects\ProbeResult;

it('classifies a matching status code under the threshold as up', function () {
    $state = ServiceState::classify(new ProbeResult(200, 120), 200, 1000);

    expect($state)->toBe(ServiceState::Up);
});

it('classifies a matching status code at the threshold as degraded', function () {
    $state = ServiceState::classify(new ProbeResult(200, 1000), 200, 1000);

    expect($state)->toBe(ServiceState::Degraded);
});

it('classifies a matching status code over the threshold as degraded', function () {
    $state = ServiceState::classify(new ProbeResult(200, 2400), 200, 1000);

    expect($state)->toBe(ServiceState::Degraded);
});

it('classifies an unexpected status code as down', function () {
    $state = ServiceState::classify(new ProbeResult(500, 80), 200, 1000);

    expect($state)->toBe(ServiceState::Down);
});

it('classifies a connection error as down even when a status code came back', function () {
    $state = ServiceState::classify(new ProbeResult(200, 80, 'Connection timed out'), 200, 1000);

    expect($state)->toBe(ServiceState::Down);
});

it('honours a non 200 expected status code', function () {
    expect(ServiceState::classify(new ProbeResult(204, 80), 204, 1000))->toBe(ServiceState::Up)
        ->and(ServiceState::classify(new ProbeResult(200, 80), 204, 1000))->toBe(ServiceState::Down);
});

it('orders severity up below degraded below down', function () {
    expect(ServiceState::Degraded->isWorseThan(ServiceState::Up))->toBeTrue()
        ->and(ServiceState::Down->isWorseThan(ServiceState::Degraded))->toBeTrue()
        ->and(ServiceState::Up->isWorseThan(ServiceState::Down))->toBeFalse()
        ->and(ServiceState::Down->isWorseThan(ServiceState::Down))->toBeFalse();
});

it('treats unknown as no worse than up so it never triggers an incident', function () {
    expect(ServiceState::Unknown->isWorseThan(ServiceState::Up))->toBeFalse()
        ->and(ServiceState::Unknown->severity())->toBe(ServiceState::Up->severity());
});

it('classifies a 503 carrying Retry-After as maintenance', function () {
    $state = ServiceState::classify(new ProbeResult(503, 40, retryAfter: '15'), 200, 1000);

    expect($state)->toBe(ServiceState::Maintenance);
});

it('classifies a bare 503 as down', function () {
    // Nothing announced this one, so it is a failure rather than a deploy.
    $state = ServiceState::classify(new ProbeResult(503, 40), 200, 1000);

    expect($state)->toBe(ServiceState::Down);
});

it('classifies a connection error as down even if Retry-After somehow came back', function () {
    $state = ServiceState::classify(new ProbeResult(503, 40, 'Connection timed out', '15'), 200, 1000);

    expect($state)->toBe(ServiceState::Down);
});

it('does not treat Retry-After on any other status code as maintenance', function () {
    // 429 and 301 both use Retry-After; only a 503 means "deliberately offline".
    expect(ServiceState::classify(new ProbeResult(429, 40, retryAfter: '30'), 200, 1000))
        ->toBe(ServiceState::Down);
});

it('treats maintenance as no worse than up so it never escalates an incident', function () {
    expect(ServiceState::Maintenance->isWorseThan(ServiceState::Up))->toBeFalse()
        ->and(ServiceState::Maintenance->isWorseThan(ServiceState::Degraded))->toBeFalse()
        ->and(ServiceState::Maintenance->severity())->toBe(ServiceState::Up->severity());
});

it('classifies a 200 missing the expected content as down', function () {
    $state = ServiceState::classify(new ProbeResult(200, 80, bodyMatched: false), 200, 1000);

    expect($state)->toBe(ServiceState::Down);
});

it('classifies a 200 containing the expected content as up', function () {
    $state = ServiceState::classify(new ProbeResult(200, 80, bodyMatched: true), 200, 1000);

    expect($state)->toBe(ServiceState::Up);
});

it('leaves a service with no expected content behaving exactly as before', function () {
    // bodyMatched null means the assertion never ran.
    expect(ServiceState::classify(new ProbeResult(200, 80), 200, 1000))->toBe(ServiceState::Up)
        ->and(ServiceState::classify(new ProbeResult(500, 80), 200, 1000))->toBe(ServiceState::Down);
});

it('reports a wrong status code rather than the missing content', function () {
    // Both are wrong; the status code is the more useful thing to be told.
    $state = ServiceState::classify(new ProbeResult(500, 80, bodyMatched: false), 200, 1000);

    expect($state)->toBe(ServiceState::Down);
});

it('does not fail a maintenance page for lacking the app content', function () {
    // A deploy's maintenance page will never contain the app's own markup, and that
    // must not turn a planned window into an outage.
    $state = ServiceState::classify(
        new ProbeResult(503, 40, retryAfter: '15', bodyMatched: false),
        200,
        1000,
    );

    expect($state)->toBe(ServiceState::Maintenance);
});

it('still marks a slow response with matching content as degraded', function () {
    $state = ServiceState::classify(new ProbeResult(200, 2400, bodyMatched: true), 200, 1000);

    expect($state)->toBe(ServiceState::Degraded);
});
