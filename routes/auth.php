<?php

use App\Http\Controllers\Auth\IdOAuthController;
use Illuminate\Support\Facades\Route;

// Sign-in is exclusively via the id SSO (STAT-7). Hitting `login` starts the
// OAuth authorization-code flow; there is no local password path.
Route::middleware('guest')->group(function () {
    Route::get('login', [IdOAuthController::class, 'redirect'])->name('login');
    Route::get('auth/callback', [IdOAuthController::class, 'callback'])->name('auth.callback');
});

Route::post('logout', [IdOAuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');
