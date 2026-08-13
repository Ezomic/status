<?php

declare(strict_types=1);

use App\Actions\Monitoring\EvaluateCertificateAlert;
use App\Enums\CertificateAlert;
use App\Models\Service;
use App\Models\User;
use App\Notifications\CertificateExpiring;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

const NOW = '2026-08-13 09:00:00';

beforeEach(function (): void {
    Notification::fake();
    User::factory()->create(['wants_incident_mail' => true]);
    $this->now = CarbonImmutable::parse(NOW);
});

function withExpiry(?int $days, ?CertificateAlert $alerted = null): Service
{
    return Service::factory()->create([
        'url' => 'https://a.test',
        'certificate_expires_at' => $days === null
            ? null
            : CarbonImmutable::parse(NOW)->addDays($days),
        'certificate_checked_at' => CarbonImmutable::parse(NOW),
        'certificate_alerted' => $alerted,
    ]);
}

it('alerts once on crossing into the warning window', function () {
    $service = withExpiry(11);

    expect(app(EvaluateCertificateAlert::class)->handle($service, $this->now))
        ->toBe(CertificateAlert::Expiring);

    Notification::assertSentTimes(CertificateExpiring::class, 1);
});

it('stays quiet on every later run while it remains inside the window', function () {
    // The whole point: certificates:refresh runs daily, so alerting on the state rather
    // than the transition would mail every morning for a month.
    $service = withExpiry(11);

    foreach (range(1, 5) as $ignored) {
        app(EvaluateCertificateAlert::class)->handle($service->refresh(), $this->now);
    }

    Notification::assertSentTimes(CertificateExpiring::class, 1);
});

it('alerts again when it actually expires', function () {
    $service = withExpiry(-1, CertificateAlert::Expiring);

    expect(app(EvaluateCertificateAlert::class)->handle($service, $this->now))
        ->toBe(CertificateAlert::Expired);

    Notification::assertSentTimes(CertificateExpiring::class, 1);
});

it('does not alert twice for an expired certificate', function () {
    $service = withExpiry(-4, CertificateAlert::Expired);

    app(EvaluateCertificateAlert::class)->handle($service, $this->now);

    Notification::assertNothingSent();
});

it('says nothing for a comfortably valid certificate', function () {
    $service = withExpiry(62);

    expect(app(EvaluateCertificateAlert::class)->handle($service, $this->now))->toBeNull();

    Notification::assertNothingSent();
});

it('re-arms silently after a renewal so next year is not swallowed', function () {
    // Was alerted, now valid again: reset without an email, because nobody needs to be
    // told a certificate is fine.
    $service = withExpiry(89, CertificateAlert::Expiring);

    expect(app(EvaluateCertificateAlert::class)->handle($service, $this->now))->toBeNull();
    Notification::assertNothingSent();

    // And the next approach alerts again rather than being silently swallowed.
    $service->forceFill(['certificate_expires_at' => $this->now->addDays(9)])->save();

    expect(app(EvaluateCertificateAlert::class)->handle($service->refresh(), $this->now))
        ->toBe(CertificateAlert::Expiring);
    Notification::assertSentTimes(CertificateExpiring::class, 1);
});

it('never alerts when the expiry could not be read', function () {
    // STAT-23 nulls the expiry on a failed lookup so it reads as unknown, never as
    // expiring. An unknown must not manufacture an alert.
    $service = withExpiry(null);

    expect(app(EvaluateCertificateAlert::class)->handle($service, $this->now))->toBeNull();

    Notification::assertNothingSent();
});

it('does not treat an unreadable expiry as a renewal', function () {
    // Already warned, then the host stops answering. "We could not look" is not evidence
    // the certificate was renewed, so the armed state must survive.
    $service = withExpiry(null, CertificateAlert::Expiring);

    expect(app(EvaluateCertificateAlert::class)->handle($service, $this->now))
        ->toBe(CertificateAlert::Expiring);

    expect($service->refresh()->certificate_alerted)->toBe(CertificateAlert::Expiring);
    Notification::assertNothingSent();
});

it('sends nothing to a user who opted out of incident mail', function () {
    User::query()->update(['wants_incident_mail' => false]);

    app(EvaluateCertificateAlert::class)->handle(withExpiry(11), $this->now);

    Notification::assertNothingSent();
});
