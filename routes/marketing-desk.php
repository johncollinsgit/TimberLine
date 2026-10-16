<?php

use App\Http\Controllers\MarketingDeskController;
use App\Http\Controllers\MarketingDeskMetaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant.access'])->prefix('marketing/desk')->name('marketing.desk.')->group(function (): void {
    Route::get('/', [MarketingDeskController::class, 'index'])->name('index');
    Route::post('/knowledge', [MarketingDeskController::class, 'knowledge'])->name('knowledge');
    Route::post('/knowledge/{id}/approve', [MarketingDeskController::class, 'approveKnowledge'])->name('knowledge.approve');
    Route::post('/chat', [MarketingDeskController::class, 'chat'])->middleware('throttle:20,1')->name('chat');
    Route::post('/posts/batch-approve', [MarketingDeskController::class, 'batchApprovePosts'])->name('posts.batch-approve');
    Route::post('/posts', [MarketingDeskController::class, 'savePost'])->name('posts');
    Route::put('/posts/{id}', [MarketingDeskController::class, 'savePost'])->name('posts.update');
    Route::post('/posts/{id}/approve', [MarketingDeskController::class, 'approvePost'])->name('posts.approve');
    Route::post('/budget', [MarketingDeskController::class, 'budget'])->name('budget');
    Route::post('/timezone', [MarketingDeskController::class, 'timezone'])->name('timezone');
    Route::post('/publications/{id}/reconcile', [MarketingDeskController::class, 'reconcilePublication'])->name('publications.reconcile');
    Route::post('/publications/{id}/insights', [MarketingDeskController::class, 'insights'])->name('insights');
    Route::post('/reply-policies/{id?}', [MarketingDeskController::class, 'policy'])->name('policies');
    Route::post('/conversations/{id}/control', [MarketingDeskController::class, 'control'])->name('control');
    Route::post('/conversations/{id}/reply', [MarketingDeskController::class, 'reply'])->name('reply');
    Route::post('/recipes', [MarketingDeskController::class, 'recipe'])->name('recipes');
    Route::post('/workflows/{id}/approve', [MarketingDeskController::class, 'approveWorkflow'])->name('workflows.approve');
    Route::post('/permissions', [MarketingDeskController::class, 'permission'])->name('permissions');
    Route::post('/milestones', [MarketingDeskController::class, 'milestone'])->name('milestones');
    Route::post('/facebook/{id}/disconnect', [MarketingDeskMetaController::class, 'disconnect'])->name('facebook.disconnect');
    Route::get('/facebook/connect', [MarketingDeskMetaController::class, 'connect'])->name('facebook.connect');
});
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/integrations/marketing-facebook/callback', [MarketingDeskMetaController::class, 'callback'])->name('marketing.desk.facebook.callback');
    Route::post('/integrations/marketing-facebook/select', [MarketingDeskMetaController::class, 'selectPage'])->name('marketing.desk.facebook.select');
});
Route::match(['GET', 'POST'], '/webhooks/marketing-facebook', [MarketingDeskMetaController::class, 'webhook'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])->middleware('throttle:120,1')->name('marketing.desk.facebook.webhook');
