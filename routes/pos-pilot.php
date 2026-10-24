<?php

use App\Http\Controllers\PosPilotController;
use App\Http\Middleware\PosWebSession;
use App\Http\Middleware\ResolvePosTenant;
use Illuminate\Support\Facades\Route;

// These routes use their own restricted session authority, never a shared owner login.
Route::middleware([ResolvePosTenant::class, PosWebSession::class])->prefix(config('enterprise.runtime') ? '/enterprise/pos/staff/{tenant}/terminal' : '/pos/staff/{tenant}/terminal')
    ->name(config('enterprise.runtime') ? 'enterprise.pos.web.terminal.' : 'pos.web.terminal.')->group(function (): void {
        Route::get('/', [PosPilotController::class, 'index'])->name('index');
        Route::get('/assets/{asset}', [PosPilotController::class, 'asset'])->whereIn('asset', ['register.js', 'register.css', 'trees.png'])->name('asset');
        Route::get('/state', [PosPilotController::class, 'state'])->name('state');
        Route::post('/owner/{action}', [PosPilotController::class, 'ownerAction'])->middleware('throttle:30,1')->name('owner');
        Route::post('/session/{action}', [PosPilotController::class, 'sessionAction'])->middleware('throttle:60,1')->name('session');
        Route::post('/events', [PosPilotController::class, 'createEvent'])->name('events.create');
        Route::post('/events/{event}/{action}', [PosPilotController::class, 'eventAction'])->where('event', '[a-f0-9]{32}')->name('events.action');
        Route::get('/activity/{format?}', [PosPilotController::class, 'report'])->whereIn('format', ['csv'])->name('activity');
        Route::post('/baskets', [PosPilotController::class, 'basket'])->name('baskets.save');
        Route::get('/baskets/{basket}', [PosPilotController::class, 'resumeBasket'])->where('basket', '[a-f0-9]{32}')->name('baskets.resume');
        Route::prefix('/registers/{register}')->where(['register' => '[a-f0-9]{32}'])->group(function (): void {
            Route::get('/catalog', [PosPilotController::class, 'catalog'])->name('catalog');
            Route::post('/quotes', [PosPilotController::class, 'quote'])->middleware('throttle:30,1')->name('quotes');
            Route::get('/requests/{requestId}', [PosPilotController::class, 'requestStatus'])->whereUuid('requestId')->name('request');
            Route::post('/requests/{requestId}/cancel', [PosPilotController::class, 'discardRequest'])->whereUuid('requestId')->name('discard');
            Route::post('/favorites', [PosPilotController::class, 'favorite'])->name('favorites');
            Route::get('/sales/{sale}/{action}', [PosPilotController::class, 'saleAction'])->where('sale', '[a-f0-9]{32}')->whereIn('action', ['status', 'receipt'])->name('sale.read');
            Route::post('/sales/{sale}/{action}', [PosPilotController::class, 'saleAction'])->where('sale', '[a-f0-9]{32}')->whereIn('action', ['collect', 'reconcile', 'cancel', 'retry', 'resume', 'refund', 'refund-status', 'refund-resume'])->middleware('throttle:30,1')->name('sale.write');
        });
    });
