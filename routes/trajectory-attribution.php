<?php

use App\Http\Controllers\Trajectory\AttributionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'throttle:120,1'])->prefix('trajectory/spaces/{space}')->whereNumber('space')->group(function (): void {
    Route::get('/attribution/definitions', [AttributionController::class, 'definitions']);
    Route::post('/attribution/definitions', [AttributionController::class, 'saveDefinition']);
    Route::patch('/attribution/definitions/{record}', [AttributionController::class, 'saveDefinition'])->whereNumber('record');
    Route::get('/transactions/{transaction}/attribution', [AttributionController::class, 'show'])->whereNumber('transaction');
    Route::put('/transactions/{transaction}/attribution', [AttributionController::class, 'store'])->whereNumber('transaction');
    Route::post('/transactions/{transaction}/attribution/undo', [AttributionController::class, 'undo'])->whereNumber('transaction');
});
