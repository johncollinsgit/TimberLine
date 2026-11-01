<?php

use App\Http\Controllers\Mobile\PosController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos')->name('workspace.pos.')->group(function (): void {
    $read = ['abilities:mobile:read,payments:read'];
    $write = ['abilities:mobile:write,payments:write', 'throttle:30,1'];
    Route::get('/registers', [PosController::class, 'registers'])->middleware($read)->name('registers');
    Route::prefix('/registers/{register}')->where(['register' => '[a-f0-9]{32}'])->group(function () use ($read, $write): void {
        Route::get('/customer-enrollment', [PosController::class, 'customerEnrollmentSettings'])->middleware($read)->name('customer-enrollment.settings');
        Route::post('/customer-enrollment/settings', [PosController::class, 'configureCustomerEnrollment'])->middleware($write)->name('customer-enrollment.configure');
        Route::post('/customers/{profile}/enrollment', [PosController::class, 'enrollCustomer'])->whereNumber('profile')->middleware($write)->name('customer-enrollment.save');
        Route::get('/customers', [PosController::class, 'customers'])->middleware($read)->name('customers.search');
        Route::get('/customers/{profile}/commerce', [PosController::class, 'customerCommerce'])->whereNumber('profile')->middleware($read)->name('customers.commerce');
        Route::post('/customers', [PosController::class, 'createCustomer'])->middleware($write)->name('customers.create');
        Route::get('/customer-requests/{requestId}', [PosController::class, 'customerRequest'])->whereUuid('requestId')->middleware($read)->name('customers.request');
        Route::get('/catalog', [PosController::class, 'catalog'])->middleware($read)->name('catalog');
        Route::get('/checkout-rules/available', [PosController::class, 'availableCheckoutRules'])->middleware($read)->name('checkout-rules.available');
        Route::post('/checkout-rules/online/reconcile', [PosController::class, 'reconcileOnlineCheckoutRule'])->middleware($write)->name('checkout-rules.online.reconcile');
        Route::get('/tax-locations', [PosController::class, 'taxLocations'])->middleware($read)->name('tax-locations.index');
        Route::post('/tax-locations/preview', [PosController::class, 'previewTaxLocation'])->middleware($write)->name('tax-locations.preview');
        Route::post('/tax-locations/publish', [PosController::class, 'publishTaxLocation'])->middleware($write)->name('tax-locations.publish');
        Route::get('/checkout-rules/review', [PosController::class, 'reviewCheckoutRules'])->middleware($read)->name('checkout-rules.review');
        Route::post('/checkout-rules/publish', [PosController::class, 'publishCheckoutRules'])->middleware($write)->name('checkout-rules.publish');
        Route::post('/checkout-rules/online/review', [PosController::class, 'reviewOnlineDiscount'])->middleware($read)->name('checkout-rules.online.review');
        Route::post('/checkout-rules/online/publish', [PosController::class, 'publishOnlineDiscount'])->middleware($write)->name('checkout-rules.online.publish');
        Route::get('/checkout-rules', [PosController::class, 'checkoutRules'])->middleware($read)->name('checkout-rules.index');
        Route::post('/checkout-rules', [PosController::class, 'saveCheckoutRule'])->middleware($write)->name('checkout-rules.save');
        Route::delete('/checkout-rules/{rule}', [PosController::class, 'deleteCheckoutRule'])->where('rule', '[a-z0-9][a-z0-9_-]{0,63}')->middleware($write)->name('checkout-rules.delete');
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
            Route::get('/receipt/email', [PosController::class, 'receiptEmailStatus'])->middleware($read)->name('sales.receipt.email.status');
            Route::post('/receipt/email', [PosController::class, 'sendReceiptEmail'])->middleware($write)->name('sales.receipt.email.send');
            Route::post('/receipt/customer', [PosController::class, 'attachCustomer'])->middleware($write)->name('sales.receipt.customer');
        });
    });
});
