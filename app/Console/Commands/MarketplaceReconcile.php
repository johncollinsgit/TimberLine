<?php

namespace App\Console\Commands;

use App\Http\Controllers\Marketplace\ShopifyWebhookController;
use App\Models\Marketplace\Connection;
use App\Models\Marketplace\LoginLink;
use App\Models\Marketplace\WebhookEvent;
use App\Models\Marketplace\WorkspaceLink;
use App\Services\Marketplace\MarketplaceOrders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class MarketplaceReconcile extends Command
{
    protected $signature = 'marketplace:reconcile {--connection= : Exact saved connection UUID for a provider order read} {--order= : Shopify Order GID to reconcile} {--limit=25 : Maximum stored events to retry}';

    protected $description = 'Reconcile signed marketplace events and original-account orders; never replay a payment mutation';

    public function handle(ShopifyWebhookController $webhooks, MarketplaceOrders $orders): int
    {
        if (! Schema::hasTable('marketplace_webhook_events')) {
            return self::SUCCESS;
        }
        if (Schema::hasTable('marketplace_payment_events')) {
            foreach (\App\Models\Marketplace\PaymentEvent::whereIn('status', ['pending', 'failed'])->oldest('updated_at')->limit(max(1, min(100, (int) $this->option('limit'))))->get() as $event) {
                app(\App\Http\Controllers\Marketplace\StripeWebhookController::class)->process($event);
            }
            foreach (\App\Models\Marketplace\Checkout::where('provider', 'stripe')->whereNotNull('provider_session_ref')->whereIn('status', ['ready', 'unknown', 'sending', 'paid'])->oldest('updated_at')->limit(max(1, min(100, (int) $this->option('limit'))))->get() as $checkout) {
                try {
                    app(\App\Services\Marketplace\MarketplaceNativeCheckout::class)->reconcile($checkout);
                    $checkout->touch();
                } catch (\Throwable) {
                    $checkout->forceFill(['failure_code' => 'native_reconciliation_required'])->save();
                }
            }
        }
        foreach (\App\Models\Marketplace\Checkout::where('provider', 'shop_pay')->whereIn('status', ['wallet_submitting', 'wallet_submitted', 'wallet_unknown', 'paid'])->oldest('updated_at')->limit(max(1, min(100, (int) $this->option('limit'))))->get() as $checkout) {
            try {
                app(\App\Services\Marketplace\MarketplaceWallet::class)->reconcile($checkout);
                $checkout->touch();
            } catch (\Throwable) {
                $checkout->forceFill(['failure_code' => 'wallet_reconciliation_required'])->save();
            }
        }
        if ($this->option('order')) {
            $connection = Connection::query()->where('uuid', (string) $this->option('connection'))->first();
            if (! $connection) {
                $this->error('Provide the exact saved connection UUID.');

                return self::FAILURE;
            }
            try {
                $order = $orders->reconcile($connection, (string) $this->option('order'));
                $this->info($order ? 'Original merchant order reconciled.' : 'No marketplace checkout matched this order.');
            } catch (\Throwable) {
                $this->error('Provider reconciliation needs review. No mutation was retried.');

                return self::FAILURE;
            }
        }
        $events = WebhookEvent::query()->whereIn('status', ['pending', 'failed'])->orderBy('updated_at')->limit(max(1, min(100, (int) $this->option('limit'))))->get();
        foreach ($events as $event) {
            $webhooks->process($event);
        }
        LoginLink::query()->where('expires_at', '<', now()->subDay())->delete();
        WorkspaceLink::query()->where('expires_at', '<', now()->subDay())->delete();
        $this->line($events->count().' stored events checked.');

        return self::SUCCESS;
    }
}
