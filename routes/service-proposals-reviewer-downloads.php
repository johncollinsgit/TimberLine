<?php

use App\Proposals\ReviewerWorkspaceDownloadsController;
use Illuminate\Support\Facades\Route;

// Inside the existing proposal owner web/runtime/CSRF group, before the shared session-lock loop.
Route::prefix('service-proposals/{token}/campus/workspace/downloads')->name('service-proposals.recipient.workspace.downloads.')->group(function (): void {
    foreach (['metadata', 'download'] as $action) {
        Route::post('/'.$action, [ReviewerWorkspaceDownloadsController::class, $action])->name($action)->block(10, 10);
    }
});
