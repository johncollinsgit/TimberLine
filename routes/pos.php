<?php

use App\Http\Controllers\PointOfSaleController;
use Illuminate\Support\Facades\Route;

// The service additionally requires local/testing, explicit rollout and tenant access.
// This private route does not enable merchant collection or advertise a POS Branch.
Route::middleware(['auth', 'verified', 'tenant.access'])
    ->prefix('pos')->name('pos.')->group(function (): void {
        Route::get('/', [PointOfSaleController::class, 'setup'])->name('setup');
        Route::get('/registers/{register}', [PointOfSaleController::class, 'register'])->whereNumber('register')->name('register');
        Route::get('/registers/{register}/requests/{requestId}', [PointOfSaleController::class, 'requestStatus'])->whereNumber('register')->whereUuid('requestId')->name('request-status');
        Route::post('/registers/{register}/sales', [PointOfSaleController::class, 'start'])->whereNumber('register')->middleware('throttle:30,1')->name('start');
        Route::get('/sales/{attempt}', [PointOfSaleController::class, 'show'])->whereNumber('attempt')->name('show');
        Route::post('/sales/{attempt}/reconcile', [PointOfSaleController::class, 'reconcile'])->whereNumber('attempt')->middleware('throttle:30,1')->name('reconcile');
        Route::post('/sales/{attempt}/cancel', [PointOfSaleController::class, 'cancel'])->whereNumber('attempt')->middleware('throttle:30,1')->name('cancel');
        Route::post('/sales/{attempt}/simulate', [PointOfSaleController::class, 'simulate'])->whereNumber('attempt')->middleware('throttle:30,1')->name('simulate');
        Route::post('/sales/{attempt}/refunds', [PointOfSaleController::class, 'refund'])->whereNumber('attempt')->middleware('throttle:15,1')->name('refund');
        Route::post('/refunds/{refund}/reconcile', [PointOfSaleController::class, 'reconcileRefund'])->whereNumber('refund')->middleware('throttle:30,1')->name('refund.reconcile');
        Route::post('/refunds/{refund}/simulate', [PointOfSaleController::class, 'simulateRefund'])->whereNumber('refund')->middleware('throttle:30,1')->name('refund.simulate');
        Route::get('/sales/{attempt}/receipt', [PointOfSaleController::class, 'receipt'])->whereNumber('attempt')->name('receipt');
    });
