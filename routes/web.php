<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\Shipping\Orders as ShippingOrders;
use App\Livewire\Admin\Catalog as AdminCatalog;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Production OS
    |--------------------------------------------------------------------------
    */

    // Shipping
    Route::get('/shipping/orders', ShippingOrders::class)
        ->name('shipping.orders');

    // Pouring
    Route::view('/pouring', 'pouring.index')
        ->name('pouring.index');

    // Admin landing page
    Route::view('/admin', 'admin.index')
        ->name('admin.index');

    // ✅ Admin Catalog (Scents + Sizes)
    Route::get('/admin/catalog', AdminCatalog::class)
        ->name('admin.catalog');

    // Analytics
    Route::view('/analytics', 'analytics.index')
        ->name('analytics.index');
});

require __DIR__.'/settings.php';
