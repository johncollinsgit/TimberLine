<?php

use App\Http\Controllers\SquareCustomerConnectionController;
use App\Http\Controllers\SquareCustomerOAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant.access'])->prefix('workspace/customers/square')->name('customers.square.')->group(function (): void {
    Route::get('/', [SquareCustomerConnectionController::class, 'index'])->name('index');
    Route::post('/', [SquareCustomerConnectionController::class, 'refresh'])->middleware('throttle:6,1')->name('refresh');
    Route::post('/refresh-settings', [SquareCustomerConnectionController::class, 'refreshSettings'])->middleware('throttle:6,1')->name('refresh-settings');
    Route::post('/reconcile-references', [SquareCustomerConnectionController::class, 'reconcileReferences'])->middleware('throttle:6,1')->name('reconcile-references');
    Route::post('/reviews/{review}', [SquareCustomerConnectionController::class, 'review'])->whereNumber('review')->name('review');
    Route::get('/profiles/{profile}/history', [SquareCustomerConnectionController::class, 'history'])->whereNumber('profile')->middleware('throttle:60,1')->name('history');
    Route::post('/connect', [SquareCustomerOAuthController::class, 'start'])->middleware('throttle:6,1')->name('connect');
    Route::post('/disconnect', [SquareCustomerOAuthController::class, 'disconnect'])->middleware('throttle:6,1')->name('disconnect');
});

// The callback resolves the original tenant only from the consumed session/state.
Route::middleware(['auth', 'verified', 'throttle:10,1'])->get('/workspace/customers/square/callback', [SquareCustomerOAuthController::class, 'callback'])->name('customers.square.callback');

Route::middleware(['auth', 'verified', 'tenant.access'])->prefix('workspace/customers')->group(function (): void {
    Route::get('/reconciliation', [\App\Http\Controllers\CustomerSourceReconciliationController::class, 'index'])->name('customers.reconciliation');
    Route::post('/reconciliation/restore', [\App\Http\Controllers\CustomerSourceReconciliationController::class, 'restore'])->middleware('throttle:12,1')->name('customers.reconciliation.restore');
    Route::post('/reconciliation/purchase-place', [\App\Http\Controllers\CustomerSourceReconciliationController::class, 'mapPurchasePlace'])->middleware('throttle:12,1')->name('customers.reconciliation.purchase-place');
    Route::post('/reconciliation/template', [\App\Http\Controllers\CustomerSourceReconciliationController::class, 'copyTemplate'])->middleware('throttle:20,1')->name('customers.reconciliation.template');
    Route::get('/', \App\Http\Controllers\ConnectedCustomersController::class)->name('customers.directory');
    Route::get('/{profile}', \App\Http\Controllers\ConnectedCustomersController::class)->whereNumber('profile')->name('customers.profile');
});
