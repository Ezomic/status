<?php

declare(strict_types=1);

use App\Models\Service;
use App\Services\CertificateInspector;
use Carbon\CarbonImmutable;
use Mockery\MockInterface;

it('records the expiry for active https services only', function () {
    $https = Service::factory()->create(['url' => 'https://a.test', 'name' => 'A']);
    $plain = Service::factory()->create(['url' => 'http://b.test', 'name' => 'B']);
    $paused = Service::factory()->paused()->create(['url' => 'https://c.test', 'name' => 'C']);

    $expiry = CarbonImmutable::parse('2026-11-01 09:00:00');

    $this->mock(CertificateInspector::class, function (MockInterface $mock) use ($expiry): void {
        // Once, not three times: http and paused services are never dialled.
        $mock->shouldReceive('expiresAt')->once()->andReturn($expiry);
    });

    $this->artisan('certificates:refresh')->assertSuccessful();

    expect($https->refresh()->certificate_expires_at?->toDateTimeString())
        ->toBe($expiry->toDateTimeString())
        ->and($https->certificate_checked_at)->not->toBeNull()
        ->and($plain->refresh()->certificate_expires_at)->toBeNull()
        ->and($plain->certificate_checked_at)->toBeNull()
        ->and($paused->refresh()->certificate_checked_at)->toBeNull();
});

it('nulls a stale expiry when the lookup fails rather than leaving yesterday in place', function () {
    // The important half of "unknown, never expiring": a host that stops answering must
    // not keep reporting a comfortable date from the last successful look.
    $service = Service::factory()->create([
        'url' => 'https://a.test',
        'certificate_expires_at' => CarbonImmutable::parse('2026-12-01 00:00:00'),
        'certificate_checked_at' => CarbonImmutable::parse('2026-08-01 00:00:00'),
    ]);

    $this->mock(CertificateInspector::class, function (MockInterface $mock): void {
        $mock->shouldReceive('expiresAt')->once()->andReturnNull();
    });

    $this->artisan('certificates:refresh')->assertSuccessful();

    expect($service->refresh()->certificate_expires_at)->toBeNull()
        ->and($service->certificate_checked_at?->toDateString())
        ->toBe(CarbonImmutable::now()->toDateString())
        ->and($service->certificateDaysRemaining(CarbonImmutable::now()))->toBeNull();
});

it('keeps going when one host throws', function () {
    Service::factory()->create(['url' => 'https://a.test', 'name' => 'A']);
    Service::factory()->create(['url' => 'https://b.test', 'name' => 'B']);

    $this->mock(CertificateInspector::class, function (MockInterface $mock): void {
        $mock->shouldReceive('expiresAt')
            ->twice()
            ->andReturnUsing(function (Service $service): ?CarbonImmutable {
                if ($service->name === 'A') {
                    throw new RuntimeException('handshake exploded');
                }

                return CarbonImmutable::parse('2026-11-01 00:00:00');
            });
    });

    $this->artisan('certificates:refresh')->assertSuccessful();

    expect(Service::where('name', 'A')->sole()->certificate_expires_at)->toBeNull()
        ->and(Service::where('name', 'B')->sole()->certificate_expires_at)->not->toBeNull();
});

it('says so when there is nothing on https to inspect', function () {
    Service::factory()->create(['url' => 'http://a.test']);

    $this->artisan('certificates:refresh')
        ->expectsOutputToContain('No active https services.')
        ->assertSuccessful();
});
