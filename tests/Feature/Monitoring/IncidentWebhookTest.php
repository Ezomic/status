<?php

declare(strict_types=1);

use App\Actions\Monitoring\EvaluateIncident;
use App\Actions\Services\SaveService;
use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const WEBHOOK = 'https://chat.example/hooks/abc';

beforeEach(function (): void {
    config()->set('services.monitor.incident_webhook_url', WEBHOOK);
    $this->service = Service::factory()->create(['degraded_threshold_ms' => 1000]);
});

/**
 * Drive a service through states, evaluating after each, as the command does.
 *
 * @param  list<ServiceState>  $states
 */
function walk(Service $service, array $states): void
{
    $action = app(EvaluateIncident::class);
    $at = CarbonImmutable::now()->subMinutes(count($states));

    foreach ($states as $index => $state) {
        $check = Check::factory()->for($service)->create([
            'state' => $state,
            'ok' => $state !== ServiceState::Down,
            'status_code' => $state === ServiceState::Down ? 500 : 200,
            'response_time_ms' => $state === ServiceState::Degraded ? 2400 : 120,
            'checked_at' => $at->addMinutes($index),
        ]);

        $action->handle($service, $check);
    }
}

function webhookCalls(): array
{
    $sent = [];

    foreach (Http::recorded() as [$request]) {
        if ($request->url() === WEBHOOK) {
            $sent[] = $request->data();
        }
    }

    return $sent;
}

it('posts once when an incident opens, however long it stays open', function () {
    Http::fake(['*' => Http::response('', 200)]);

    walk($this->service, array_fill(0, 6, ServiceState::Down));

    $calls = webhookCalls();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['event'])->toBe('opened')
        ->and($calls[0]['service'])->toBe($this->service->name)
        ->and($calls[0]['severity'])->toBe('down');
});

it('posts on all three transitions and nothing else', function () {
    Http::fake(['*' => Http::response('', 200)]);

    walk($this->service, [
        ServiceState::Up,
        ServiceState::Degraded, ServiceState::Degraded,  // opened
        ServiceState::Down,                              // escalated
        ServiceState::Up, ServiceState::Up,              // resolved
    ]);

    expect(array_column(webhookCalls(), 'event'))
        ->toBe(['opened', 'escalated', 'resolved']);
});

it('never puts the incident reason or a hostname in the payload', function () {
    // A webhook usually lands in a chat room, so it gets the same treatment as the
    // public page: reasons are raw cURL strings carrying internal hostnames.
    Http::fake(['*' => Http::response('', 200)]);

    $service = Service::factory()->create(['url' => 'https://secret-host.internal/health']);

    $at = CarbonImmutable::now()->subMinutes(2);

    foreach ([0, 1] as $offset) {
        $check = Check::factory()->for($service)->create([
            'state' => ServiceState::Down,
            'ok' => false,
            'status_code' => null,
            'error' => 'cURL error 6: Could not resolve host: secret-host.internal',
            'checked_at' => $at->addMinutes($offset),
        ]);

        app(EvaluateIncident::class)->handle($service, $check);
    }

    $payload = json_encode(webhookCalls());

    foreach (['secret-host.internal', 'cURL', 'Could not resolve'] as $secret) {
        expect($payload)->not->toContain($secret);
    }
});

it('sends nothing when no webhook is configured', function () {
    config()->set('services.monitor.incident_webhook_url', null);
    Http::fake(['*' => Http::response('', 200)]);

    walk($this->service, [ServiceState::Up, ServiceState::Down, ServiceState::Down]);

    expect(webhookCalls())->toHaveCount(0);
});

it('does not let a dead webhook stop the checks', function () {
    Http::fake([
        '*chat.example*' => fn () => throw new ConnectionException('webhook unreachable'),
        '*' => Http::response('ok', 200),
    ]);

    $service = Service::factory()->create(['last_checked_at' => null]);

    // Two rounds so an incident opens and the webhook is actually attempted.
    $this->artisan('monitor:run --force')->assertSuccessful();
    $this->artisan('monitor:run --force')->assertSuccessful();

    expect(Check::where('service_id', $service->id)->count())->toBe(2);
});

it('stays quiet when a paused service has its incident closed as housekeeping', function () {
    // SaveService resolves an open incident when monitoring is paused, and that is not a
    // recovery, so it must not announce. The webhook inherits that by living in announce().
    Http::fake(['*' => Http::response('', 200)]);

    walk($this->service, [ServiceState::Up, ServiceState::Down, ServiceState::Down]);
    $opened = count(webhookCalls());

    app(SaveService::class)->handle(['is_active' => false], $this->service);

    expect(webhookCalls())->toHaveCount($opened);
});
