<?php

use App\Http\Controllers\TerminalWorkspaceController;
use App\Http\Middleware\TerminalSurface;
use Illuminate\Support\Facades\Route;

// Existing web authentication and CSRF stay intact. This is not a payment API.
Route::prefix('terminal')->name('terminal.')->middleware(TerminalSurface::class)->group(function (): void {
    Route::get('/', [TerminalWorkspaceController::class, 'entry'])->name('entry');
    Route::middleware(['auth', 'verified'])->group(function (): void {
        Route::get('/workspaces', [TerminalWorkspaceController::class, 'workspaces'])->name('workspaces');
        Route::post('/workspace', [TerminalWorkspaceController::class, 'select'])->middleware('throttle:20,1')->name('select');
        Route::post('/workspace/clear', [TerminalWorkspaceController::class, 'clear'])->middleware('throttle:20,1')->name('clear');
        Route::get('/register', [TerminalWorkspaceController::class, 'register'])->name('register');
    });
});
