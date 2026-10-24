<?php

use App\Proposals\ReviewerWorkspaceController;
use Illuminate\Support\Facades\Route;

// Candidate root hook: include from existing owner proposal web/session/CSRF routes only.
Route::prefix('service-proposals/{token}/campus/workspace')->name('service-proposals.recipient.workspace.')->group(function (): void {
    Route::post('/projection', [ReviewerWorkspaceController::class, 'projection'])->name('projection');
    Route::post('/facts', [ReviewerWorkspaceController::class, 'facts'])->name('facts');
    Route::post('/commands', [ReviewerWorkspaceController::class, 'command'])->name('commands');
    Route::post('/command-status', [ReviewerWorkspaceController::class, 'status'])->name('command-status');
});
