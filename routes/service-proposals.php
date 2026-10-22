<?php

use App\Proposals\OwnerController;
use App\Proposals\RecipientController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

RateLimiter::for('service-proposals-public', fn ($request) => Limit::perMinute(30)->by('sp-public:'.hash('sha256', $request->getHost().'|'.$request->ip())));
RateLimiter::for('service-proposals-unlock', fn ($request) => Limit::perMinute(6)->by('sp-unlock:'.hash('sha256', $request->getHost().'|'.$request->ip().'|'.$request->route('token'))));

Route::middleware(['auth', 'verified', \App\Proposals\ResponsePolicy::class])->prefix('owner-workspaces/{workspace}/proposals')->name('service-proposals.owner.')->group(function (): void {
    Route::get('/', [OwnerController::class, 'index'])->name('index');
    Route::get('/create', [OwnerController::class, 'edit'])->name('create');
    Route::post('/', [OwnerController::class, 'save'])->name('store');
    Route::post('/lees-mcrae-draft', [OwnerController::class, 'leesMcRae'])->name('lees-mcrae');
    Route::post('/draft-intake', [OwnerController::class, 'intake'])->name('intake');
    Route::post('/assets/{kind}/{assetId?}', [OwnerController::class, 'asset'])->name('asset');
    Route::get('/{draftId}/edit', [OwnerController::class, 'edit'])->name('edit');
    Route::put('/{draftId}', [OwnerController::class, 'save'])->name('update');
    Route::get('/{draftId}/preview', [OwnerController::class, 'preview'])->name('preview');
    Route::post('/{draftId}/publish', [OwnerController::class, 'publish'])->middleware('throttle:10,1')->name('publish');
    Route::post('/versions/{versionId}/revoke', [OwnerController::class, 'revoke'])->name('revoke');
});

Route::middleware([\App\Proposals\ResponsePolicy::class, 'throttle:service-proposals-public'])->prefix('service-proposals/{token}')->name('service-proposals.recipient.')->group(function (): void {
    Route::get('/', [RecipientController::class, 'show'])->name('show');
    Route::post('/unlock', [RecipientController::class, 'unlock'])->middleware('throttle:service-proposals-unlock')->name('unlock');
    Route::post('/selection', [RecipientController::class, 'reviewSelection'])->name('review');
    Route::post('/reviewer-name', [RecipientController::class, 'reviewerName'])->name('reviewer-name');
    Route::post('/comments', [RecipientController::class, 'comment'])->name('comment');
    Route::post('/usage-estimate', [RecipientController::class, 'estimate'])->name('estimate');
    Route::post('/request-agreement', [RecipientController::class, 'requestAgreement'])->name('request');
});
