<?php

namespace App\Jobs;

use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Operations\OperationsSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncTeamOperations implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 170;

    public function __construct(public string $connectionId, public int $generation) {}

    public function handle(OperationsAccess $access, OperationsSync $sync): void
    {
        if (! config('team_operations.enabled')) {
            return;
        }
        $c = TeamProviderConnection::query()->find($this->connectionId);
        if (! $c || $c->generation !== $this->generation || $c->status !== 'connected' || $c->retry_at?->isFuture() || ($c->settings['selection_required'] ?? false)) {
            return;
        }
        $tenant = Tenant::query()->findOrFail($c->tenant_id);
        $user = User::query()->findOrFail($c->user_id);
        try {
            $access->area($tenant, $user, $c->area_id);
            if ($c->provider === 'sheets') {
                $access->financial($tenant, $user);
            }
            $watch = $c->settings['watch'] ?? [];
            if (in_array($c->provider, ['gmail', 'sheets']) && (isset($watch['id']) || config('team_operations.google.pubsub_topic')) && (int) ($watch['expires_at'] ?? 0) < now()->addHours(12)->timestamp * 1000) {
                try {
                    app(\App\Services\Team\Operations\OperationsWatches::class)->renew($c);
                } catch (\Throwable) { /* Reconciliation remains authoritative when renewal fails. */
                }
            }
            $start = microtime(true);
            $state = $sync->refresh($c);
            foreach (\App\Models\TeamOperationAttempt::where(['tenant_id' => $c->tenant_id, 'connection_id' => $c->id, 'user_id' => $c->user_id])->whereIn('kind', ['gmail.send', 'asana.create'])->whereIn('status', ['unknown', 'executing'])->where('updated_at', '<', now()->subMinutes(5))->limit(5)->get() as $attempt) {
                try {
                    app(\App\Services\Team\Operations\OperationsReconciliation::class)->attempt($tenant, $user, \App\Models\TeamArea::where('tenant_id', $tenant->id)->findOrFail($attempt->area_id), $attempt->id);
                } catch (\Throwable) { /* Keep the uncertain attempt; never resend it. */
                }
            }
            Log::info('team.operations.sync', ['tenant_id' => $c->tenant_id, 'connection_id' => $c->id, 'provider' => $c->provider, 'status' => $state['status'], 'duration_ms' => (int) ((microtime(true) - $start) * 1000), 'pages' => $state['pages']]);
        } catch (\Throwable $e) {
            Log::warning('team.operations.sync_failed', ['tenant_id' => $c->tenant_id, 'connection_id' => $c->id, 'provider' => $c->provider, 'failure_class' => class_basename($e)]);
        }
    }
}
