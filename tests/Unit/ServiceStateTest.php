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
