<?php

use App\Proposals\ResponsePolicy;
use App\Proposals\ReviewerWorkspacePreparationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', ResponsePolicy::class, 'throttle:6,1'])
    ->prefix('owner-workspaces/{workspace}/proposals/reviewer-preparation')->name('service-proposals.owner.preparation.')
    ->group(function (): void {
        Route::post('/{action}', [ReviewerWorkspacePreparationController::class, 'handle'])
            ->whereIn('action', ['preview', 'apply', 'status'])->block(10, 10)->name('action');
    });
