<?php

use App\Http\Controllers\SquareCustomerConnectionController;
use App\Http\Controllers\SquareCustomerOAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant.access'])->prefix('workspace/customers/square')->name('customers.square.')->group(function (): void {
    Route::get('/', [SquareCustomerConnectionController::class, 'index'])->name('index');
    Route::post('/', [SquareCustomerConnectionController::class, 'refresh'])->middleware('throttle:6,1')->name('refresh');
    Route::post('/reviews/{review}', [SquareCustomerConnectionController::class, 'review'])->whereNumber('review')->name('review');
    Route::get('/profiles/{profile}/history', [SquareCustomerConnectionController::class, 'history'])->whereNumber('profile')->middleware('throttle:60,1')->name('history');
    Route::post('/connect', [SquareCustomerOAuthController::class, 'start'])->middleware('throttle:6,1')->name('connect');
    Route::post('/disconnect', [SquareCustomerOAuthController::class, 'disconnect'])->middleware('throttle:6,1')->name('disconnect');
});

// The callback resolves the original tenant only from the consumed session/state.
Route::middleware(['auth', 'verified', 'throttle:10,1'])->get('/workspace/customers/square/callback', [SquareCustomerOAuthController::class, 'callback'])->name('customers.square.callback');
