<?php

declare(strict_types=1);

use App\Models\Service;
use Database\Seeders\ServiceSeeder;

function seedServices(): void
{
    (new ServiceSeeder)->run();
}

it('seeds Tempo alongside the other deployed apps', function () {
    seedServices();

    $tempo = Service::query()->where('url', 'https://tempo.thijssensoftware.nl')->sole();

    expect($tempo->name)->toBe('Tempo')
        ->and($tempo->is_public)->toBeTrue()
        ->and($tempo->is_active)->toBeTrue();
});

it('seeds the Garmin sidecar as a non-public loopback check', function () {
    seedServices();

    $sidecar = Service::query()->where('url', 'http://127.0.0.1:8790/health')->sole();

    // Loopback-only by design, so publishing this URL would be a leak as well as
    // useless to anyone outside the droplet.
    expect($sidecar->is_public)->toBeFalse()
        ->and($sidecar->expected_body)->toBe('"status":"ok"')
        ->and($sidecar->is_active)->toBeTrue();
});

it('is idempotent, so re-seeding does not duplicate services', function () {
    seedServices();
    $first = Service::query()->count();

    seedServices();

    expect(Service::query()->count())->toBe($first);
});

it('leaves a service that was retuned by hand alone', function () {
    seedServices();
    $sidecar = Service::query()->where('url', 'http://127.0.0.1:8790/health')->sole();
    $sidecar->forceFill(['interval_seconds' => 300, 'is_active' => false])->save();

    seedServices();

    expect($sidecar->fresh())
        ->interval_seconds->toBe(300)
        ->is_active->toBeFalse();
});

it('still seeds the services that carry no extra attributes', function () {
    seedServices();

    $chronos = Service::query()->where('url', 'https://chronos.thijssensoftware.nl')->sole();

    expect($chronos->name)->toBe('Chronos')
        ->and($chronos->expected_body)->toBeNull();
});
