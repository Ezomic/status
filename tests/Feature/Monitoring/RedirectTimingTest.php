<?php

declare(strict_types=1);

use App\Models\Service;
use App\Services\HttpProbe;
use Illuminate\Support\Facades\Http;

/**
 * A real HTTP server, because faking cannot test this.
 *
 * Http::fake() never fires on_stats, so a faked redirect chain records 0ms and would pass
 * against the bug just as happily as against the fix. The only way to prove the recorded
 * time covers every hop is to make the probe follow a genuine redirect.
 *
 * Each hop sleeps 200ms, so the whole chain is about 400ms and the old behaviour of
 * keeping the last hop would record about 200ms.
 */
function startRedirectServer(): array
{
    $socket = socket_create_listen(0);
    socket_getsockname($socket, $address, $port);
    socket_close($socket);

    $router = sys_get_temp_dir().'/status-redirect-'.$port.'.php';
    file_put_contents($router, <<<'PHP'
        <?php
        usleep(200_000);

        if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/') {
            header('Location: /landed', true, 302);

            return;
        }

        echo 'ok';
        PHP);

    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        return [null, $router, null];
    }

    // Wait for it to accept connections rather than sleeping a guessed amount.
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

        if (is_resource($connection)) {
            fclose($connection);

            return [$process, $router, "http://127.0.0.1:{$port}/"];
        }

        usleep(50_000);
    }

    return [$process, $router, null];
}

function stopRedirectServer(mixed $process, string $router): void
{
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }

    @unlink($router);
}

it('records the whole redirect chain, not just the last hop', function () {
    [$process, $router, $url] = startRedirectServer();

    if ($url === null) {
        stopRedirectServer($process, $router);

        $this->markTestSkipped('Could not start a local HTTP server.');
    }

    // Narrowed to this server rather than switched off: every other suite keeps the
    // guard that stops a missed fake escaping to the real network.
    Http::allowStrayRequests([$url.'*']);

    $service = Service::factory()->create(['url' => $url, 'timeout_seconds' => 10]);

    $result = app(HttpProbe::class)->probe($service);

    stopRedirectServer($process, $router);

    // Two hops of 200ms each. Anything at or under a single hop means only the last one
    // was counted, which is the bug: Billr recorded 488ms against a 6.48s curl -L.
    expect($result->statusCode)->toBe(200)
        ->and($result->responseTimeMs)->toBeGreaterThan(350);
});
