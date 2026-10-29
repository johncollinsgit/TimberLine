<?php

use App\Http\Controllers\TeamFieldLinkController;
use App\Http\Controllers\TeamFileController;
use App\Http\Controllers\TeamWorkController;
use Illuminate\Support\Facades\Route;

Route::get('/team/asana/callback', [\App\Http\Controllers\TeamAsanaController::class, 'callback'])->middleware(['auth', 'verified', 'throttle:20,1'])->name('team.asana.callback');
Route::get('/team/integrations/{provider}/callback', [\App\Http\Controllers\TeamOperationsController::class, 'callback'])->whereIn('provider', ['asana', 'gmail', 'sheets'])->middleware(['auth', 'verified', 'throttle:20,1'])->name('team.operations.callback');

Route::middleware(['auth', 'verified', 'tenant.access', \App\Http\Middleware\EnsureTeamWorkViewer::class, 'throttle:120,1'])->group(function (): void {
    Route::get('/teams', [TeamWorkController::class, 'app'])->name('team.app');
    Route::prefix('team')->name('team.')->group(function (): void {
        Route::get('/search', \App\Http\Controllers\TeamSearchController::class)->withoutMiddleware('throttle:120,1')->middleware('throttle:team-search')->name('search');
        Route::controller(\App\Http\Controllers\TeamOperationsController::class)->name('operations.')->group(function (): void {
            Route::get('/areas', 'areas')->name('areas');
            Route::post('/areas', 'createArea')->name('areas.create');
            Route::put('/areas/{id}', 'updateArea')->whereUuid('id')->name('areas.update');
            Route::get('/areas/{id}/members', 'members')->whereUuid('id')->name('members');
            Route::get('/operations/records', 'records')->name('records');
            Route::get('/operations/work', 'personal')->name('personal');
            Route::post('/operations/records', 'save')->name('records.save');
            Route::get('/operations/records/{id}', 'record')->whereUuid('id')->name('records.show');
            Route::post('/operations/records/{id}/review', 'review')->whereUuid('id')->name('records.review');
            Route::get('/events', 'records')->name('events');
            Route::get('/events/prefill', 'prefill')->name('events.prefill');
            Route::get('/events/{id}/weather', 'weather')->whereUuid('id')->name('events.weather');
            Route::get('/events/{id}/sales', 'sales')->whereUuid('id')->name('events.sales');
            Route::get('/statistics', 'statistics')->name('statistics');
            Route::get('/communications', 'communications')->name('communications');
            Route::get('/communications/templates', 'templates')->name('communications.templates');
            Route::post('/communications/templates/preview', 'templatePreview')->name('communications.templates.preview');
            Route::post('/communications/automations/preview', 'automationPreview')->name('communications.automations.preview');
            Route::post('/communications/briefing', 'briefing')->name('briefing');
            Route::post('/communications', 'communicate')->name('communications.prepare');
            Route::post('/communications/{id}/dispatch', 'dispatch')->whereUuid('id')->name('communications.dispatch');
            Route::post('/communications/{id}/reconcile', 'reconcile')->whereUuid('id')->name('communications.reconcile');
            Route::get('/wholesale/{view}', 'wholesale')->name('wholesale');
            Route::post('/wholesale/{action}', 'wholesaleAction')->name('wholesale.action');
            Route::get('/integrations', 'connections')->name('connections');
            Route::post('/integrations/{provider}/connect', 'connect')->whereIn('provider', ['asana', 'gmail', 'sheets'])->name('connect');
            Route::delete('/integrations/{provider}/{id}', 'disconnect')->whereUuid('id')->name('disconnect');
            Route::put('/integrations/{provider}/{id}/selection', 'select')->whereUuid('id')->name('selection');
            Route::get('/integrations/{provider}/{id}/catalog', 'catalog')->whereUuid('id')->name('catalog');
            Route::get('/integrations/{provider}/{id}/records', 'sourceRead')->whereUuid('id')->name('source.read');
            Route::post('/integrations/{provider}/{id}/refresh', 'refresh')->whereUuid('id')->name('refresh');
            Route::post('/integrations/{provider}/{id}/watch', 'watch')->whereUuid('id')->name('watch');
            Route::post('/integrations/sheets/{id}/identities', 'sheetIds')->whereUuid('id')->name('sheet.identities');
            Route::post('/integrations/sheets/{id}/preview', 'sheetPreview')->whereUuid('id')->name('sheet.preview');
            Route::post('/operations/documents/{id}/review', 'reviewDocument')->whereUuid('id')->name('documents.review');
            Route::get('/operations/documents/{id}/download', 'download')->whereUuid('id')->name('documents.download');
        });
        Route::get('/preferences', [\App\Http\Controllers\TeamPreferencesController::class, 'show'])->name('preferences');
        Route::put('/preferences', [\App\Http\Controllers\TeamPreferencesController::class, 'update'])->name('preferences.update');
        Route::prefix('asana')->name('asana.')->controller(\App\Http\Controllers\TeamAsanaController::class)->group(function (): void {
            Route::get('/status', 'status')->name('status');
            Route::post('/connect', 'connect')->name('connect');
            Route::delete('/connection', 'disconnect')->name('disconnect');
            Route::get('/projects', 'projects')->name('projects');
            Route::get('/workspaces', 'workspaces')->name('workspaces');
            Route::put('/selection', 'selection')->name('selection');
            Route::get('/tasks', 'tasks')->name('tasks');
            Route::get('/tasks/{id}', 'task')->where('id', '[0-9]+')->name('task');
            Route::get('/tasks/{id}/{kind}', 'related')->where('id', '[0-9]+')->whereIn('kind', ['stories', 'subtasks', 'attachments'])->name('related');
            Route::get('/projects/{id}/sections', 'sections')->where('id', '[0-9]+')->name('sections');
        });
        Route::get('/', [TeamWorkController::class, 'index'])->name('index');
        Route::get('/workspaces', [TeamWorkController::class, 'workspaces'])->name('workspaces');
        Route::get('/data', [TeamWorkController::class, 'data'])->name('data');
        Route::get('/projects/{id}/overview', [TeamWorkController::class, 'overview'])->whereUuid('id')->name('projects.overview');
        Route::get('/projects/{id}', [TeamWorkController::class, 'project'])->whereUuid('id')->name('projects.show');
        Route::get('/projects/{id}/templates/{template}', [TeamWorkController::class, 'template'])->whereUuid(['id', 'template'])->name('templates.show');
        Route::get('/tasks/{id}', [TeamWorkController::class, 'task'])->whereUuid('id')->name('tasks.show');
        Route::get('/tasks/{id}/field-jobs', [TeamFieldLinkController::class, 'jobs'])->whereUuid('id')->name('field-links.jobs');
        Route::post('/tasks/{id}/field-jobs/{job}', [TeamFieldLinkController::class, 'link'])->whereUuid('id')->whereNumber('job')->name('field-links.link');
        Route::get('/field-jobs/{job}/tasks', [TeamFieldLinkController::class, 'tasks'])->whereNumber('job')->name('field-links.tasks');
        Route::post('/commands', [TeamWorkController::class, 'command'])->name('commands');
        Route::get('/inbox', [TeamWorkController::class, 'inbox'])->name('inbox');
        Route::post('/inbox/{id}', [TeamWorkController::class, 'updateInbox'])->whereUuid('id')->name('inbox.update');
        Route::get('/tasks/{task}/files', [TeamFileController::class, 'index'])->whereUuid('task')->name('files.index');
        Route::post('/tasks/{task}/files', [TeamFileController::class, 'begin'])->whereUuid('task')->name('files.begin');
        Route::put('/files/{upload}/chunks/{index}', [TeamFileController::class, 'chunk'])->whereUuid('upload')->whereNumber('index')->name('files.chunk');
        Route::post('/files/{upload}/complete', [TeamFileController::class, 'complete'])->whereUuid('upload')->name('files.complete');
        Route::delete('/files/{upload}', [TeamFileController::class, 'cancel'])->whereUuid('upload')->name('files.cancel');
        Route::get('/files/{upload}/download', [TeamFileController::class, 'download'])->whereUuid('upload')->name('files.download');
    });
});
