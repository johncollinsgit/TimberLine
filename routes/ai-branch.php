<?php

use App\Http\Controllers\AiBranchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant.access', 'throttle:20,1'])->prefix('ai-branch')->name('ai-branch.')->group(function (): void {
    Route::get('/', [AiBranchController::class, 'index'])->name('index');
    Route::get('/assistant', [\App\Http\Controllers\AiAssistantController::class, 'session'])->name('assistant.session');
    Route::post('/reports', [\App\Http\Controllers\AiReportController::class, 'store'])->name('assistant.reports.store');
    Route::get('/reports/{report}', [\App\Http\Controllers\AiReportController::class, 'print'])->whereUuid('report')->name('assistant.reports.print');
    Route::post('/actions/{action}/undo', [\App\Http\Controllers\AiAssistantController::class, 'webUndo'])->whereNumber('action')->name('assistant.undo');
    Route::post('/briefings', [AiBranchController::class, 'briefing'])->name('briefings');
    Route::post('/settings', [AiBranchController::class, 'settings'])->name('settings');
    Route::post('/conversations', [AiBranchController::class, 'start'])->name('start');
    Route::post('/conversations/{conversation}/messages', [AiBranchController::class, 'ask'])->whereNumber('conversation')->name('ask');
    Route::delete('/conversations/{conversation}', [AiBranchController::class, 'deleteConversation'])->whereNumber('conversation')->name('conversations.delete');
    Route::post('/memories', [AiBranchController::class, 'memory'])->name('memory');
    Route::delete('/memories/{memory}', [AiBranchController::class, 'deleteMemory'])->whereNumber('memory')->name('memory.delete');
    Route::post('/actions', [AiBranchController::class, 'propose'])->name('actions');
    Route::post('/actions/{action}/decision', [AiBranchController::class, 'decide'])->whereNumber('action')->name('decide');
});
