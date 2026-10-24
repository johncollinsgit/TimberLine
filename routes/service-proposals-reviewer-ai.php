<?php

use App\Proposals\ReviewerAiController;
use Illuminate\Support\Facades\Route;

// Include only from the existing proposal web/session/CSRF runtime. Does not introduce auth-user authority.
Route::prefix('service-proposals/{token}/campus/ask/ai')->name('service-proposals.recipient.ai.')->group(function (): void {
    Route::post('/preview', [ReviewerAiController::class, 'preview'])->name('preview');
    Route::post('/confirm', [ReviewerAiController::class, 'confirm'])->name('confirm');
    Route::post('/cancel', [ReviewerAiController::class, 'cancel'])->name('cancel');
    Route::post('/status', [ReviewerAiController::class, 'status'])->name('status');
});
