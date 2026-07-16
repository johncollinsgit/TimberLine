<?php

use App\Http\Controllers\Mobile\EverbranchMobileAuthController;
use App\Http\Controllers\Mobile\EverbranchMobileClassSchedulingController;
use App\Http\Controllers\Mobile\EverbranchMobileController;
use App\Http\Controllers\Mobile\EverbranchMobileEstimatorController;
use App\Http\Controllers\Mobile\EverbranchMobileFieldServiceController;
use App\Http\Controllers\Mobile\EverbranchMobileLandlordController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')->name('mobile.v1.')->group(function (): void {
    Route::post('/auth/exchange', [EverbranchMobileAuthController::class, 'exchange'])
        ->middleware('throttle:20,1')
        ->name('auth.exchange');

    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
        Route::post('/auth/refresh', [EverbranchMobileAuthController::class, 'refresh'])->middleware('abilities:mobile:read')->name('auth.refresh');
        Route::post('/auth/logout', [EverbranchMobileAuthController::class, 'logout'])->middleware('abilities:mobile:read')->name('auth.logout');
        Route::get('/auth/sessions', [EverbranchMobileAuthController::class, 'sessions'])->middleware('abilities:mobile:read')->name('auth.sessions');
        Route::delete('/auth/sessions/{token}', [EverbranchMobileAuthController::class, 'revokeSession'])
            ->middleware('abilities:mobile:write')
            ->whereNumber('token')
            ->name('auth.sessions.revoke');

        Route::get('/workspaces', [EverbranchMobileController::class, 'workspaces'])->middleware('abilities:mobile:read')->name('workspaces');
        Route::get('/account/preferences', [EverbranchMobileController::class, 'preferences'])->middleware('abilities:mobile:read')->name('account.preferences');
        Route::patch('/account/preferences', [EverbranchMobileController::class, 'updatePreferences'])->middleware('abilities:mobile:write')->name('account.preferences.update');
        Route::post('/account/push-device', [EverbranchMobileController::class, 'registerPushDevice'])->middleware('abilities:mobile:write')->name('account.push-device.register');
        Route::delete('/account/push-device', [EverbranchMobileController::class, 'unregisterPushDevice'])->middleware('abilities:mobile:write')->name('account.push-device.unregister');

        Route::prefix('/landlord')->group(function (): void {
            Route::get('/bootstrap', [EverbranchMobileLandlordController::class, 'bootstrap'])->middleware('abilities:mobile:read')->name('landlord.bootstrap');
            Route::get('/tenants', [EverbranchMobileLandlordController::class, 'tenants'])->middleware('abilities:mobile:read')->name('landlord.tenants');
            Route::get('/tenants/{tenant}', [EverbranchMobileLandlordController::class, 'tenant'])->middleware('abilities:mobile:read')->whereNumber('tenant')->name('landlord.tenants.show');
            Route::patch('/access-requests/{accessRequest}', [EverbranchMobileLandlordController::class, 'decideAccess'])->middleware(['abilities:mobile:write', 'throttle:20,1'])->whereNumber('accessRequest')->name('landlord.access.decide');
            Route::patch('/support/{inquiry}', [EverbranchMobileLandlordController::class, 'updateInquiry'])->middleware(['abilities:mobile:write', 'throttle:30,1'])->whereNumber('inquiry')->name('landlord.support.update');
            Route::get('/tickets', [EverbranchMobileLandlordController::class, 'tickets'])->middleware('abilities:mobile:read')->name('landlord.tickets');
            Route::get('/tickets/{ticket}', [EverbranchMobileLandlordController::class, 'ticket'])->middleware('abilities:mobile:read')->whereNumber('ticket')->name('landlord.tickets.show');
            Route::patch('/tickets/{ticket}', [EverbranchMobileLandlordController::class, 'triageTicket'])->middleware(['abilities:mobile:write', 'throttle:60,1'])->whereNumber('ticket')->name('landlord.tickets.triage');
            Route::post('/tickets/{ticket}/reply', [EverbranchMobileLandlordController::class, 'replyTicket'])->middleware(['abilities:mobile:write', 'throttle:60,1'])->whereNumber('ticket')->name('landlord.tickets.reply');
        });

        Route::prefix('/workspaces/{tenant}')
            ->middleware('mobile.tenant')
            ->group(function (): void {
                Route::get('/bootstrap', [EverbranchMobileController::class, 'bootstrap'])->middleware('abilities:mobile:read')->name('workspace.bootstrap');
                Route::patch('/branding', [EverbranchMobileController::class, 'updateBranding'])->middleware(['abilities:mobile:write', 'throttle:20,1'])->name('workspace.branding.update');
                Route::get('/support-tickets', [EverbranchMobileController::class, 'supportTickets'])->middleware('abilities:mobile:read')->name('workspace.support.index');
                Route::post('/support-tickets', [EverbranchMobileController::class, 'createSupportTicket'])->middleware(['abilities:mobile:write', 'throttle:20,1'])->name('workspace.support.create');
                Route::get('/support-tickets/{ticket}', [EverbranchMobileController::class, 'supportTicket'])->middleware('abilities:mobile:read')->whereNumber('ticket')->name('workspace.support.show');
                Route::post('/support-tickets/{ticket}/reply', [EverbranchMobileController::class, 'replySupportTicket'])->middleware(['abilities:mobile:write', 'throttle:60,1'])->whereNumber('ticket')->name('workspace.support.reply');
                Route::get('/search', [EverbranchMobileController::class, 'search'])->middleware('abilities:mobile:read')->name('workspace.search');
                Route::get('/customers', [EverbranchMobileController::class, 'customers'])->middleware('abilities:mobile:read')->name('workspace.customers');
                Route::get('/customers/{customer}', [EverbranchMobileController::class, 'customer'])->middleware('abilities:mobile:read')->whereNumber('customer')->name('workspace.customers.show');
                Route::get('/work', [EverbranchMobileController::class, 'work'])->middleware('abilities:mobile:read')->name('workspace.work');
                Route::get('/work/{kind}/{resource}', [EverbranchMobileController::class, 'workDetail'])->middleware('abilities:mobile:read')->whereIn('kind', ['orders', 'jobs', 'clients'])->whereNumber('resource')->name('workspace.work.show');
                Route::get('/field-service', [EverbranchMobileFieldServiceController::class, 'index'])->middleware('abilities:mobile:read')->name('workspace.field-service.index');
                Route::get('/class-scheduling', [EverbranchMobileClassSchedulingController::class, 'index'])->middleware('abilities:mobile:read')->name('workspace.class-scheduling.index');
                Route::get('/class-scheduling/classes/{scheduledClass}', [EverbranchMobileClassSchedulingController::class, 'show'])->middleware('abilities:mobile:read')->whereNumber('scheduledClass')->name('workspace.class-scheduling.show');
                Route::post('/class-scheduling/enrollments/{enrollment}/reminders', [EverbranchMobileClassSchedulingController::class, 'storeReminder'])->middleware(['abilities:mobile:write', 'throttle:30,1'])->whereNumber('enrollment')->name('workspace.class-scheduling.reminders.store');
                Route::get('/field-service/my-day', [EverbranchMobileFieldServiceController::class, 'myDay'])->middleware('abilities:mobile:read')->name('workspace.field-service.my-day');
                Route::post('/field-service/jobs', [EverbranchMobileFieldServiceController::class, 'storeJob'])->middleware(['abilities:mobile:write', 'throttle:30,1'])->name('workspace.field-service.jobs.store');
                Route::get('/field-service/team', [EverbranchMobileFieldServiceController::class, 'team'])->middleware('abilities:mobile:read')->name('workspace.field-service.team');
                Route::get('/field-service/preferences', [EverbranchMobileFieldServiceController::class, 'preferences'])->middleware('abilities:mobile:read')->name('workspace.field-service.preferences');
                Route::patch('/field-service/preferences', [EverbranchMobileFieldServiceController::class, 'updatePreferences'])->middleware('abilities:mobile:write')->name('workspace.field-service.preferences.update');
                Route::get('/field-service/jobs/{job}', [EverbranchMobileFieldServiceController::class, 'show'])->middleware('abilities:mobile:read')->whereNumber('job')->name('workspace.field-service.jobs.show');
                Route::patch('/field-service/jobs/{job}', [EverbranchMobileFieldServiceController::class, 'updateJob'])->middleware('abilities:mobile:write')->whereNumber('job')->name('workspace.field-service.jobs.update');
                Route::post('/field-service/jobs/{job}/transitions', [EverbranchMobileFieldServiceController::class, 'transitionJob'])->middleware(['abilities:mobile:write', 'throttle:60,1'])->whereNumber('job')->name('workspace.field-service.jobs.transitions');
                Route::post('/field-service/jobs/{job}/comments', [EverbranchMobileFieldServiceController::class, 'comment'])->middleware(['abilities:mobile:write', 'throttle:60,1'])->whereNumber('job')->name('workspace.field-service.jobs.comments');
                Route::post('/field-service/jobs/{job}/photos', [EverbranchMobileFieldServiceController::class, 'uploadPhotos'])->middleware(['abilities:mobile:write', 'throttle:30,1'])->whereNumber('job')->name('workspace.field-service.jobs.photos');
                Route::post('/field-service/jobs/{job}/tasks', [EverbranchMobileFieldServiceController::class, 'storeTask'])->middleware(['abilities:mobile:write', 'throttle:60,1'])->whereNumber('job')->name('workspace.field-service.jobs.tasks');
                Route::patch('/field-service/jobs/{job}/tasks/{task}', [EverbranchMobileFieldServiceController::class, 'updateTask'])->middleware(['abilities:mobile:write', 'throttle:60,1'])->whereNumber('job')->whereNumber('task')->name('workspace.field-service.jobs.tasks.update');
                Route::get('/field-service/assets/{asset}', [EverbranchMobileFieldServiceController::class, 'downloadAsset'])->middleware('abilities:mobile:read')->whereNumber('asset')->name('workspace.field-service.assets.show');
                Route::get('/field-service/notifications', [EverbranchMobileFieldServiceController::class, 'notifications'])->middleware('abilities:mobile:read')->name('workspace.field-service.notifications.index');
                Route::post('/field-service/notifications/read-all', [EverbranchMobileFieldServiceController::class, 'readAllNotifications'])->middleware('abilities:mobile:write')->name('workspace.field-service.notifications.read-all');
                Route::post('/field-service/notifications/{notification}/read', [EverbranchMobileFieldServiceController::class, 'readNotification'])->middleware('abilities:mobile:write')->whereNumber('notification')->name('workspace.field-service.notifications.read');
                Route::get('/estimator', [EverbranchMobileEstimatorController::class, 'index'])->middleware('abilities:mobile:read')->name('workspace.estimator.index');
                Route::post('/estimator/drafts', [EverbranchMobileEstimatorController::class, 'store'])->middleware('abilities:mobile:write')->name('workspace.estimator.drafts.store');
                Route::get('/estimator/drafts/{estimate}', [EverbranchMobileEstimatorController::class, 'show'])->middleware('abilities:mobile:read')->whereNumber('estimate')->name('workspace.estimator.drafts.show');
                Route::patch('/estimator/drafts/{estimate}', [EverbranchMobileEstimatorController::class, 'update'])->middleware('abilities:mobile:write')->whereNumber('estimate')->name('workspace.estimator.drafts.update');
                Route::post('/estimator/drafts/{estimate}/duplicate', [EverbranchMobileEstimatorController::class, 'duplicate'])->middleware('abilities:mobile:write')->whereNumber('estimate')->name('workspace.estimator.drafts.duplicate');
                Route::post('/estimator/candidates/{candidate}/approve', [EverbranchMobileEstimatorController::class, 'approveCandidate'])->middleware('abilities:mobile:write')->whereNumber('candidate')->name('workspace.estimator.candidates.approve');
                Route::get('/messaging/conversations', [EverbranchMobileController::class, 'conversations'])->middleware('abilities:mobile:read')->name('workspace.messaging.conversations');
                Route::get('/messaging/conversations/{conversation}', [EverbranchMobileController::class, 'conversation'])->middleware('abilities:mobile:read')->whereNumber('conversation')->name('workspace.messaging.conversations.show');
                Route::get('/messaging/customers', [EverbranchMobileController::class, 'messageCustomers'])->middleware('abilities:mobile:read')->name('workspace.messaging.customers');
                Route::post('/messaging/conversations', [EverbranchMobileController::class, 'composeMessage'])->middleware(['abilities:mobile:write', 'throttle:30,1'])->name('workspace.messaging.compose');
                Route::post('/messaging/conversations/{conversation}/reply', [EverbranchMobileController::class, 'replyMessage'])->middleware(['abilities:mobile:write', 'throttle:60,1'])->whereNumber('conversation')->name('workspace.messaging.reply');
                Route::patch('/messaging/conversations/{conversation}', [EverbranchMobileController::class, 'conversationAction'])->middleware(['abilities:mobile:write', 'throttle:60,1'])->whereNumber('conversation')->name('workspace.messaging.action');
                Route::get('/modules/{moduleKey}', [EverbranchMobileController::class, 'moduleScreen'])
                    ->middleware('abilities:mobile:read')
                    ->name('workspace.modules.show');
                Route::post('/modules/{moduleKey}/actions/{actionKey}', [EverbranchMobileController::class, 'moduleAction'])
                    ->middleware(['abilities:mobile:write', 'throttle:30,1'])
                    ->name('workspace.modules.action');
                Route::get('/branches/{moduleKey}', [EverbranchMobileController::class, 'moduleScreen'])
                    ->middleware('abilities:mobile:read')
                    ->name('workspace.branches.show');
                Route::post('/branches/{moduleKey}/actions/{actionKey}', [EverbranchMobileController::class, 'moduleAction'])
                    ->middleware(['abilities:mobile:write', 'throttle:30,1'])
                    ->name('workspace.branches.action');
                Route::get('/branches', [EverbranchMobileController::class, 'branches'])->middleware('abilities:mobile:read')->name('workspace.branches');
                Route::post('/branches/{moduleKey}/request', [EverbranchMobileController::class, 'requestBranch'])
                    ->middleware(['abilities:mobile:write', 'throttle:20,1'])
                    ->name('workspace.branches.request');
                Route::post('/branches/{moduleKey}/billing-handoff', [EverbranchMobileController::class, 'billingHandoff'])
                    ->middleware(['abilities:mobile:write', 'throttle:10,1'])
                    ->name('workspace.branches.billing');
            });
    });
});
