<?php

use App\Http\Controllers\Watershed\WatershedWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant.access'])->prefix('watershed')->name('watershed.')->group(function () {
    Route::get('/', [WatershedWorkspaceController::class, 'index'])->name('workspace');
    Route::post('/actions/{action}', [WatershedWorkspaceController::class, 'action'])->name('action');
    Route::get('/releases/{id}', [WatershedWorkspaceController::class, 'release'])->name('release');
});
