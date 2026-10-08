<?php

namespace App\Console\Commands;

use App\Jobs\ProcessHighLevelWebhook;
use App\Models\HighLevel\EmbeddedSession;
use App\Models\HighLevel\OAuthState;
use App\Models\HighLevel\WebhookEvent;
use Illuminate\Console\Command;

class MaintainHighLevelFleet extends Command
{
    protected $signature = 'highlevel:fleet-maintain';

    protected $description = 'Recover accepted Fleet webhooks and prune expired embedded authorization records.';

    public function handle(): int
    {
        // Recovery inbox is durable even when initial queue publication fails.
        WebhookEvent::whereNull('processed_at')->where('received_at', '>', now()->subDays(2))
            ->where('updated_at', '<', now()->subMinutes(35))->where('attempts', '<', 24)
            ->limit(100)->get()->each(fn ($event) => ProcessHighLevelWebhook::dispatch($event->id)->onQueue(config('highlevel.queue')));
        // Never retain queued raw GPS past the product retention maximum.
        WebhookEvent::whereNull('processed_at')->where('received_at', '<', now()->subDays(30))
            ->update(['payload' => null, 'processed_at' => now(), 'error_code' => 'retention_expired']);
        OAuthState::where('expires_at', '<', now()->subDay())->delete();
        // Keep session rows until their OAuth tickets have expired and been pruned.
        EmbeddedSession::where('expires_at', '<', now()->subDays(2))->whereNotIn('id', OAuthState::whereNotNull('session_id')->select('session_id'))->delete();
        \App\Models\HighLevel\Installation::where('status', 'installed')->each(function ($install): void {
            $days = max(1, min(30, (int) app(\App\Services\FleetTracking\FleetTrackingAccessService::class)->settings($install->tenant)->retention_days));
            \App\Models\HighLevel\FleetOperationRecord::forTenantId($install->tenant_id)->whereIn('kind', ['trip', 'telemetry', 'job', 'job_source'])->where('event_at', '<', now()->subDays($days))->delete();
            app(\App\Services\HighLevel\FleetOperationsService::class)->maintenance($install);
        });
        \App\Models\HighLevel\FleetOperationRecord::whereIn('kind', ['trip', 'telemetry', 'job', 'job_source'])->where('event_at', '<', now()->subDays(30))->delete();
        $this->info('HighLevel Fleet maintenance completed.');

        return self::SUCCESS;
    }
}
