<?php

use App\Http\Controllers\HighLevel\FleetController;
use App\Http\Controllers\HighLevel\HighLevelController;
use App\Http\Middleware\HighLevelSession;
use App\Http\Middleware\HighLevelSurface;
use Illuminate\Support\Facades\Route;

// Dedicated surface: no Everbranch login, no third-party cookies, no implicit
// tenant context from the host, and no shared application navigation.
Route::prefix('crm/fleet')->name('highlevel.')->middleware([HighLevelSurface::class, 'throttle:120,1'])
    ->withoutMiddleware([\Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->group(function (): void {
            Route::get('/launch', [HighLevelController::class, 'launch'])->name('launch');
            Route::view('/guide', 'highlevel.guide')->name('guide');
            Route::get('/install', [HighLevelController::class, 'install'])->name('install');
            Route::get('/oauth/callback', [HighLevelController::class, 'callback'])->name('oauth.callback');
            Route::get('/session/challenge', [HighLevelController::class, 'challenge'])->middleware('throttle:30,1');
            Route::post('/session/exchange', [HighLevelController::class, 'exchange'])->middleware('throttle:30,1');
            Route::post('/webhooks/lifecycle', [HighLevelController::class, 'lifecycle'])->name('webhook');
            Route::post('/webhooks/bouncie', [HighLevelController::class, 'bouncieWebhook'])->name('bouncie.webhook');
            Route::get('/bouncie/launch', [FleetController::class, 'bouncieLaunch'])->name('bouncie.launch');
            Route::get('/bouncie/callback', [FleetController::class, 'bouncieCallback'])->name('bouncie.callback');
            Route::prefix('api')->middleware(HighLevelSession::class)->group(function (): void {
                Route::get('/bootstrap', [FleetController::class, 'bootstrap']);
                Route::get('/vehicles', [FleetController::class, 'bootstrap']);
                Route::get('/connection', [FleetController::class, 'bootstrap']);
                Route::get('/devices', [FleetController::class, 'devices']);
                Route::put('/devices', [FleetController::class, 'select']);
                Route::put('/settings', [FleetController::class, 'settings']);
                Route::post('/bouncie/connect', [FleetController::class, 'connect']);
                Route::post('/bouncie/disconnect', [FleetController::class, 'disconnect']);
                Route::post('/disconnect', [HighLevelController::class, 'disconnect']);
            });
        });
