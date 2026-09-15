<?php

namespace App\Console\Commands;

use App\Models\CustomerAccessRequest;
use App\Services\Onboarding\WholesaleApplicationDeliveryService;
use Illuminate\Console\Command;

class DeliverWholesaleApplications extends Command
{
    protected $signature = 'wholesale:deliver-applications {--limit=100}';

    protected $description = 'Retry due wholesale review and decision emails from durable delivery records.';

    public function handle(WholesaleApplicationDeliveryService $delivery): int
    {
        CustomerAccessRequest::wholesaleApplications()
            ->where(function ($query) {
                foreach (['review', 'decision'] as $kind) {
                    $query->orWhere(function ($due) use ($kind) {
                        $due->whereIn('metadata->delivery->'.$kind.'->status', ['pending', 'failed'])
                            ->where('metadata->delivery->'.$kind.'->next_attempt_at', '<=', now()->toIso8601String());
                    });
                }
            })
            ->orderBy('id')->limit(max(1, min(500, (int) $this->option('limit'))))->get(['id'])
            ->each(fn ($request) => $delivery->deliver($request->id));

        return self::SUCCESS;
    }
}
