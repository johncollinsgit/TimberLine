<?php

use App\Proposals\ReviewerWorkspaceFilesController;
use Illuminate\Support\Facades\Route;

// Include inside the existing owner proposal web/session/CSRF/runtime group, before its session block loop.
Route::prefix('service-proposals/{token}/campus/workspace/files')->name('service-proposals.recipient.workspace.files.')->group(function (): void {
    foreach (['show', 'begin', 'status', 'chunk', 'complete', 'cancel'] as $action) {
        Route::post('/'.$action, [ReviewerWorkspaceFilesController::class, $action])->name($action)->block(10, 10);
    }
});
