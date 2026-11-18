<?php

use App\Http\Controllers\Trajectory\AnalysisController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'throttle:120,1'])->prefix('trajectory/spaces/{space}')->whereNumber('space')->group(function (): void {
    Route::get('/analysis', [AnalysisController::class, 'show']);
    Route::get('/budget-envelopes', [AnalysisController::class, 'budget']);
    Route::get('/visual-context', [AnalysisController::class, 'context']);
});
