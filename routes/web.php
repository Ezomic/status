<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IncidentUpdateController;
use App\Http\Controllers\MaintenanceWindowController;
use App\Http\Controllers\PublicStatusPageController;
use App\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

// The shareable page, and what a signed-out visitor (or anyone just logged out) lands
// on. Outside the auth group by design (STAT-5).
Route::get('/', [PublicStatusPageController::class, 'index'])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('services', [ServiceController::class, 'index'])->name('services.index');
    Route::post('services', [ServiceController::class, 'store'])->name('services.store');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
    Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');
    Route::delete('services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

    Route::get('maintenance', [MaintenanceWindowController::class, 'index'])->name('maintenance.index');
    Route::post('maintenance', [MaintenanceWindowController::class, 'store'])->name('maintenance.store');
    Route::delete('maintenance/{window}', [MaintenanceWindowController::class, 'destroy'])->name('maintenance.destroy');

    Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::post('incidents/{incident}/acknowledge', [IncidentUpdateController::class, 'acknowledge'])->name('incidents.acknowledge');

    Route::post('incidents/{incident}/updates', [IncidentUpdateController::class, 'store'])->name('incident-updates.store');
    Route::put('incident-updates/{update}', [IncidentUpdateController::class, 'update'])->name('incident-updates.update');
    Route::delete('incident-updates/{update}', [IncidentUpdateController::class, 'destroy'])->name('incident-updates.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
