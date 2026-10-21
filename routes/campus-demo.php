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
