<?php

declare(strict_types=1);

use App\Services\ProbeTimings;

it('sums the hops of a redirect chain rather than keeping the last one', function () {
    // The bug in STAT-30: Guzzle fires on_stats per hop and the old code called put(),
    // so a service redirecting to /login reported only the final hop. Billr recorded
    // 488ms against a 6.48s curl -L on the same box.
    $timings = new ProbeTimings;

    $timings->record(1, 0.4);   // the redirect
    $timings->record(1, 6.08);  // the page it lands on

    expect($timings->secondsFor(1))->toBe(6.48);
});

it('keeps services apart', function () {
    $timings = new ProbeTimings;

    $timings->record(1, 0.4);
    $timings->record(2, 1.5);
    $timings->record(1, 0.1);

    expect($timings->secondsFor(1))->toBe(0.5)
        ->and($timings->secondsFor(2))->toBe(1.5);
});

it('reports nothing for a service that never transferred anything', function () {
    // The distinction toResult() depends on: never connected records 0ms, not the
    // timeout, so a DNS failure does not spike the latency chart.
    expect((new ProbeTimings)->secondsFor(99))->toBeNull();
});

it('counts a hop that reports no time as a hop', function () {
    // Transferred but untimed is not the same as never connected: the first must read
    // as 0ms measured, the second as no measurement at all.
    $timings = new ProbeTimings;

    $timings->record(1, null);

    expect($timings->secondsFor(1))->toBe(0.0);
});
