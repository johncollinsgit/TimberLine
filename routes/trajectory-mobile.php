<?php

use App\Http\Controllers\Trajectory\MobileController;
use Illuminate\Support\Facades\Route;

Route::get('/trajectory/{surface}/manifest.webmanifest', [MobileController::class, 'manifest'])->whereIn('surface', ['personal', 'business']);
Route::get('/trajectory/{surface}/sw.js', [MobileController::class, 'worker'])->whereIn('surface', ['personal', 'business']);
Route::middleware(['auth', 'verified', 'throttle:120,1'])->group(function (): void {
    Route::get('/trajectory/{surface}/', [MobileController::class, 'page'])->whereIn('surface', ['personal', 'business']);
    Route::prefix('/trajectory/spaces/{space}')->whereNumber('space')->group(function (): void {
        Route::get('/notifications', [MobileController::class, 'inbox']);
        Route::patch('/notifications/preferences', [MobileController::class, 'preferences']);
        Route::patch('/notifications/{notice}/read', [MobileController::class, 'read'])->whereNumber('notice');
        Route::get('/push-subscriptions', [MobileController::class, 'subscriptionStatus']);
        Route::post('/push-subscriptions', [MobileController::class, 'subscribe'])->middleware('throttle:10,1');
        Route::delete('/push-subscriptions', [MobileController::class, 'unsubscribe']);
    });
});
