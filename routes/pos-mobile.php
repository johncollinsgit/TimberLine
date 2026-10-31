<?php

use App\Http\Controllers\Mobile\PosController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos')->name('workspace.pos.')->group(function (): void {
    $read = ['abilities:mobile:read,payments:read'];
    $write = ['abilities:mobile:write,payments:write', 'throttle:30,1'];
    Route::get('/registers', [PosController::class, 'registers'])->middleware($read)->name('registers');
    Route::prefix('/registers/{register}')->where(['register' => '[a-f0-9]{32}'])->group(function () use ($read, $write): void {
        Route::get('/customers', [PosController::class, 'customers'])->middleware($read)->name('customers.search');
        Route::post('/customers', [PosController::class, 'createCustomer'])->middleware($write)->name('customers.create');
        Route::get('/customer-requests/{requestId}', [PosController::class, 'customerRequest'])->whereUuid('requestId')->middleware($read)->name('customers.request');
        Route::get('/catalog', [PosController::class, 'catalog'])->middleware($read)->name('catalog');
        Route::post('/stock-counts', [PosController::class, 'countStock'])->middleware($write)->name('stock.counts');
        Route::get('/printing-quotes', [PosController::class, 'approvedQuotes'])->middleware($read)->name('printing-quotes');
        Route::post('/quotes', [PosController::class, 'quote'])->middleware($write)->name('quotes');
        Route::post('/authorize-device', [PosController::class, 'authorizeDevice'])->middleware($write)->name('device.authorize');
        Route::get('/requests/{requestId}', [PosController::class, 'requestStatus'])->whereUuid('requestId')->middleware($read)->name('requests.show');
        Route::post('/requests/{requestId}/discard', [PosController::class, 'discard'])->whereUuid('requestId')->middleware($write)->name('requests.discard');
        Route::prefix('/sales/{sale}')->where(['sale' => '[a-f0-9]{32}'])->group(function () use ($read, $write): void {
            Route::get('/', [PosController::class, 'show'])->middleware($read)->name('sales.show');
            Route::post('/collect', [PosController::class, 'start'])->middleware($write)->name('sales.collect');
            Route::post('/reconcile', [PosController::class, 'reconcile'])->middleware($write)->name('sales.reconcile');
            Route::post('/leave-for-review', [PosController::class, 'leaveForReview'])->middleware($write)->name('sales.leave-for-review');
            Route::post('/refunds', [PosController::class, 'refund'])->middleware($write)->name('sales.refund');
            Route::get('/receipt', [PosController::class, 'receipt'])->middleware($read)->name('sales.receipt');
            Route::post('/receipt/customer', [PosController::class, 'attachCustomer'])->middleware($write)->name('sales.receipt.customer');
        });
    });
});
