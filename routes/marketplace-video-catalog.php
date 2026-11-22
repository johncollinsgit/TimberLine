<?php

use App\Http\Controllers\Marketplace\VideoCatalogController;
use Illuminate\Support\Facades\Route;

Route::get('/maker/{id}/video-catalog', [VideoCatalogController::class, 'show'])->whereNumber('id')->name('maker.video-catalog');
Route::post('/maker/{id}/video-catalog/shopify', [VideoCatalogController::class, 'authorizeShopify'])->whereNumber('id')->middleware('throttle:10,1')->name('maker.video-catalog.start');
Route::get('/integrations/video-catalog/shopify/callback', [VideoCatalogController::class, 'callback'])->middleware('throttle:30,1')->name('maker.video-catalog.callback');
Route::post('/maker/{id}/video-catalog/{source}/variants', [VideoCatalogController::class, 'select'])->whereNumber(['id', 'source'])->middleware('throttle:60,1')->name('maker.video-catalog.select');
Route::delete('/maker/{id}/video-catalog/{source}', [VideoCatalogController::class, 'disconnect'])->whereNumber(['id', 'source'])->name('maker.video-catalog.disconnect');
