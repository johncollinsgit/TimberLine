<?php

use App\Enterprise\EnterpriseController;
use App\Enterprise\ResolveWorkspace;
use App\Enterprise\SessionController;
use App\Http\Controllers\TeamFileController;
use App\Http\Controllers\TeamWorkController;
use Illuminate\Support\Facades\Route;

// Generic install metadata does not start a web session or resolve operational records.
Route::withoutMiddleware([\Illuminate\Cookie\Middleware\EncryptCookies::class, \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \App\Http\Middleware\EnterpriseCsrfToken::class])->group(function (): void {
        Route::get('/enterprise/app.webmanifest', [\App\Enterprise\AppManifest::class, 'manifest'])->name('enterprise.app_manifest');
        Route::get('/enterprise/app-version', [\App\Enterprise\AppManifest::class, 'currentVersion'])->name('enterprise.app_version');
        Route::get('/enterprise/app-icon-{size}.png', [\App\Enterprise\AppManifest::class, 'icon'])->where('size', '180|192|512')->name('enterprise.app_icon');
    });

Route::get('/login', [SessionController::class, 'create'])->name('login');
Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('/verify-email', fn () => response('A verified Enterprise account is required. Contact your organization administrator.', 403))->name('verification.notice');
Route::prefix('/enterprise/trial')->middleware([\App\Enterprise\TrialBoundary::class, 'throttle:60,1,enterprise-trial:'])->group(function (): void {
    $controller = \App\Enterprise\Trial\TrialController::class;
    Route::get('/', [\App\Enterprise\TrialPageController::class, 'index'])->name('enterprise.trial');
    Route::get('/session', [$controller, 'show'])->name('enterprise.trial.session');
    Route::post('/start', [$controller, 'start'])->middleware('throttle:5,1,enterprise-trial-start:')->name('enterprise.trial.start');
    Route::post('/previews', [$controller, 'preview'])->name('enterprise.trial.preview');
    Route::post('/previews/{id}/confirm', [$controller, 'confirm'])->whereUuid('id')->name('enterprise.trial.confirm');
    Route::post('/links/{id}/revoke', [$controller, 'revokeLink'])->whereUuid('id')->name('enterprise.trial.revoke');
    Route::get('/wordmark.png', [$controller, 'wordmark'])->name('enterprise.trial.wordmark');
    Route::get('/task/{token}', [\App\Enterprise\TrialPageController::class, 'task'])->where('token', '[A-Za-z0-9_-]{43}')->name('enterprise.trial.task');
    Route::get('/task/{token}/data', [$controller, 'task'])->where('token', '[A-Za-z0-9_-]{43}')->name('enterprise.trial.task.data');
    Route::post('/task/{token}/update', [$controller, 'update'])->where('token', '[A-Za-z0-9_-]{43}')->name('enterprise.trial.task.update');
    Route::post('/task/{token}/uploads', [$controller, 'upload'])->where('token', '[A-Za-z0-9_-]{43}')->name('enterprise.trial.task.upload');
    Route::get('/task/{token}/files/{id}', [$controller, 'download'])->where('token', '[A-Za-z0-9_-]{43}')->whereUuid('id')->name('enterprise.trial.task.download');
});
// The one-use state resolves its own frozen tenant; never accept a callback tenant query.
Route::get('/enterprise/gmail/callback', [\App\Enterprise\GmailController::class, 'callback'])
    ->middleware([\App\Enterprise\GmailCallbackSession::class, 'throttle:10,1,enterprise-gmail-callback:'])->name('enterprise.gmail.callback');
