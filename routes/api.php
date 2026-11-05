<?php

declare(strict_types=1);

use App\Http\Controllers\PublicStatusController;
use App\Http\Controllers\SpikeSignalController;
use App\Http\Middleware\AuthenticateSignalToken;
use Illuminate\Support\Facades\Route;

/*
 * Machine-readable status for other apps (ID-13). It lives here rather than in
 * web.php (STAT-20) because the web group would boot a session for every poll:
 * with SESSION_DRIVER=database that is a write into the same SQLite file the
 * scheduler is writing checks into, for a JSON response that has no user.
 *
 * The api group is stateless, so the prefix keeps the path at api/status. Other
 * apps already call that URL and it must not move.
 *
 * Throttled because hash_equals() protects the bearer token against timing
 * attacks but not against being guessed. A handful of machines poll this behind
 * a 30 second cache, so a tight limit costs legitimate callers nothing.
 */
Route::get('status', [PublicStatusController::class, 'index'])
    ->middleware('throttle:30,1')
    ->name('api.status');

/*
 * Symptom signals from other apps (SNAG-34): report spikes while the app still answers 200s,
 * which polling cannot see. Its own token, never the read token above.
 */
Route::post('signals/spikes', [SpikeSignalController::class, 'store'])
    ->middleware(['throttle:120,1', AuthenticateSignalToken::class])
    ->name('api.signals.spikes');
