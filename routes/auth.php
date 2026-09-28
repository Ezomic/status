<?php

use App\Http\Controllers\Auth\SignOutController;
use Illuminate\Support\Facades\Route;
use Thijssensoftware\IdClient\Http\Controllers\SsoController;

// Sign-in is exclusively via ID (STAT-7), through id-client (STAT-53), which registers
// auth/sso/redirect, auth/sso/callback and the back-channel auth/sso/logout itself.
// `login` is where the auth middleware sends a guest, so it starts the same flow.
Route::get('login', [SsoController::class, 'redirect'])
    ->middleware('guest')
    ->name('login');

Route::post('logout', SignOutController::class)
    ->middleware('auth')
    ->name('logout');
