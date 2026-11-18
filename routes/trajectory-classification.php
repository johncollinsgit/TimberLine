<?php

use App\Http\Controllers\Trajectory\ClassificationSuggestionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'throttle:60,1'])->prefix('trajectory/spaces/{space}')->whereNumber('space')->group(function (): void {
    Route::post('/classification-suggestions', [ClassificationSuggestionController::class, 'preview']);
});
