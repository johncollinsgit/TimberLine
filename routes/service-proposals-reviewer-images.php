<?php

use App\Proposals\ReviewerWorkspaceImagesController;
use Illuminate\Support\Facades\Route;

// Inside existing owner proposal web/runtime/CSRF group. No recipient scan route.
Route::prefix('service-proposals/{token}/campus/workspace/images')->name('service-proposals.recipient.workspace.images.')->group(function (): void {
    foreach (['metadata', 'thumbnail', 'preview'] as $action) {
        Route::post('/'.$action, [ReviewerWorkspaceImagesController::class, $action])->name($action)->block(10, 10);
    }
});
