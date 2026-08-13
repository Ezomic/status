<?php

declare(strict_types=1);

use App\Actions\Monitoring\RecordCheck;
use App\Models\Check;
use App\Models\Service;
use App\Services\Heartbeat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const HEARTBEAT = 'https://hc.example/ping/abc123';

beforeEach(function (): void {
    config()->set('services.monitor.heartbeat_url', HEARTBEAT);
});

it('pings after a healthy run', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    Service::factory()->create(['last_checked_at' => null]);

    $this->artisan('monitor:run')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === HEARTBEAT);
});

it('pings when nothing was due, because the runner still ran', function () {
    // A quiet minute must not look like a dead cron.
    Http::fake(['*' => Http::response('ok', 200)]);
    Service::factory()->create([
        'interval_seconds' => 3600,
        'last_checked_at' => now()->subMinute(),
    ]);

    $this->artisan('monitor:run')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === HEARTBEAT);
});

it('sends nothing when no heartbeat is configured', function () {
    config()->set('services.monitor.heartbeat_url', null);
    Http::fake(['*' => Http::response('ok', 200)]);
    Service::factory()->create(['last_checked_at' => null]);

    $this->artisan('monitor:run')->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'hc.example'));
});

it('withholds the ping when nothing could be recorded', function () {
    // A blind run, for example a locked database. The app cannot report this itself, so
    // silence towards the heartbeat is what reaches someone.
    Http::fake(['*' => Http::response('ok', 200)]);
    Service::factory()->create(['last_checked_at' => null]);

    // Force every recording attempt to throw.
    $this->mock(RecordCheck::class, function ($mock): void {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('database is locked'));
    });

    $this->artisan('monitor:run')->assertFailed();

    Http::assertNotSent(fn ($request) => $request->url() === HEARTBEAT);
});

it('still pings when one service fails but others record', function () {
    // One flaky service is already visible in the app; it must not raise the external
    // alarm about the runner being dead.
    Http::fake(['*' => Http::response('ok', 200)]);
    $good = Service::factory()->create(['name' => 'Good', 'last_checked_at' => null]);
    $bad = Service::factory()->create(['name' => 'Bad', 'last_checked_at' => null]);

    $this->mock(RecordCheck::class, function ($mock) use ($bad): void {
        $mock->shouldReceive('handle')->andReturnUsing(
            function (Service $service, ...$rest) use ($bad) {
                if ($service->id === $bad->id) {
                    throw new RuntimeException('nope');
                }

                return Check::factory()->for($service)->create();
            }
        );
    });

    $this->artisan('monitor:run')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === HEARTBEAT);
});

it('does not let an unreachable heartbeat fail the run', function () {
    Http::fake([
        '*hc.example*' => fn () => throw new ConnectionException('heartbeat unreachable'),
        '*' => Http::response('ok', 200),
    ]);
    Service::factory()->create(['last_checked_at' => null]);

    $this->artisan('monitor:run')->assertSuccessful();
});

it('does not let a slow heartbeat be retried into the next tick', function () {
    expect((new ReflectionClass(Heartbeat::class))->getConstant('TIMEOUT_SECONDS'))
        ->toBeLessThanOrEqual(10);
});
