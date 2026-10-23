<?php

use App\Proposals\CampusReviewController;
use App\Proposals\OwnerController;
use App\Proposals\PdfController;
use App\Proposals\RecipientController;
use Illuminate\Support\Facades\Route;

\App\Proposals\RateLimits::register();

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
    Route::get('/{draftId}/pdf', [PdfController::class, 'owner'])->middleware('throttle:6,1')->name('pdf');
    Route::post('/{draftId}/financial-planning', [OwnerController::class, 'financial'])->name('financial');
    Route::post('/{draftId}/publish', [OwnerController::class, 'publish'])->middleware('throttle:10,1')->name('publish');
    Route::get('/versions/{versionId}/activity', [\App\Proposals\ActivityController::class, 'owner'])->name('activity');
    Route::post('/versions/{versionId}/revoke', [OwnerController::class, 'revoke'])->name('revoke');
});

Route::post('service-proposals/{token}/activity', [\App\Proposals\ActivityController::class, 'capture'])
    ->middleware([\App\Proposals\ResponsePolicy::class, 'throttle:service-proposals-activity'])->name('service-proposals.recipient.activity');

Route::middleware([\App\Proposals\ResponsePolicy::class, \App\Proposals\RecordActivity::class, 'throttle:service-proposals-public'])->prefix('service-proposals/{token}')->name('service-proposals.recipient.')->group(function (): void {
    Route::get('/', [RecipientController::class, 'show'])->name('show');
    Route::get('/pdf', [PdfController::class, 'recipient'])->middleware('throttle:6,1')->name('pdf');
    Route::post('/unlock', [RecipientController::class, 'unlock'])->middleware('throttle:service-proposals-unlock')->name('unlock');
    Route::post('/selection', [RecipientController::class, 'reviewSelection'])->name('review');
    Route::post('/reviewer-name', [RecipientController::class, 'reviewerName'])->name('reviewer-name');
    Route::post('/comments', [RecipientController::class, 'comment'])->name('comment');
    Route::get('/campus/{page?}', [CampusReviewController::class, 'show'])->name('campus');
    Route::get('/campus/{page}/review', [CampusReviewController::class, 'review'])->name('campus.review');
    Route::post('/campus/{page}/reviewer-name', [CampusReviewController::class, 'name'])->name('campus.name');
    Route::post('/campus/{page}/comments', [CampusReviewController::class, 'comment'])->name('campus.comment');
    Route::post('/financial-planning', [RecipientController::class, 'financial'])->name('financial');
    Route::post('/usage-estimate', [RecipientController::class, 'estimate'])->name('estimate');
    Route::post('/request-agreement', [RecipientController::class, 'requestAgreement'])->name('request');
});