Route::middleware(['auth', 'verified', ResolveWorkspace::class, \App\Http\Middleware\EnsureTeamWorkViewer::class, 'throttle:120,1'])->group(function (): void {
    Route::get('/', [EnterpriseController::class, 'index'])->name('enterprise.index');
    Route::get('/teams', [EnterpriseController::class, 'index'])->name('team.index');
    Route::get('/enterprise/data', [EnterpriseController::class, 'data'])->name('enterprise.data');
    Route::get('/enterprise/role-preview', \App\Enterprise\RolePreviewController::class)->name('enterprise.role_preview');
    Route::get('/enterprise/integrations', [\App\Enterprise\IntegrationController::class, 'status'])->name('enterprise.integrations');
    Route::get('/enterprise/files/{id}/caption', [\App\Enterprise\FileCaptionController::class, 'show'])->whereUuid('id')->name('enterprise.files.caption');
    Route::post('/enterprise/files/{id}/caption', [\App\Enterprise\FileCaptionController::class, 'save'])->whereUuid('id')->name('enterprise.files.caption.save');
    Route::get('/enterprise/search', \App\Enterprise\CampusSearchController::class)->name('enterprise.search');
    Route::get('/enterprise/roll-call', [\App\Enterprise\RollCallController::class, 'index'])->name('enterprise.roll_call');
    Route::get('/enterprise/meetings', [\App\Enterprise\RollCallController::class, 'meetings'])->name('enterprise.meetings');
    Route::post('/enterprise/meetings', [\App\Enterprise\RollCallController::class, 'save'])->name('enterprise.meetings.save');
    Route::get('/enterprise/meetings/{id}', [\App\Enterprise\RollCallController::class, 'show'])->whereUuid('id')->name('enterprise.meetings.show');
    Route::post('/enterprise/meetings/{id}/preview', [\App\Enterprise\RollCallController::class, 'preview'])->whereUuid('id')->name('enterprise.meetings.preview');
    Route::post('/enterprise/meetings/{id}/confirm', [\App\Enterprise\RollCallController::class, 'confirm'])->whereUuid('id')->name('enterprise.meetings.confirm');
    Route::get('/enterprise/meetings/{id}/pdf', [\App\Enterprise\RollCallController::class, 'pdf'])->whereUuid('id')->name('enterprise.meetings.pdf');
    Route::get('/enterprise/operations', [\App\Enterprise\OperationsController::class, 'index'])->name('enterprise.operations');
    Route::post('/enterprise/operations/commands', [\App\Enterprise\OperationsController::class, 'command'])->name('enterprise.operations.command');
    Route::get('/enterprise/operations/contractors/{id}/quality', [\App\Enterprise\OperationsController::class, 'quality'])->whereUuid('id')->name('enterprise.operations.quality');
    Route::get('/enterprise/operations/tasks/{id}', [\App\Enterprise\OperationsController::class, 'show'])->whereUuid('id')->name('enterprise.operations.show');
    Route::get('/enterprise/resources/catalog', [\App\Enterprise\ResourcesController::class, 'catalog'])->name('enterprise.resources.catalog');
    Route::get('/enterprise/resources', [\App\Enterprise\ResourcesController::class, 'index'])->name('enterprise.resources');
    Route::post('/enterprise/resources/commands', [\App\Enterprise\ResourcesController::class, 'command'])->name('enterprise.resources.command');
    Route::get('/enterprise/resources/{id}/history', [\App\Enterprise\ResourcesController::class, 'history'])->whereUuid('id')->name('enterprise.resources.history');
    Route::get('/enterprise/resources/{id}', [\App\Enterprise\ResourcesController::class, 'show'])->whereUuid('id')->name('enterprise.resources.show');
    Route::get('/enterprise/reports/quarterly', [\App\Enterprise\ReportExportController::class, 'show'])->name('enterprise.reports.quarterly');
    Route::get('/enterprise/reports/quarterly.csv', [\App\Enterprise\ReportExportController::class, 'csv'])->name('enterprise.reports.quarterly.csv');
    Route::get('/enterprise/reports/quarterly.json', [\App\Enterprise\ReportExportController::class, 'json'])->name('enterprise.reports.quarterly.json');
    Route::get('/enterprise/certificates', [\App\Enterprise\ContractorCertificatesController::class, 'index'])->name('enterprise.certificates');
    Route::get('/enterprise/certificates/reminders', [\App\Enterprise\ContractorCertificatesController::class, 'reminders'])->name('enterprise.certificates.reminders');
    Route::get('/enterprise/certificates/{id}/history', [\App\Enterprise\ContractorCertificatesController::class, 'history'])->whereUuid('id')->name('enterprise.certificates.history');
    Route::get('/enterprise/certificates/{id}', [\App\Enterprise\ContractorCertificatesController::class, 'show'])->whereUuid('id')->name('enterprise.certificates.show');
    Route::post('/enterprise/certificates/commands', [\App\Enterprise\ContractorCertificatesController::class, 'command'])->name('enterprise.certificates.command');
    Route::get('/enterprise/finance', [\App\Enterprise\FinancePacketsController::class, 'index'])->name('enterprise.finance');
    Route::get('/enterprise/finance/{id}/history', [\App\Enterprise\FinancePacketsController::class, 'history'])->whereUuid('id')->name('enterprise.finance.history');
    Route::get('/enterprise/finance/{id}', [\App\Enterprise\FinancePacketsController::class, 'show'])->whereUuid('id')->name('enterprise.finance.show');
    Route::post('/enterprise/finance/commands', [\App\Enterprise\FinancePacketsController::class, 'command'])->name('enterprise.finance.command');
    Route::get('/enterprise/preferences', [\App\Enterprise\WorkspacePreferencesController::class, 'show'])->name('enterprise.preferences');
    Route::post('/enterprise/preferences', [\App\Enterprise\WorkspacePreferencesController::class, 'save'])->name('enterprise.preferences.save');
    Route::get('/enterprise/requester', [\App\Enterprise\RequesterController::class, 'index'])->name('enterprise.requester');
    Route::post('/enterprise/requester', [\App\Enterprise\RequesterController::class, 'submit'])->name('enterprise.requester.submit');
    Route::get('/enterprise/requester/{id}', [\App\Enterprise\RequesterController::class, 'show'])->whereUuid('id')->name('enterprise.requester.show');
    Route::post('/enterprise/requester/{id}/updates', [\App\Enterprise\RequesterController::class, 'publish'])->whereUuid('id')->name('enterprise.requester.publish');
    Route::get('/projects/workspace', [\App\Enterprise\WorkspaceController::class, 'show'])->name('enterprise.workspace');
    Route::get('/enterprise/briefing', [\App\Enterprise\BriefingController::class, 'show'])->name('enterprise.briefing');
    Route::post('/enterprise/briefing/refresh', [\App\Enterprise\BriefingController::class, 'refresh'])->middleware('throttle:10,1,enterprise-briefing:')->name('enterprise.briefing.refresh');
    Route::get('/enterprise/briefing.pdf', [\App\Enterprise\BriefingController::class, 'pdf'])->name('enterprise.briefing.pdf');
    Route::get('/enterprise/workflows', [\App\Enterprise\WorkflowController::class, 'index'])->name('enterprise.workflows');
    Route::post('/enterprise/workflows', [\App\Enterprise\WorkflowController::class, 'save'])->name('enterprise.workflows.save');
    Route::post('/enterprise/workflows/preview', [\App\Enterprise\WorkflowController::class, 'preview'])->name('enterprise.workflows.preview');
    Route::post('/enterprise/workflows/propose', [\App\Enterprise\WorkflowController::class, 'propose'])->middleware('throttle:10,1,enterprise-propose:')->name('enterprise.workflows.propose');
    Route::get('/enterprise/workflows/{id}/data', [\App\Enterprise\WorkflowController::class, 'show'])->whereUuid('id')->name('enterprise.workflows.show');
    Route::post('/enterprise/workflows/{id}/activate', [\App\Enterprise\WorkflowController::class, 'activate'])->whereUuid('id')->name('enterprise.workflows.activate');
    Route::post('/enterprise/workflows/{id}/pause', [\App\Enterprise\WorkflowController::class, 'pause'])->whereUuid('id')->name('enterprise.workflows.pause');
    Route::post('/enterprise/workflows/{id}/runs', [\App\Enterprise\WorkflowController::class, 'start'])->whereUuid('id')->name('enterprise.workflows.start');
    Route::post('/enterprise/workflows/{id}/runs/{run}/advance', [\App\Enterprise\WorkflowController::class, 'advance'])->whereUuid('id')->whereUuid('run')->name('enterprise.workflows.advance');
    Route::post('/enterprise/commands', [EnterpriseController::class, 'command'])->name('enterprise.commands');
    Route::get('/enterprise/requests/{id}/history', [EnterpriseController::class, 'history'])->whereUuid('id')->name('enterprise.history');
    Route::get('/enterprise/planning', [\App\Enterprise\PlanningController::class, 'index'])->name('enterprise.planning');
    Route::post('/enterprise/planning', [\App\Enterprise\PlanningController::class, 'save'])->name('enterprise.planning.save');
    Route::post('/enterprise/ask', [\App\Enterprise\AskController::class, 'ask'])->middleware('throttle:20,1,enterprise-ask:')->name('enterprise.ask');
    Route::get('/enterprise/mailboxes', [\App\Enterprise\EmailController::class, 'mailboxes'])->name('enterprise.mailboxes');
    Route::get('/enterprise/gmail', [\App\Enterprise\GmailController::class, 'status'])->name('enterprise.gmail.status');
    Route::post('/enterprise/gmail/connect', [\App\Enterprise\GmailController::class, 'connect'])->middleware('throttle:5,1,enterprise-gmail-connect:')->name('enterprise.gmail.connect');
    Route::post('/enterprise/gmail/{id}/disconnect', [\App\Enterprise\GmailController::class, 'disconnect'])->whereUuid('id')->name('enterprise.gmail.disconnect');
    Route::post('/enterprise/email-drafts', [\App\Enterprise\EmailController::class, 'create'])->name('enterprise.email.create');
    Route::get('/enterprise/email-drafts/{id}', [\App\Enterprise\EmailController::class, 'preview'])->whereUuid('id')->name('enterprise.email.preview');
    Route::post('/enterprise/email-drafts/{id}/confirm', [\App\Enterprise\EmailController::class, 'confirm'])->whereUuid('id')->name('enterprise.email.confirm');
    Route::get('/enterprise/discussion/{kind}/{id}', [\App\Enterprise\DiscussionController::class, 'show'])->whereIn('kind', ['project', 'request', 'task'])->whereUuid('id')->name('enterprise.discussion');
    Route::post('/enterprise/discussion/{kind}/{id}/open', [\App\Enterprise\DiscussionController::class, 'open'])->whereIn('kind', ['project', 'request', 'task'])->whereUuid('id')->name('enterprise.discussion.open');
    Route::post('/enterprise/discussion/{kind}/{id}/comments', [\App\Enterprise\DiscussionController::class, 'comment'])->whereIn('kind', ['project', 'request', 'task'])->whereUuid('id')->name('enterprise.discussion.comment');
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
