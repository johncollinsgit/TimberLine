<?php

use App\Http\Controllers\Retail\RetailShowroomController as Retail;
use App\Http\Controllers\Retail\RetailVoiceController;
use Illuminate\Support\Facades\Route;

$host = config('tenancy.landlord.primary_host');
if (app()->environment('local')) {
    Route::get('/retail/local-review/{token}', \App\Http\Controllers\Retail\LocalReviewController::class)->where('token', '[A-Za-z0-9]{48}')->middleware(['signed', 'throttle:5,1'])->name('retail.local-review');
}
if (is_string($host) && $host !== '') {
    Route::domain($host)->prefix('retail')->middleware('throttle:120,1')->group(function () {
        Route::get('/preview/{slug}/{token}', [Retail::class, 'shared'])->where('token', '[A-Za-z0-9]{48}');
        Route::get('/showroom/api/bootstrap', [Retail::class, 'bootstrap']);
        Route::post('/showroom/api/{slug}/quote', [Retail::class, 'quote']);
        Route::middleware(['auth', 'verified'])->group(function () {
            Route::get('/showroom', [Retail::class, 'index'])->name('retail.showroom');
            Route::get('/showroom/pricing', [Retail::class, 'index'])->name('retail.pricing');
            Route::get('/showroom/assistant', [Retail::class, 'index'])->name('retail.assistant');
            Route::get('/showroom/api/demo/delivery/{reference}', [Retail::class, 'demoDeliveryStatus'])->where('reference', 'DEMO-MSG-[a-f0-9]{12}')->middleware('throttle:30,1,retail-demo-status:');
            Route::post('/showroom/api/demo/delivery', [Retail::class, 'demoDelivery'])->middleware('throttle:3,1,retail-demo-delivery:');
            Route::post('/showroom/api/avery/services', [Retail::class, 'averyServices'])->middleware('throttle:10,1,retail-services:');
            Route::get('/showroom/api/avery/handoff', [Retail::class, 'averyHandoff'])->middleware('throttle:10,1,retail-handoff:');
            Route::post('/showroom/api/signup', [Retail::class, 'signup'])->middleware('throttle:6,1,retail-signup:');
            Route::post('/showroom/api/assistant', [Retail::class, 'assistant'])->middleware('throttle:6,1,retail-ai:');
            Route::get('/showroom/api/voice/status', [RetailVoiceController::class, 'status'])->middleware('throttle:15,1,retail-voice-status:');
            Route::post('/showroom/api/voice/session', [RetailVoiceController::class, 'session'])->middleware('throttle:6,1,retail-voice-session:');
            Route::get('/showroom/outreach', [Retail::class, 'outreach'])->name('retail.outreach');
            Route::get('/showroom/concepts/{slug}', [Retail::class, 'concept']);
            Route::get('/staff/{slug}', [Retail::class, 'concept']);
            Route::get('/showroom/api/research', [Retail::class, 'research']);
            Route::put('/showroom/api/{slug}/cart', [Retail::class, 'cart']);
            Route::post('/showroom/api/{slug}/checkout', [Retail::class, 'checkout']);
            Route::get('/showroom/api/{slug}/staff', [Retail::class, 'staff']);
            Route::post('/showroom/api/{slug}/notes', [Retail::class, 'note']);
            Route::put('/showroom/api/{slug}/prospect', [Retail::class, 'prospect']);
            Route::post('/showroom/api/{slug}/share', [Retail::class, 'share']);
            Route::delete('/showroom/api/{slug}/share', [Retail::class, 'revoke']);
            Route::get('/showroom/api/{slug}/conversion', [Retail::class, 'conversion']);
        });
    });
}

if (is_string($host) && $host !== '') {
    Route::domain($host)->middleware(['auth', 'verified'])->group(function () {
        Route::get('/studio/', [\App\Http\Controllers\Retail\StudioGatewayController::class, 'page']);
        Route::match(['get', 'post', 'put', 'delete'], '/api/studio/{path?}', [\App\Http\Controllers\Retail\StudioGatewayController::class, 'proxy'])->where('path', '[A-Za-z0-9_/-]+')->middleware('throttle:60,1');
    });
}
