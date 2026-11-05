<?php

declare(strict_types=1);

use App\Enums\SpikeSignalState;
use App\Models\SpikeSignal;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config()->set('services.spike_signal.token', 'signal-token');
    config()->set('services.monitor.incident_webhook_url', 'https://chat.example/hook');
    Http::fake(['chat.example/*' => Http::response('', 200)]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function signal(array $overrides = []): array
{
    return [
        'source' => 'snag',
        'key' => 'route:12:invoices.show',
        'state' => 'open',
        'application' => ['id' => 12, 'name' => 'Billr'],
        'route' => 'invoices.show',
        'window_minutes' => 15,
        'count' => 9,
        'baseline_per_window' => 1.5,
        'threshold' => 5,
        'detected_at' => '2026-11-05T10:00:00+00:00',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $body
 */
function sendSignal(array $body, ?string $token = 'signal-token'): TestResponse
{
    return test()->postJson(route('api.signals.spikes'), $body, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}

describe('authentication', function (): void {
    it('refuses a missing or wrong token', function (): void {
        sendSignal(signal(), null)->assertUnauthorized();
        sendSignal(signal(), 'nope')->assertUnauthorized();

        expect(SpikeSignal::query()->count())->toBe(0);
    });

    it('is off when no token is configured', function (): void {
        config()->set('services.spike_signal.token', null);

        sendSignal(signal(), '')->assertUnauthorized();
        sendSignal(signal(), 'anything')->assertUnauthorized();
    });

    it('does not accept the status read token', function (): void {
        config()->set('services.status_endpoint.token', 'read-token');

        sendSignal(signal(), 'read-token')->assertUnauthorized();
    });

    it('does not start a session', function (): void {
        $response = sendSignal(signal())->assertAccepted();

        expect($response->headers->getCookies())->toBe([]);
    });
});

describe('recording', function (): void {
    it('records an open signal and alerts once', function (): void {
        sendSignal(signal())->assertAccepted()->assertExactJson(['recorded' => true]);

        $row = SpikeSignal::query()->sole();

        expect($row->state)->toBe(SpikeSignalState::Open)
            ->and($row->key)->toBe('route:12:invoices.show')
            ->and($row->application_name)->toBe('Billr')
            ->and($row->route)->toBe('invoices.show')
            ->and($row->count)->toBe(9)
            ->and($row->baseline_per_window)->toBe(1.5)
            ->and($row->resolved_at)->toBeNull();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['event'] === 'spike_open'
            && $request['service'] === 'Billr'
            && $request['text'] === 'Billr (invoices.show): report spike, 9 reports in 15 minutes (baseline 1.5)');
    });

    it('records an app level signal without a route', function (): void {
        sendSignal(signal(['key' => 'app:12', 'route' => null]))->assertAccepted();

        expect(SpikeSignal::query()->sole()->route)->toBeNull();
        Http::assertSent(fn ($request): bool => str_starts_with($request['text'], 'Billr: report spike'));
    });

    it('is idempotent for a repeated open', function (): void {
        sendSignal(signal())->assertAccepted();
        sendSignal(signal(['detected_at' => '2026-11-05T10:05:00+00:00', 'count' => 20]))
            ->assertAccepted()->assertExactJson(['recorded' => false]);

        expect(SpikeSignal::query()->sole()->count)->toBe(9);
        Http::assertSentCount(1);
    });

    it('resolves an open signal and alerts', function (): void {
        sendSignal(signal())->assertAccepted();
        sendSignal(signal(['state' => 'resolved', 'detected_at' => '2026-11-05T10:20:00+00:00']))
            ->assertAccepted()->assertExactJson(['recorded' => true]);

        $row = SpikeSignal::query()->sole();

        expect($row->state)->toBe(SpikeSignalState::Resolved)
            ->and($row->opened_at->toIso8601String())->toBe('2026-11-05T10:00:00+00:00')
            ->and($row->resolved_at?->toIso8601String())->toBe('2026-11-05T10:20:00+00:00');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request['event'] === 'spike_resolved'
            && $request['text'] === 'Billr (invoices.show): report spike over');
    });

    it('is idempotent for a repeated resolve', function (): void {
        sendSignal(signal())->assertAccepted();
        sendSignal(signal(['state' => 'resolved', 'detected_at' => '2026-11-05T10:20:00+00:00']))->assertAccepted();
        sendSignal(signal(['state' => 'resolved', 'detected_at' => '2026-11-05T10:25:00+00:00']))
            ->assertExactJson(['recorded' => false]);

        Http::assertSentCount(2);
    });

    it('records a resolve it never saw open without alerting', function (): void {
        sendSignal(signal(['state' => 'resolved']))->assertAccepted()->assertExactJson(['recorded' => true]);

        expect(SpikeSignal::query()->sole()->state)->toBe(SpikeSignalState::Resolved);
        Http::assertNothingSent();
    });

    it('reopens a resolved key as a new episode', function (): void {
        sendSignal(signal())->assertAccepted();
        sendSignal(signal(['state' => 'resolved', 'detected_at' => '2026-11-05T10:20:00+00:00']))->assertAccepted();
        sendSignal(signal(['detected_at' => '2026-11-05T11:00:00+00:00', 'count' => 12]))->assertExactJson(['recorded' => true]);

        $row = SpikeSignal::query()->sole();

        expect($row->state)->toBe(SpikeSignalState::Open)
            ->and($row->count)->toBe(12)
            ->and($row->opened_at->toIso8601String())->toBe('2026-11-05T11:00:00+00:00')
            ->and($row->resolved_at)->toBeNull();
        Http::assertSentCount(3);
    });

    it('drops a late retry older than what is stored', function (): void {
        sendSignal(signal(['detected_at' => '2026-11-05T10:20:00+00:00']))->assertAccepted();
        sendSignal(signal(['state' => 'resolved', 'detected_at' => '2026-11-05T10:00:00+00:00']))
            ->assertExactJson(['recorded' => false]);

        expect(SpikeSignal::query()->sole()->state)->toBe(SpikeSignalState::Open);
    });

    it('stores a time with an offset as the same instant in UTC', function (): void {
        sendSignal(signal(['detected_at' => '2026-11-05T12:00:00+02:00']))->assertAccepted();

        expect(SpikeSignal::query()->sole()->detected_at->toIso8601String())->toBe('2026-11-05T10:00:00+00:00');
    });

    it('keeps signals with different keys apart', function (): void {
        sendSignal(signal())->assertAccepted();
        sendSignal(signal(['key' => 'app:12', 'route' => null]))->assertAccepted();

        expect(SpikeSignal::query()->count())->toBe(2);
    });

    it('still records when the webhook is unreachable', function (): void {
        Http::fake(['chat.example/*' => fn () => throw new RuntimeException('down')]);

        sendSignal(signal())->assertAccepted();

        expect(SpikeSignal::query()->count())->toBe(1);
    });

    it('does not alert when no webhook is configured', function (): void {
        config()->set('services.monitor.incident_webhook_url', null);

        sendSignal(signal())->assertAccepted();

        Http::assertNothingSent();
    });
});

describe('validation', function (): void {
    it('refuses a body that breaks the contract', function (string $field, mixed $value): void {
        sendSignal(signal([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);

        expect(SpikeSignal::query()->count())->toBe(0);
    })->with([
        'unknown source' => ['source', 'other'],
        'missing key' => ['key', ''],
        'bad state' => ['state', 'pending'],
        'no application' => ['application', null],
        'negative count' => ['count', -1],
        'zero window' => ['window_minutes', 0],
        'bad time' => ['detected_at', 'yesterday-ish'],
        'non numeric baseline' => ['baseline_per_window', 'many'],
    ]);

    it('requires an application id and name', function (): void {
        sendSignal(signal(['application' => ['id' => 12]]))->assertUnprocessable()->assertJsonValidationErrors('application.name');
    });
});
