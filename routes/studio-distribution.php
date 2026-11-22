<?php

use App\Http\Controllers\StudioDistributionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant.access'])->prefix('website/studio/distribution')->name('studio.distribution.')->group(function (): void {
    Route::get('/', [StudioDistributionController::class, 'index'])->name('index');
    Route::post('/meta', [StudioDistributionController::class, 'meta'])->middleware('throttle:20,1')->name('meta');
    Route::post('/youtube/connect', [StudioDistributionController::class, 'connect'])->middleware('throttle:10,1')->name('connect');
    Route::post('/youtube/upload', [StudioDistributionController::class, 'youtube'])->middleware('throttle:10,1')->name('youtube');
    Route::delete('/youtube/{channel}', [StudioDistributionController::class, 'disconnect'])->whereNumber('channel')->name('disconnect');
    Route::post('/youtube/resume/{receipt}', [StudioDistributionController::class, 'resume'])->name('resume');
});

Route::get('/integrations/studio/youtube/callback', [StudioDistributionController::class, 'callback'])
    ->middleware(['auth', 'verified', 'throttle:30,1'])->name('studio.distribution.callback');
