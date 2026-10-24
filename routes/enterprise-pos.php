<?php

use App\Http\Controllers\Mobile\EverbranchMobileAuthController;
use App\Http\Controllers\PosAdminController;
use App\Http\Middleware\ResolvePosTenant;
use Illuminate\Support\Facades\Route;

// Loaded only by the explicitly enabled independent Enterprise composition.
require __DIR__.'/pos-staff.php';
Route::get('/mobile/authorize', [\App\Enterprise\PosMobileController::class, 'authorize'])->name('mobile.everbranch.authorize');
Route::middleware(['auth', 'verified', ResolvePosTenant::class])->prefix('/enterprise/pos')->name('enterprise.pos.')->group(function (): void {
    Route::get('/', [PosAdminController::class, 'index'])->name('index');
    Route::get('/report', [PosAdminController::class, 'report'])->name('report');
    Route::post('/items/{item}/adjust', [PosAdminController::class, 'adjust'])->where('item', '[a-f0-9]{32}')->name('stock.adjust');
    Route::post('/pause', [PosAdminController::class, 'pause'])->name('pause');
});
Route::middleware(['auth', 'verified', 'throttle:10,1'])->group(function (): void {
    Route::post('/pos/setup/square', [\App\Http\Controllers\PosSquareSetupController::class, 'start'])->name('pos.square.start');
    Route::get('/pos/setup/square/callback', [\App\Http\Controllers\PosSquareSetupController::class, 'callback'])->name('pos.square.callback');
});
Route::withoutMiddleware([\Illuminate\Cookie\Middleware\EncryptCookies::class, \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \App\Http\Middleware\EnterpriseCsrfToken::class])->prefix('/api')->group(function (): void {
        Route::post('/pos/square/webhook', \App\Http\Controllers\PosSquareWebhookController::class)->middleware('throttle:300,1');
        Route::prefix('/mobile/v1')->name('enterprise.pos.api.')->group(function (): void {
            Route::post('/auth/exchange', [EverbranchMobileAuthController::class, 'exchange'])->middleware('throttle:20,1');
            Route::middleware(['auth:sanctum'])->group(function (): void {
                Route::post('/auth/refresh', [EverbranchMobileAuthController::class, 'refresh']);
                Route::post('/auth/logout', [EverbranchMobileAuthController::class, 'logout']);
                Route::get('/workspaces', [\App\Enterprise\PosMobileController::class, 'workspaces'])->middleware('abilities:mobile:read');
                Route::prefix('/workspaces/{tenant}')->middleware(ResolvePosTenant::class)->group(function (): void {
                    require __DIR__.'/pos-mobile.php';
                });
            });
        });
    });
