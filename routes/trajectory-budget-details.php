<?php

use App\Http\Controllers\Trajectory\BudgetDetailController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'throttle:120,1'])->prefix('trajectory/spaces/{space}')->whereNumber('space')->group(function (): void {
    Route::get('/budget-records/{record}', [BudgetDetailController::class, 'show'])->whereNumber('record');
    Route::patch('/budget-records/{record}', [BudgetDetailController::class, 'update'])->whereNumber('record');
    Route::post('/budget-records/{record}/undo', [BudgetDetailController::class, 'undo'])->whereNumber('record');
});
