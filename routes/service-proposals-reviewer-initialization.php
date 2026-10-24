<?php

use App\Proposals\ResponsePolicy;
use App\Proposals\ReviewerWorkspaceInitializationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', ResponsePolicy::class, 'throttle:6,1'])
    ->prefix('owner-workspaces/{workspace}/proposals/reviewer-initialization')->name('service-proposals.owner.initialization.')
    ->group(function (): void {
        Route::post('/{action}', [ReviewerWorkspaceInitializationController::class, 'handle'])
            ->whereIn('action', ['preview', 'apply', 'status'])->name('action');
    });
