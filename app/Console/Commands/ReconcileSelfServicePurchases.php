<?php

namespace App\Console\Commands;

use App\Models\StripeWebhookEvent;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingSubscription;
use App\Services\Billing\SelfServiceStandardPurchaseService;
use Illuminate\Console\Command;

class ReconcileSelfServicePurchases extends Command
{
    protected $signature = 'billing:reconcile-self-service {--limit=100}';

    protected $description = 'Retry durable signed purchase receipts against their original Stripe account.';

    public function handle(SelfServiceStandardPurchaseService $purchases): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $confirmed = 0;
        foreach (StripeWebhookEvent::query()->where('status', 'received_self_service')->oldest('id')->limit($limit)->get() as $receipt) {
            $result = $purchases->ingestVerifiedEvent((array) $receipt->payload);
            $confirmed += (int) ($result['ok'] ?? false);
        }
        // A missed renewal callback must not strand an already-paying customer.
        // Re-read the original account; never create a payment or a synthetic event.
        $subscriptions = TenantBillingSubscription::withoutGlobalScopes()->where('provider', 'stripe')->where('purchase_key', 'offer.standard')
            ->whereIn('status', ['active', 'past_due'])->where(function ($query): void {
                $query->where('current_period_ends_at', '<=', now()->addHours(6))->orWhere('status', 'past_due');
            })->limit($limit)->get();
        foreach ($subscriptions as $subscription) {
            $receipt = TenantBillingReceipt::withoutGlobalScopes()->where('tenant_id', $subscription->tenant_id)
                ->where('provider', 'stripe')->where('provider_subscription_id', $subscription->provider_subscription_reference)
                ->where('metadata->self_service_purchase_id', $subscription->metadata['self_service_purchase_id'] ?? '')->where('status', 'paid')->first();
            $event = $receipt ? StripeWebhookEvent::query()->where('event_id', $receipt->source_event_id)->where('tenant_id', $subscription->tenant_id)
                ->where('status', 'processed_self_service')->first() : null;
            if ($event) {
                $result = $purchases->ingestVerifiedEvent((array) $event->payload, refresh: true);
                $confirmed += (int) ($result['ok'] ?? false);
            }
        }
        $this->info('Processed '.$confirmed.' retained purchase receipts.');

        return self::SUCCESS;
    }
}
