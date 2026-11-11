<?php

use App\Http\Controllers\RentalWorkspaceController as Rentals;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant.access', 'throttle:120,1'])->prefix('rentals')->name('rentals.')->group(function (): void {
    Route::get('/', [Rentals::class, 'index'])->name('index');
    Route::get('/properties', [Rentals::class, 'properties'])->name('properties');
    Route::post('/properties', [Rentals::class, 'create'])->name('create');
    Route::prefix('/properties/{property}')->whereUuid('property')->group(function (): void {
        Route::get('/', [Rentals::class, 'data'])->name('data');
        Route::post('/commands', [Rentals::class, 'command'])->name('command');
        Route::get('/commands/{requestKey}', [Rentals::class, 'receipt'])->whereUuid('requestKey')->name('receipt');
        Route::post('/files', [Rentals::class, 'upload'])->middleware('throttle:15,1')->name('upload');
        Route::get('/files/{file}', [Rentals::class, 'file'])->whereUuid('file')->name('file');
    });
});
