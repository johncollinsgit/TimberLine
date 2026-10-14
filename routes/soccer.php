<?php

use App\Http\Controllers\Soccer\SoccerWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant.access'])->prefix('soccer')->name('soccer.')->group(function () {
    Route::get('/release/{id}', function (\Illuminate\Http\Request $r, string $id) {
        $t = app(\App\Services\Soccer\SoccerAccess::class)->tenant($r, true);
        app(\App\Services\Soccer\SoccerStore::class)->row('releases', $t->id, $id);
        $source = rtrim(config('soccer.backend_origin'), '/').\Illuminate\Support\Facades\URL::temporarySignedRoute('soccer.release-data', now()->addMinutes(5), ['id' => $id, 'actor' => $r->user()->id], absolute: false);

        return redirect()->away(config('soccer.origin').'/api/portal/admin-pdf?source='.urlencode($source))->header('Referrer-Policy', 'no-referrer');
    })->name('release-export');
    Route::get('/group-messages', [SoccerWorkspaceController::class, 'groupMessages'])->name('group-messages');
    Route::post('/actions/{action}', [SoccerWorkspaceController::class, 'action'])->name('action');
    Route::get('/{section?}', [SoccerWorkspaceController::class, 'index'])->name('workspace');
});
