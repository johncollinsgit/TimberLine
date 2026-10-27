<?php

use App\Proposals\ResponsePolicy;
use App\Proposals\ReviewerWorkspaceMaintenanceController;
use Illuminate\Support\Facades\Route;

// Included inside the existing proposal runtime/web rails and before its session
// locking loop. No recipient, CLI, scheduler or native-workspace route is added.
Route::middleware(['auth', 'verified', ResponsePolicy::class, 'throttle:6,1'])
    ->prefix('owner-workspaces/{workspace}/proposals/reviewer-maintenance')->name('service-proposals.owner.maintenance.')
    ->group(function (): void {
        Route::post('/{kind}', [ReviewerWorkspaceMaintenanceController::class, 'prune'])
            ->whereIn('kind', ['graph', 'comments', 'quarantine'])->name('prune');
    });
