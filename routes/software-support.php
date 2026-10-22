<?php

use App\SoftwareSupport\Controller;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('workspaces/{workspace}/software-support')->name('software-support.')->group(function (): void {
    Route::get('/', [Controller::class, 'index'])->name('index');
    Route::post('/', [Controller::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::get('/{reference}', [Controller::class, 'show'])->whereUuid('reference')->name('show');
    Route::post('/{reference}/reply', [Controller::class, 'reply'])->whereUuid('reference')->middleware('throttle:30,1')->name('reply');
    Route::post('/{reference}/triage', [Controller::class, 'triage'])->whereUuid('reference')->middleware('throttle:30,1')->name('triage');
    Route::get('/{reference}/files/{file}', [Controller::class, 'download'])->whereUuid('reference')->whereUuid('file')->name('download');
});
