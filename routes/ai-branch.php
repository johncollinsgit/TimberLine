<?php

use App\Http\Controllers\AiBranchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant.access', 'throttle:20,1'])->prefix('ai-branch')->name('ai-branch.')->group(function (): void {
    Route::get('/', [AiBranchController::class, 'index'])->name('index');
    Route::get('/assistant', [\App\Http\Controllers\AiAssistantController::class, 'session'])->name('assistant.session');
    Route::post('/reports', [\App\Http\Controllers\AiReportController::class, 'store'])->middleware('throttle:5,1,ai-reports:')->name('assistant.reports.store');
    Route::post('/phone/practice', [\App\Http\Controllers\AiPhonePracticeController::class, 'run'])->middleware('throttle:10,1,ai-phone-practice:')->name('assistant.phone.practice');
    Route::get('/attention', [\App\Http\Controllers\AiAttentionController::class, 'index'])->name('assistant.attention.index');
    Route::post('/attention/{item}', [\App\Http\Controllers\AiAttentionController::class, 'webUpdate'])->whereNumber('item')->middleware('throttle:30,1,ai-attention:')->name('assistant.attention.update');
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
