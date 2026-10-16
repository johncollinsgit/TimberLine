<?php

use App\Http\Controllers\AiBranchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant.access', 'throttle:20,1'])->prefix('ai-branch')->name('ai-branch.')->group(function (): void {
    Route::get('/', [AiBranchController::class, 'index'])->name('index');
    Route::post('/settings', [AiBranchController::class, 'settings'])->name('settings');
    Route::post('/conversations', [AiBranchController::class, 'start'])->name('start');
    Route::post('/conversations/{conversation}/messages', [AiBranchController::class, 'ask'])->whereNumber('conversation')->name('ask');
    Route::delete('/conversations/{conversation}', [AiBranchController::class, 'deleteConversation'])->whereNumber('conversation')->name('conversations.delete');
    Route::post('/memories', [AiBranchController::class, 'memory'])->name('memory');
    Route::delete('/memories/{memory}', [AiBranchController::class, 'deleteMemory'])->whereNumber('memory')->name('memory.delete');
    Route::post('/actions', [AiBranchController::class, 'propose'])->name('actions');
    Route::post('/actions/{action}/decision', [AiBranchController::class, 'decide'])->whereNumber('action')->name('decide');
});
