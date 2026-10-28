<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileSubscriptionPilotReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $tenantId, public readonly int $receiptId) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(\App\Services\Subscriptions\SubscriptionPilotWebhookService $webhooks): void
    {
        $receipt = \App\Models\SubscriptionPilotEvent::query()->forTenantId($this->tenantId)->findOrFail($this->receiptId);
        $webhooks->process($receipt);
    }
}
