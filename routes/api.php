<?php

declare(strict_types=1);

use App\Http\Controllers\PublicStatusController;
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
