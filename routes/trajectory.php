<?php

use App\Http\Controllers\Trajectory\TrajectoryController as Trajectory;
use Illuminate\Support\Facades\Route;

Route::get('/trajectory/welcome', fn () => view('trajectory.welcome'))->name('trajectory.welcome');

Route::middleware(['auth', 'verified', 'throttle:120,1'])->prefix('trajectory')->group(function (): void {
    Route::get('/', [Trajectory::class, 'index'])->name('trajectory.index');
    Route::post('/invites/accept', [Trajectory::class, 'acceptInvite'])->name('trajectory.acceptInvite');
    Route::prefix('/spaces/{space}')->whereNumber('space')->group(function (): void {
        Route::get('/reconciliation', [Trajectory::class, 'reconciliation'])->name('trajectory.reconciliation');
        Route::post('/reconciliation', [Trajectory::class, 'reconcile'])->name('trajectory.reconcile');
        Route::get('/combined', [Trajectory::class, 'combined'])->name('trajectory.combined');
        Route::post('/sms/verify', [Trajectory::class, 'verifySms'])->name('trajectory.verifySms')->middleware('throttle:3,10');
        Route::post('/sms/confirm', [Trajectory::class, 'confirmSms'])->name('trajectory.confirmSms')->middleware('throttle:5,10');
        Route::patch('/review-email', [Trajectory::class, 'reviewEmailPreferences'])->name('trajectory.reviewEmailPreferences');
        Route::get('/dashboard', [Trajectory::class, 'dashboard'])->name('trajectory.dashboard');
        Route::post('/evidence', [Trajectory::class, 'evidence'])->name('trajectory.evidence');
        Route::post('/accounts', [Trajectory::class, 'account'])->name('trajectory.account');
        Route::patch('/accounts/{account}', [Trajectory::class, 'updateAccount'])->name('trajectory.updateAccount');
        Route::patch('/transactions/{transaction}', [Trajectory::class, 'classify'])->name('trajectory.classify');
        Route::post('/transactions/bulk-classify', [Trajectory::class, 'bulkClassify'])->name('trajectory.bulkClassify');
        Route::post('/transactions/{transaction}/undo', [Trajectory::class, 'undo'])->name('trajectory.undo');
        Route::post('/transactions/{transaction}/split', [Trajectory::class, 'split'])->name('trajectory.split');
        Route::post('/records', [Trajectory::class, 'saveRecord'])->name('trajectory.saveRecord');
        Route::patch('/records/{record}', [Trajectory::class, 'saveRecord'])->name('trajectory.updateRecord');
        Route::delete('/records/{record}', [Trajectory::class, 'archiveRecord'])->name('trajectory.archiveRecord');
        Route::post('/records/{record}/sell', [Trajectory::class, 'sellMetal'])->name('trajectory.sellMetal');
        Route::post('/imports/preview', [Trajectory::class, 'previewImport'])->name('trajectory.previewImport');
        Route::post('/imports/confirm', [Trajectory::class, 'confirmImport'])->name('trajectory.confirmImport');
        Route::post('/banks/link', [Trajectory::class, 'linkBank'])->name('trajectory.linkBank')->middleware('throttle:10,1');
        Route::post('/banks/exchange', [Trajectory::class, 'exchangeBank'])->name('trajectory.exchangeBank')->middleware('throttle:10,1');
        Route::post('/banks/{connection}/sync', [Trajectory::class, 'syncBank'])->name('trajectory.syncBank')->middleware('throttle:5,1');
        Route::delete('/banks/{connection}', [Trajectory::class, 'disconnectBank'])->name('trajectory.disconnectBank');
        Route::post('/invites', [Trajectory::class, 'invite'])->name('trajectory.invite');
        Route::delete('/members/{user}', [Trajectory::class, 'revokeMember'])->name('trajectory.revokeMember');
        Route::post('/links', [Trajectory::class, 'linkSpaces'])->name('trajectory.linkSpaces');
        Route::patch('/settings', [Trajectory::class, 'settings'])->name('trajectory.settings');
    });
});
