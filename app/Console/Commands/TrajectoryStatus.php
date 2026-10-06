<?php

namespace App\Console\Commands;

use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Notification;
use App\Models\Trajectory\Snapshot;
use App\Models\Trajectory\Transaction;
use Illuminate\Console\Command;

class TrajectoryStatus extends Command
{
    protected $signature = 'trajectory:status';

    protected $description = 'Report aggregate Trajectory source and delivery health without financial details.';

    public function handle(): int
    {
        $latest = Snapshot::where('observed_on', '>=', now()->subDays(2)->toDateString())->get();
        $this->line(json_encode([
            'enabled' => (bool) config('trajectory.enabled'),
            'checkout_enabled' => false,
            'connections_needing_attention' => Connection::where('status', 'needs_attention')->count(),
            'stale_connections' => Connection::where('status', '!=', 'disconnected')->where(fn ($q) => $q->whereNull('synced_at')->orWhere('synced_at', '<', now()->subDays(2)))->count(),
            'unreviewed_transactions' => Transaction::where('reviewed', false)->where('removed', false)->where('pending', false)->count(),
            'review_email_provider_ready' => app(\App\Services\Trajectory\ReviewEmailService::class)->deliveryReady(),
            'review_email_uncertain' => \App\Models\Trajectory\Event::where('action', 'review_email_uncertain')->count(),
            'review_email_unfinished' => \App\Models\Trajectory\Event::where('action', 'review_email_claimed')->where('created_at', '<', now()->subMinutes(10))->whereNotIn('id', \App\Models\Trajectory\Event::whereIn('action', ['review_email_accepted', 'review_email_uncertain'])->whereNotNull('record_id')->select('record_id'))->count(),
            'notification_failures' => Notification::whereIn('status', ['failed', 'undelivered'])->count(),
            'provisional_snapshots' => $latest->filter(fn ($s) => $s->data['forecast_provisional'] ?? true)->count(),
            'forecast_comparisons_available' => $latest->filter(fn ($s) => isset($s->data['forecast_error_cents']))->count(),
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
