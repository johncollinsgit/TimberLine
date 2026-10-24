<?php

use App\Proposals\ReviewerWorkspaceCollaborationController;
use Illuminate\Support\Facades\Route;

// Root includes only within the existing owner proposal web/session/CSRF/runtime group.
Route::prefix('service-proposals/{token}/campus/workspace/collaboration')->name('service-proposals.recipient.workspace.collaboration.')->group(function (): void {
    Route::post('/show', [ReviewerWorkspaceCollaborationController::class, 'show'])->name('show');
    Route::post('/comment', [ReviewerWorkspaceCollaborationController::class, 'comment'])->name('comment');
    Route::post('/status', [ReviewerWorkspaceCollaborationController::class, 'status'])->name('status');
});
