<?php

use App\Http\Controllers\IncidentController;
use App\Http\Controllers\PublicStatusController;
use App\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Machine-readable status for other apps (token-guarded, leak-safe). ID-13.
Route::get('api/status', [PublicStatusController::class, 'index'])->name('api.status');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('services', [ServiceController::class, 'index'])->name('services.index');
    Route::post('services', [ServiceController::class, 'store'])->name('services.store');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
    Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');
    Route::delete('services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

    Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
});

require __DIR__.'/settings.php';
