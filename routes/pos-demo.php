<?php

use App\Http\Controllers\PosBrowserDemoController;
use App\Http\Middleware\EvaluateSchedulerHeartbeat;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Public fictional content only: no tenant selection, auto-login, server-side demo
// mutations or provider endpoints. Browser sessionStorage is the entire ledger.
// Preserve the normal host/security middleware while avoiding app-session writes.
Route::get('/platform/demos/alpha-printing-pos', PosBrowserDemoController::class)
    // GET/HEAD-only content has no CSRF-protected mutations. Excluding cookie
    // issuance here avoids requiring a session merely to display the fixture.
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, VerifyCsrfToken::class, EvaluateSchedulerHeartbeat::class])
    ->name('platform.alpha-printing-pos-demo');
