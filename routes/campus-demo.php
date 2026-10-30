<?php

use App\Http\Controllers\LeesMcRaeCampusDemoController;
use App\Http\Middleware\EvaluateSchedulerHeartbeat;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// One public GET/HEAD document; no tenant selection, login or server mutations.
// Browser sessionStorage holds all fictional state. Keep normal host/security
// middleware, while this route alone avoids application-session/heartbeat writes.
Route::get('/platform/demos/lees-mcrae', LeesMcRaeCampusDemoController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, VerifyCsrfToken::class, EvaluateSchedulerHeartbeat::class])
    ->name('platform.lees-mcrae-campus-demo');
Route::get('/platform/demos/lees-mcrae/workflows', [LeesMcRaeCampusDemoController::class, 'workflow'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, VerifyCsrfToken::class, EvaluateSchedulerHeartbeat::class])
    ->name('platform.lees-mcrae-campus-workflows');

// Existing server/storage, with a closed public fictional API and no native identity.
$facilitiesMiddleware = [StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, VerifyCsrfToken::class, EvaluateSchedulerHeartbeat::class];
Route::get('/platform/demos/lees-mcrae/classic', LeesMcRaeCampusDemoController::class)
    ->withoutMiddleware($facilitiesMiddleware)->name('platform.lees-mcrae-classic-demo');
Route::get('/platform/demos/lees-mcrae/{file}', [\App\Http\Controllers\CampusFacilitiesDemoController::class, 'package'])
    ->where('file', 'sw\.js|manifest\.webmanifest')->withoutMiddleware($facilitiesMiddleware);
Route::get('/platform/demos/lees-mcrae/api/photos/{id}', [\App\Http\Controllers\CampusFacilitiesDemoController::class, 'photo'])
    ->whereUuid('id')->withoutMiddleware($facilitiesMiddleware);
Route::match(['GET', 'POST'], '/platform/demos/lees-mcrae/api/{endpoint}', [\App\Http\Controllers\CampusFacilitiesDemoController::class, 'api'])
    ->where('endpoint', 'tickets|reset|photos|campus|voice|voice/draft|participants|messaging/config|messaging/history|messaging/test')
    ->withoutMiddleware($facilitiesMiddleware);

Route::match(['GET', 'POST'], '/platform/demos/lees-mcrae/verify/{id}/{token}', [\App\Http\Controllers\CampusFacilitiesDemoController::class, 'verify'])
    ->whereUuid('id')->where('token', '[a-f0-9]{64}')->withoutMiddleware($facilitiesMiddleware);
