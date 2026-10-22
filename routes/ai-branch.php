<?php

use App\Http\Controllers\AiBranchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant.access', 'throttle:20,1'])->prefix('ai-branch')->name('ai-branch.')->group(function (): void {
    Route::get('/', [AiBranchController::class, 'index'])->name('index');
    Route::post('/checkout', [AiBranchController::class, 'checkout'])->middleware('throttle:3,1,ai-access-checkout:')->name('checkout');
    Route::post('/credit-checkout', [AiBranchController::class, 'creditCheckout'])->middleware('throttle:3,1,ai-credit-checkout:')->name('credit-checkout');
    Route::post('/agents/{agent}/run', [\App\Http\Controllers\AiWorkspaceWorkflowController::class, 'runAgent'])->whereUuid('agent')->name('agents.run');
    Route::post('/agents/{agent}/pause', [\App\Http\Controllers\AiWorkspaceWorkflowController::class, 'pauseAgent'])->whereUuid('agent')->name('agents.pause');
    Route::get('/workflows/customers', [\App\Http\Controllers\AiWorkspaceWorkflowController::class, 'customers'])->name('workflows.customers');
    Route::post('/workflows', [\App\Http\Controllers\AiWorkspaceWorkflowController::class, 'propose'])->name('workflows.propose');
    Route::post('/workflows/{action}/decision', [\App\Http\Controllers\AiWorkspaceWorkflowController::class, 'decision'])->whereNumber('action')->name('workflows.decision');
    Route::post('/workspace-memory', [\App\Http\Controllers\AiWorkspaceWorkflowController::class, 'memory'])->name('workspace-memory');
    Route::delete('/workspace-memory/{memory}', [\App\Http\Controllers\AiWorkspaceWorkflowController::class, 'forget'])->whereUuid('memory')->name('workspace-memory.forget');
    Route::get('/assistant', [\App\Http\Controllers\AiAssistantController::class, 'session'])->name('assistant.session');
    Route::post('/voice/settings', [\App\Http\Controllers\AiWorkspaceAudioController::class, 'settings'])->name('voice.settings');
    Route::post('/voice/transcribe', [\App\Http\Controllers\AiWorkspaceAudioController::class, 'transcribe'])->name('voice.transcribe');
    Route::post('/voice/speech', [\App\Http\Controllers\AiWorkspaceAudioController::class, 'speech'])->name('voice.speech');
    Route::post('/reports', [\App\Http\Controllers\AiReportController::class, 'store'])->middleware('throttle:5,1,ai-reports:')->name('assistant.reports.store');
    Route::get('/commitments/sources', [\App\Http\Controllers\AiCommitmentController::class, 'sources'])->name('assistant.commitments.sources');
    Route::get('/commitments', [\App\Http\Controllers\AiCommitmentController::class, 'index'])->name('assistant.commitments.index');
    Route::post('/commitments', [\App\Http\Controllers\AiCommitmentController::class, 'propose'])->middleware('throttle:20,1,ai-commitments:')->name('assistant.commitments.propose');
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
