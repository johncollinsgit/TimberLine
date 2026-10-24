<?php

use App\Proposals\ResponsePolicy;
use App\Proposals\ReviewerLegacyCaptureController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', ResponsePolicy::class, 'throttle:6,1'])
    ->prefix('owner-workspaces/{workspace}/proposals/legacy-capture')->name('service-proposals.owner.legacy-capture.')
    ->group(function (): void {
        Route::get('/{versionId}', [ReviewerLegacyCaptureController::class, 'show'])->whereUuid('versionId')->name('show');
        Route::post('/{versionId}/authorize', [ReviewerLegacyCaptureController::class, 'authorize'])->whereUuid('versionId')->name('authorize');
    });
