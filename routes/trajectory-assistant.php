<?php

use App\Http\Controllers\Trajectory\AssistantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'throttle:30,1'])->prefix('/trajectory/spaces/{space}')->whereNumber('space')->group(function (): void {
    Route::get('/assistant', [AssistantController::class, 'index']);
    Route::post('/assistant', [AssistantController::class, 'store']);
    Route::post('/assistant/{turn}/apply', [AssistantController::class, 'apply'])->whereNumber('turn');
    Route::post('/assistant/{turn}/undo', [AssistantController::class, 'undo'])->whereNumber('turn');
});
