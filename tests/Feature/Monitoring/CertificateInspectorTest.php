<?php

declare(strict_types=1);

use App\Models\Service;
use App\Services\CertificateInspector;
use Carbon\CarbonImmutable;

/*
 * The parse and the days-remaining arithmetic are covered without opening a socket:
 * expiryFromParsedCertificate() takes what openssl_x509_parse() returns, so the date
 * handling is testable on its own.
 */

it('reads the expiry out of a parsed certificate', function () {
    $expiry = CarbonImmutable::parse('2026-10-05 11:22:33', 'UTC');

    $result = app(CertificateInspector::class)->expiryFromParsedCertificate([
        'validTo_time_t' => $expiry->getTimestamp(),
    ]);

    expect($result?->toIso8601String())->toBe($expiry->toIso8601String());
});

it('returns null when the parsed certificate has no usable expiry', function () {
    $inspector = app(CertificateInspector::class);

    expect($inspector->expiryFromParsedCertificate([]))->toBeNull()
        ->and($inspector->expiryFromParsedCertificate(['validTo_time_t' => 0]))->toBeNull()
        ->and($inspector->expiryFromParsedCertificate(['validTo_time_t' => -1]))->toBeNull()
        ->and($inspector->expiryFromParsedCertificate(['validTo_time_t' => 'soon']))->toBeNull();
});

it('never inspects a service that is not on https', function () {
    // No socket is opened, so this also proves the scheme guard comes first.
    $service = Service::factory()->create(['url' => 'http://insecure.test']);

    expect($service->usesTls())->toBeFalse()
        ->and(app(CertificateInspector::class)->expiresAt($service))->toBeNull();
});

it('defaults to port 443 and honours an explicit port', function () {
    expect(Service::factory()->create(['url' => 'https://a.test/health'])->tlsPort())->toBe(443)
        ->and(Service::factory()->create(['url' => 'https://a.test:8443'])->tlsPort())->toBe(8443);
});

it('keeps www in the TLS host even though the display host strips it', function () {
    // SNI decides which certificate the server presents, so trimming www. can fetch
    // an entirely different one.
    $service = Service::factory()->create(['url' => 'https://www.example.test']);

    expect($service->tlsHost())->toBe('www.example.test')
        ->and($service->host())->toBe('example.test');
});

it('counts whole days remaining', function () {
    $now = CarbonImmutable::parse('2026-08-06 12:00:00');
    $service = Service::factory()->create([
        'certificate_expires_at' => $now->addDays(62)->addHours(3),
    ]);

    expect($service->certificateDaysRemaining($now))->toBe(62);
});

it('counts negative days once the certificate has expired', function () {
    $now = CarbonImmutable::parse('2026-08-06 12:00:00');
    $service = Service::factory()->create([
        'certificate_expires_at' => $now->subDays(4),
    ]);

    expect($service->certificateDaysRemaining($now))->toBe(-4);
});

it('reports unknown rather than expiring when the expiry was never established', function () {
    $service = Service::factory()->create(['certificate_expires_at' => null]);

    expect($service->certificateDaysRemaining(CarbonImmutable::now()))->toBeNull();
});

it('warns inside certbot\'s renewal window', function () {
    expect(CertificateInspector::WARN_WITHIN_DAYS)->toBe(30);
});
