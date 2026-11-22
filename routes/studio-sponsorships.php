<?php

use App\Http\Controllers\StudioSponsorshipController as Studio;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1')->group(function () {
    Route::get('/studio/sponsorships', [Studio::class, 'offers'])->name('studio.offers');
    Route::post('/studio/sponsorships/{offer}/briefs', [Studio::class, 'submit'])->whereNumber('offer')->middleware('throttle:6,1')->name('studio.briefs.submit');
});
Route::middleware(['auth', 'verified', 'role:admin,manager,marketing_manager', 'tenant.access', 'module:managed_website', \App\Http\Middleware\UseConnectedWebsiteEditor::class])
    ->prefix('website/studio')->name('studio.admin.')->group(function () {
        Route::get('sponsorships', [Studio::class, 'editor'])->name('editor');
        Route::post('offers', [Studio::class, 'create'])->name('offers');
        Route::post('offers/{offer}/close', [Studio::class, 'close'])->whereNumber('offer')->name('close');
        Route::post('offers/{offer}/briefs/{brief}/approve', [Studio::class, 'approve'])->whereNumber(['offer', 'brief'])->name('approve');
        Route::post('offers/{offer}/briefs/{brief}/proof', [Studio::class, 'proof'])->whereNumber(['offer', 'brief'])->name('proof');
        Route::get('episodes/{episode}/rundown', [Studio::class, 'rundown'])->whereUuid('episode')->name('rundown');
    });
