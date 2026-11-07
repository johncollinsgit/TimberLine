<?php

use App\Http\Controllers\Mobile\EverbranchMobileTeamWorkController;
use App\Http\Middleware\EnsureTeamWorkMobileEnabled;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1/workspaces/{tenant}/team-work')->name('mobile.v1.team-work.')
    ->middleware(['auth:sanctum', 'mobile.tenant', EnsureTeamWorkMobileEnabled::class, \App\Http\Middleware\EnsureTeamWorkViewer::class, 'throttle:120,1'])
    ->group(function (): void {
        Route::middleware('abilities:mobile:read')->group(function (): void {
            Route::get('/events/{id}/intelligence', \App\Http\Controllers\TeamEventIntelligenceController::class)->whereUuid('id')->name('events.intelligence');
            foreach (['areas', 'records', 'prefill'] as $action) {
                Route::get('/operations/'.$action, \App\Http\Controllers\Mobile\TeamsCompanionController::class)->defaults('action', $action)->name('operations.'.$action);
            }
            Route::get('/operations/records/{id}', \App\Http\Controllers\Mobile\TeamsCompanionController::class)->whereUuid('id')->defaults('action', 'record')->name('operations.record');
            foreach (['weather', 'sales'] as $action) {
                Route::get('/events/{id}/'.$action, \App\Http\Controllers\Mobile\TeamsCompanionController::class)->whereUuid('id')->defaults('action', $action)->name('events.'.$action);
            }
            Route::get('/offline-lease', \App\Http\Controllers\Mobile\TeamsCompanionController::class)->defaults('action', 'lease')->name('lease');
            Route::get('/events/my-tasks', \App\Http\Controllers\TeamEventTasksController::class)->name('events.my-tasks');
            Route::get('/events/asana/tasks/{id}/{kind?}', \App\Http\Controllers\TeamEventTasksController::class)->where('id', '[0-9]+')->where('kind', 'task|stories|subtasks|attachments')->defaults('action', 'detail')->name('events.source');
            Route::get('/studio/preset', \App\Http\Controllers\TeamStudioController::class)->defaults('action', 'preset')->name('studio.preset');
            Route::get('/studio', \App\Http\Controllers\TeamStudioController::class)->name('studio');
            Route::get('/', [EverbranchMobileTeamWorkController::class, 'data'])->name('data');
            Route::get('/projects/{id}/overview', [EverbranchMobileTeamWorkController::class, 'overview'])->whereUuid('id')->name('projects.overview');
            Route::get('/projects/{id}', [EverbranchMobileTeamWorkController::class, 'project'])->whereUuid('id')->name('projects.show');
            Route::get('/projects/{id}/templates/{template}', [EverbranchMobileTeamWorkController::class, 'template'])->whereUuid(['id', 'template'])->name('templates.show');
            Route::get('/tasks/{id}', [EverbranchMobileTeamWorkController::class, 'task'])->whereUuid('id')->name('tasks.show');
            Route::get('/inbox', [EverbranchMobileTeamWorkController::class, 'inbox'])->name('inbox');
            Route::get('/tasks/{task}/files', [EverbranchMobileTeamWorkController::class, 'taskFiles'])->whereUuid('task')->name('files.index');
            Route::get('/files/{upload}/download', [EverbranchMobileTeamWorkController::class, 'downloadFile'])->whereUuid('upload')->name('files.download');
            Route::get('/tasks/{id}/field-jobs', [EverbranchMobileTeamWorkController::class, 'linkedJobs'])->whereUuid('id')->name('field-links.jobs');
            Route::get('/field-jobs/{job}/tasks', [EverbranchMobileTeamWorkController::class, 'linkedTasks'])->whereNumber('job')->name('field-links.tasks');
        });
        Route::middleware('abilities:mobile:write')->group(function (): void {
            Route::post('/operations/areas', \App\Http\Controllers\Mobile\TeamsCompanionController::class)->defaults('action', 'areas')->name('operations.areas.create');
            foreach (['definition', 'draft', 'preview', 'create', 'budget', 'mapping'] as $action) {
                Route::post('/studio/'.$action, \App\Http\Controllers\TeamStudioController::class)->defaults('action', $action)->name('studio.'.$action);
            }
            Route::post('/events/asana/changes', \App\Http\Controllers\TeamEventTasksController::class)->defaults('action', 'prepare')->name('events.changes');
            foreach (['dispatch', 'reconcile'] as $action) {
                Route::post('/events/asana/changes/{id}/'.$action, \App\Http\Controllers\TeamEventTasksController::class)->whereUuid('id')->defaults('action', $action)->name('events.'.$action);
            }
            Route::post('/commands', [EverbranchMobileTeamWorkController::class, 'command'])->name('commands');
            Route::post('/inbox/{id}', [EverbranchMobileTeamWorkController::class, 'updateInbox'])->whereUuid('id')->name('inbox.update');
            Route::post('/tasks/{task}/files', [EverbranchMobileTeamWorkController::class, 'beginFile'])->whereUuid('task')->name('files.begin');
            Route::put('/files/{upload}/chunks/{index}', [EverbranchMobileTeamWorkController::class, 'chunkFile'])->whereUuid('upload')->whereNumber('index')->name('files.chunk');
            Route::post('/files/{upload}/complete', [EverbranchMobileTeamWorkController::class, 'completeFile'])->whereUuid('upload')->name('files.complete');
            Route::delete('/files/{upload}', [EverbranchMobileTeamWorkController::class, 'cancelFile'])->whereUuid('upload')->name('files.cancel');
            Route::post('/tasks/{id}/field-jobs/{job}', [EverbranchMobileTeamWorkController::class, 'linkJob'])->whereUuid('id')->whereNumber('job')->name('field-links.link');
        });
    });
