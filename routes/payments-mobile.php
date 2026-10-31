<?php

use App\Http\Controllers\Mobile\EvergrovePaymentsController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/pos-mobile.php';

// Included only inside the authenticated, tenant-scoped mobile workspace group.
Route::prefix('payments')->name('workspace.payments.')->group(function (): void {
    $read = ['abilities:mobile:read,payments:read'];
    $write = ['abilities:mobile:write,payments:write', 'throttle:30,1'];
    Route::get('/registers', [EvergrovePaymentsController::class, 'registers'])->middleware($read)->name('registers');
    Route::prefix('/registers/{register}')->where(['register' => '[A-Za-z0-9_-]{1,100}'])->group(function () use ($read, $write): void {
        Route::get('/catalog', [EvergrovePaymentsController::class, 'catalog'])->middleware($read)->name('catalog');
        Route::post('/quotes', [EvergrovePaymentsController::class, 'quote'])->middleware($write)->name('quotes');
        Route::post('/attempts', [EvergrovePaymentsController::class, 'start'])->middleware($write)->name('attempts.start');
        Route::prefix('/attempts/{attempt}')->where(['attempt' => '[a-f0-9]{32}'])->group(function () use ($read, $write): void {
            Route::get('/', [EvergrovePaymentsController::class, 'inspect'])->middleware($read)->name('attempts.show');
            Route::post('/reconcile', [EvergrovePaymentsController::class, 'reconcile'])->middleware($write)->name('attempts.reconcile');
            Route::post('/refunds', [EvergrovePaymentsController::class, 'refund'])->middleware($write)->name('attempts.refunds');
        });
    });
});
