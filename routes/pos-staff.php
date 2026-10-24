<?php

use App\Http\Controllers\PosHandoffController;
use App\Http\Middleware\PosWebSession;
use App\Http\Middleware\ResolvePosTenant;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', ResolvePosTenant::class, PosWebSession::class])
    ->prefix(config('enterprise.runtime') ? '/enterprise/pos/staff/{tenant}' : '/pos/staff/{tenant}')
    ->name(config('enterprise.runtime') ? 'enterprise.pos.web.' : 'pos.web.')
    ->group(function (): void {
        Route::get('/', [PosHandoffController::class, 'index'])->name('index');
        Route::get('/register.js', [PosHandoffController::class, 'script'])->name('script');
        Route::prefix('/registers/{register}')->where(['register' => '[a-f0-9]{32}'])->group(function (): void {
            Route::get('/catalog', [PosHandoffController::class, 'catalog'])->middleware('throttle:60,1')->name('catalog');
            Route::post('/handoffs', [PosHandoffController::class, 'prepare'])->middleware('throttle:30,1')->name('prepare');
            Route::get('/requests/{requestId}', [PosHandoffController::class, 'byRequest'])->whereUuid('requestId')->name('request');
            Route::post('/requests/{requestId}/cancel', [PosHandoffController::class, 'cancelRequest'])->whereUuid('requestId')->middleware('throttle:30,1')->name('request.cancel');
            Route::prefix('/handoffs/{handoff}')->where(['handoff' => '[a-f0-9]{32}'])->group(function (): void {
                Route::get('/', [PosHandoffController::class, 'show'])->name('show');
                Route::post('/cancel', [PosHandoffController::class, 'cancel'])->middleware('throttle:30,1')->name('cancel');
                Route::get('/receipt', [PosHandoffController::class, 'receipt'])->name('receipt');
            });
        });
    });
