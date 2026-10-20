<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Connection;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\WebhookEvent;
use App\Services\Marketplace\MarketplaceAccess;
use App\Services\Marketplace\MarketplaceOrders;
use App\Services\Marketplace\MarketplaceShopify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ShopifyWebhookController extends Controller
{
    public function receive(Request $request, string $connection)
    {
        $connection = Connection::query()->where('uuid', $connection)->firstOrFail();
        $raw = $request->getContent();
        $secret = $connection->credentials['webhook_secret'] ?? '';
        abort_unless($secret !== '' && strlen($raw) <= 2000000 && hash_equals($connection->shop_domain, (string) $request->header('X-Shopify-Shop-Domain')) && hash_equals(base64_encode(hash_hmac('sha256', $raw, $secret, true)), (string) $request->header('X-Shopify-Hmac-Sha256')), 401);
        $id = (string) $request->header('X-Shopify-Webhook-Id');
        $topic = (string) $request->header('X-Shopify-Topic');
        abort_unless(Str::isUuid($id) && in_array($topic, ['orders/create', 'order_transactions/create', 'shopify_payments/disputes/create', 'shopify_payments/disputes/update', 'orders/paid', 'orders/updated', 'refunds/create', 'fulfillments/create', 'fulfillments/update', 'inventory_levels/update', 'app/uninstalled'], true), 400);
        $payload = json_decode($raw, true, 128, JSON_BIGINT_AS_STRING);
        abort_unless(is_array($payload), 400);
        $event = WebhookEvent::query()->firstOrCreate(['connection_id' => $connection->id, 'event_id' => $id], ['topic' => $topic, 'payload_hash' => hash('sha256', $raw), 'payload' => $payload]);
        abort_unless(hash_equals($event->payload_hash, hash('sha256', $raw)) && $event->topic === $topic, 409);
        $this->process($event);

        return response()->json(['received' => true], $event->fresh()->status === 'failed' ? 202 : 200);
    }

    public function process(WebhookEvent $event): void
    {
        if ($event->status === 'processed') {
            return;
        }
        $walletConnection = Connection::find($event->connection_id);
        if ($walletConnection && ! empty($walletConnection->credentials['wallet'])) {
            try {
                $this->processWallet($event, $walletConnection);
            } catch (\Throwable) {
                $event->forceFill(['status' => 'failed', 'failure_code' => 'wallet_reconciliation_required'])->save();
            }

            return;
        }
        try {
            DB::transaction(function () use ($event): void {
                $event = WebhookEvent::query()->lockForUpdate()->findOrFail($event->id);
                if ($event->status === 'processed') {
                    return;
                }
                $connection = Connection::query()->findOrFail($event->connection_id);
                $payload = $event->payload;
                if ($event->topic === 'app/uninstalled') {
                    $settings = app(MarketplaceAccess::class)->settings();
                    if ((int) $settings->connection_id === (int) $connection->id) {
                        $settings->forceFill(['checkout_enabled' => false])->save();
                    }
                    $connection->forceFill(['verified_at' => null])->save();
                } elseif ($event->topic === 'inventory_levels/update') {
                    $inventoryId = 'gid://shopify/InventoryItem/'.($payload['inventory_item_id'] ?? '');
                    foreach (Listing::query()->where('connection_id', $connection->id)->where('shopify_inventory_gid', $inventoryId)->get() as $listing) {
                        app(MarketplaceShopify::class)->refreshInventory($listing);
                    }
                } else {
                    $id = str_starts_with($event->topic, 'orders/') ? ($payload['id'] ?? '') : ($payload['order_id'] ?? '');
                    if (! preg_match('/^[0-9]+$/', (string) $id)) {
                        throw new \RuntimeException('missing_order_identity');
                    }
                    $order = app(MarketplaceOrders::class)->reconcile($connection, 'gid://shopify/Order/'.$id);
                    if ($order && $order->test_mode && ! $order->review_required) {
                        $settings = app(MarketplaceAccess::class)->settings();
                        $settings->forceFill(['readiness' => [...($settings->readiness ?? []), 'signed_test_secret_hash' => hash('sha256', $connection->credentials['webhook_secret'])]])->save();
                    }
                }
                $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'failure_code' => null])->save();
            });
        } catch (Throwable) {
            $event->forceFill(['status' => 'failed', 'failure_code' => 'reconciliation_required'])->save();
        }
    }

    private function processWallet(WebhookEvent $event, Connection $connection): void
    {
        $payload = $event->payload;

        if ($event->topic === 'app/uninstalled') {
            $connection->forceFill(['verified_at' => null])->save();
        } elseif ($event->topic !== 'inventory_levels/update') {
            $id = str_starts_with($event->topic, 'orders/') ? ($payload['id'] ?? '') : ($payload['order_id'] ?? '');
            if (! preg_match('/^[0-9]+$/', (string) $id)) {
                throw new \RuntimeException('missing_wallet_order');
            }
            $gid = 'gid://shopify/Order/'.$id;
            $provider = app(MarketplaceOrders::class)->read($connection, $gid);
            $checkout = \App\Models\Marketplace\Checkout::where('provider', 'shop_pay')->where('connection_id', $connection->id)->where('uuid', $provider['sourceIdentifier'] ?? '')->first();
            if ($checkout) {
                $order = app(\App\Services\Marketplace\MarketplaceWallet::class)->reconcile($checkout);
                if ($order && str_starts_with($event->topic, 'shopify_payments/disputes/')) {
                    $order->forceFill(['review_required' => true, 'review_reason' => 'A Shop Pay payment dispute needs merchant review.'])->save();
                }
                if ($order && $order->test_mode && ! $order->review_required) {
                    $store = app(MarketplaceAccess::class)->store();
                    if ($store) {
                        $store->forceFill(['settings' => [...($store->settings ?? []), 'wallet_test_shop_gid' => $connection->shop_gid, 'wallet_signed_test_secret_hash' => hash('sha256', $connection->credentials['webhook_secret'])]])->save();
                    }
                }
            }
        }
        $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'failure_code' => null])->save();

    }
}
