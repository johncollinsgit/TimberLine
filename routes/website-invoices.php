<?php

use App\Http\Controllers\WebsiteInvoiceAdminController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:admin,manager,marketing_manager', 'tenant.access', 'module:managed_website', \App\Http\Middleware\UseConnectedWebsiteEditor::class])
    ->prefix('website/invoices')->name('managed-website.invoices.')->group(function (): void {
        Route::get('/', [WebsiteInvoiceAdminController::class, 'index'])->name('index');
        Route::get('/create', [WebsiteInvoiceAdminController::class, 'create'])->name('create');
        Route::post('/', [WebsiteInvoiceAdminController::class, 'store'])->name('store');
        Route::get('/discounts', [WebsiteInvoiceAdminController::class, 'discounts'])->name('discounts');
        Route::post('/discounts', [WebsiteInvoiceAdminController::class, 'saveDiscount'])->name('discounts.store');
        Route::post('/discounts/{discount}/disable', [WebsiteInvoiceAdminController::class, 'disableDiscount'])->whereNumber('discount')->name('discounts.disable');
        Route::get('/{order}', [WebsiteInvoiceAdminController::class, 'show'])->whereNumber('order')->name('show');
        Route::get('/{order}/edit', [WebsiteInvoiceAdminController::class, 'edit'])->whereNumber('order')->name('edit');
        Route::put('/{order}', [WebsiteInvoiceAdminController::class, 'update'])->whereNumber('order')->name('update');
        Route::post('/{order}/issue', [WebsiteInvoiceAdminController::class, 'issue'])->whereNumber('order')->name('issue');
    });
