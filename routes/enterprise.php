<?php

use App\Enterprise\EnterpriseController;
use App\Enterprise\ResolveWorkspace;
use App\Enterprise\SessionController;
use App\Http\Controllers\TeamFileController;
use App\Http\Controllers\TeamWorkController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [SessionController::class, 'create'])->name('login');
Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('/verify-email', fn () => response('A verified Enterprise account is required. Contact your organization administrator.', 403))->name('verification.notice');
Route::middleware(['auth', 'verified', ResolveWorkspace::class, \App\Http\Middleware\EnsureTeamWorkViewer::class, 'throttle:120,1'])->group(function (): void {
    Route::get('/', [EnterpriseController::class, 'index'])->name('enterprise.index');
    Route::get('/teams', [EnterpriseController::class, 'index'])->name('team.index');
    Route::get('/enterprise/data', [EnterpriseController::class, 'data'])->name('enterprise.data');
    Route::post('/enterprise/commands', [EnterpriseController::class, 'command'])->name('enterprise.commands');
    Route::get('/enterprise/requests/{id}/history', [EnterpriseController::class, 'history'])->whereUuid('id')->name('enterprise.history');
    Route::get('/enterprise/planning', [\App\Enterprise\PlanningController::class, 'index'])->name('enterprise.planning');
    Route::post('/enterprise/planning', [\App\Enterprise\PlanningController::class, 'save'])->name('enterprise.planning.save');
    Route::post('/enterprise/ask', [\App\Enterprise\AskController::class, 'ask'])->middleware('throttle:20,1,enterprise-ask:')->name('enterprise.ask');
    Route::get('/enterprise/mailboxes', [\App\Enterprise\EmailController::class, 'mailboxes'])->name('enterprise.mailboxes');
    Route::post('/enterprise/email-drafts', [\App\Enterprise\EmailController::class, 'create'])->name('enterprise.email.create');
    Route::get('/enterprise/email-drafts/{id}', [\App\Enterprise\EmailController::class, 'preview'])->whereUuid('id')->name('enterprise.email.preview');
    Route::post('/enterprise/email-drafts/{id}/confirm', [\App\Enterprise\EmailController::class, 'confirm'])->whereUuid('id')->name('enterprise.email.confirm');
    Route::get('/enterprise/discussion/{kind}/{id}', [\App\Enterprise\DiscussionController::class, 'show'])->whereIn('kind', ['project', 'request'])->whereUuid('id')->name('enterprise.discussion');
    Route::post('/enterprise/discussion/{kind}/{id}/open', [\App\Enterprise\DiscussionController::class, 'open'])->whereIn('kind', ['project', 'request'])->whereUuid('id')->name('enterprise.discussion.open');
    Route::post('/enterprise/discussion/{kind}/{id}/comments', [\App\Enterprise\DiscussionController::class, 'comment'])->whereIn('kind', ['project', 'request'])->whereUuid('id')->name('enterprise.discussion.comment');
    Route::middleware(\App\Enterprise\CollaborationWrite::class)->group(function (): void {
        Route::post('/team/tasks/{task}/files', [TeamFileController::class, 'begin'])->whereUuid('task')->name('enterprise.files.begin');
        Route::put('/team/file-uploads/{upload}/chunks/{index}', [TeamFileController::class, 'chunk'])->whereUuid('upload')->whereNumber('index')->name('enterprise.files.chunk');
        Route::post('/team/file-uploads/{upload}/complete', [TeamFileController::class, 'complete'])->whereUuid('upload')->name('enterprise.files.complete');
        Route::post('/team/file-uploads/{upload}/cancel', [TeamFileController::class, 'cancel'])->whereUuid('upload')->name('enterprise.files.cancel');
    });
    // Reuse guarded native work/file routes; providers, billing, imports and grant mutations are excluded.
    Route::get('/team/data', [TeamWorkController::class, 'data'])->name('team.data');
    Route::get('/team/projects/{id}', [TeamWorkController::class, 'project'])->whereUuid('id')->name('team.projects.show');
    Route::get('/team/projects/{id}/overview', [TeamWorkController::class, 'overview'])->whereUuid('id')->name('team.projects.overview');
    Route::get('/team/tasks/{id}', [TeamWorkController::class, 'task'])->whereUuid('id')->name('team.tasks.show');
    Route::get('/team/tasks/{task}/files', [TeamFileController::class, 'index'])->whereUuid('task')->name('team.files.index');
    Route::get('/team/files/{upload}/download', [TeamFileController::class, 'download'])->whereUuid('upload')->name('team.files.download');
});
