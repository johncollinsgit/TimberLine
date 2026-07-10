<?php

use App\Http\Controllers\Mobile\EverbranchMobileAuthController;
use App\Http\Controllers\Mobile\EverbranchMobileController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')->name('mobile.v1.')->group(function (): void {
    Route::post('/auth/exchange', [EverbranchMobileAuthController::class, 'exchange'])
        ->middleware('throttle:20,1')
        ->name('auth.exchange');

    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
        Route::post('/auth/refresh', [EverbranchMobileAuthController::class, 'refresh'])->middleware('abilities:mobile:read')->name('auth.refresh');
        Route::post('/auth/logout', [EverbranchMobileAuthController::class, 'logout'])->middleware('abilities:mobile:read')->name('auth.logout');
        Route::get('/auth/sessions', [EverbranchMobileAuthController::class, 'sessions'])->middleware('abilities:mobile:read')->name('auth.sessions');
        Route::delete('/auth/sessions/{token}', [EverbranchMobileAuthController::class, 'revokeSession'])
            ->middleware('abilities:mobile:write')
            ->whereNumber('token')
            ->name('auth.sessions.revoke');

        Route::get('/workspaces', [EverbranchMobileController::class, 'workspaces'])->middleware('abilities:mobile:read')->name('workspaces');

        Route::prefix('/workspaces/{tenant}')
            ->middleware('mobile.tenant')
            ->group(function (): void {
                Route::get('/bootstrap', [EverbranchMobileController::class, 'bootstrap'])->middleware('abilities:mobile:read')->name('workspace.bootstrap');
                Route::get('/search', [EverbranchMobileController::class, 'search'])->middleware('abilities:mobile:read')->name('workspace.search');
                Route::get('/modules/{moduleKey}', [EverbranchMobileController::class, 'moduleScreen'])
                    ->middleware('abilities:mobile:read')
                    ->name('workspace.modules.show');
                Route::post('/modules/{moduleKey}/actions/{actionKey}', [EverbranchMobileController::class, 'moduleAction'])
                    ->middleware(['abilities:mobile:write', 'throttle:30,1'])
                    ->name('workspace.modules.action');
                Route::get('/branches', [EverbranchMobileController::class, 'branches'])->middleware('abilities:mobile:read')->name('workspace.branches');
                Route::post('/branches/{moduleKey}/request', [EverbranchMobileController::class, 'requestBranch'])
                    ->middleware(['abilities:mobile:write', 'throttle:20,1'])
                    ->name('workspace.branches.request');
                Route::post('/branches/{moduleKey}/billing-handoff', [EverbranchMobileController::class, 'billingHandoff'])
                    ->middleware(['abilities:mobile:write', 'throttle:10,1'])
                    ->name('workspace.branches.billing');
            });
    });
});
