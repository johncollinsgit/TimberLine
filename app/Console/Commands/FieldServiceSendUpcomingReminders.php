<?php

namespace App\Console\Commands;

use App\Models\FieldServiceJob;
use App\Services\FieldService\FieldServiceJobNotificationService;
use Illuminate\Console\Command;

class FieldServiceSendUpcomingReminders extends Command
{
    protected $signature = 'field-service:send-upcoming-reminders {--tenant= : Limit to a tenant slug}';

    protected $description = 'Queue idempotent Everbranch job reminders 24 hours and 2 hours before scheduled work.';

    public function handle(FieldServiceJobNotificationService $notifications): int
    {
        $now = now();
        $queued = 0;
        foreach ([1440, 120] as $offset) {
            $target = $now->copy()->addMinutes($offset);
            FieldServiceJob::query()
                ->when($this->option('tenant'), fn ($query, $slug) => $query->whereHas('tenant', fn ($tenants) => $tenants->where('slug', $slug)))
                ->whereIn('operational_status', ['scheduled', 'active', 'needs_details'])
                ->whereBetween('scheduled_for', [$target->copy()->subMinutes(8), $target->copy()->addMinutes(8)])
                ->with('tenant:id,slug')
                ->orderBy('id')
                ->chunkById(100, function ($jobs) use ($notifications, $offset, &$queued): void {
                    foreach ($jobs as $job) {
                        $queued += $notifications->notifyUpcomingJob($job, $offset)['push'];
                    }
                });
        }
        $this->line('queued='.$queued);

        return self::SUCCESS;
    }
}
