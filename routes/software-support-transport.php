<?php

use App\SoftwareSupport\Transport\TestController;
use Illuminate\Support\Facades\Route;

// API lane intentionally has no web session/CSRF exception; production is hard-denied in controller.
Route::post('/software-support/test-campus', TestController::class)->middleware('throttle:30,1')->name('software-support.test-campus');
