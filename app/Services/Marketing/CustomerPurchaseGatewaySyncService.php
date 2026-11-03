<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\User;
use App\Support\Payments\PurchasePaymentGateways;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Explicit bounded history enrichment. Opening a customer profile remains offline. */
class CustomerPurchaseGatewaySyncService
{
    public const QUERY = <<<'GRAPHQL'
query CustomerPurchaseGateways($id: ID!) {
  shop { myshopifyDomain }
  order(id: $id) {
    id updatedAt customer { id } paymentGatewayNames
    transactions(first: 50) { gateway kind status paymentDetails { ... on CardPaymentDetails { paymentMethodName } ... on ShopPayInstallmentsPaymentDetails { paymentMethodName } } }
  }
}
GRAPHQL;

    public function refresh(int $tenantId, User $actor, int $profileId, bool $apply = false, int $limit = 10): array
    {
        $this->authorize($tenantId, $actor);
        abort_unless($limit >= 1 && $limit <= 20, 422, 'Use a limit from 1 to 20.');
        MarketingProfile::query()->forTenantId($tenantId)->findOrFail($profileId);
        $ids = $this->orderIds($tenantId, $profileId);
        $orders = Order::query()->forTenantId($tenantId)->whereIn('id', $ids)->whereNotNull('shopify_order_id')
            ->orderByDesc('ordered_at')->orderByDesc('id')->limit($limit)->get();
        $observations = [];
        foreach ($orders as $order) {
            $store = ShopifyStore::query()->forTenantId($tenantId)->where('store_key', $order->shopify_store_key)->firstOrFail();
            abort_unless(preg_match('/\A[a-z0-9-]+\.myshopify\.com\z/', $store->shop_domain) && filled($store->access_token)
                && preg_match('/\A[1-9][0-9]*\z/', (string) $order->shopify_order_id)
                && preg_match('/\A[1-9][0-9]*\z/', (string) $order->shopify_customer_id)
                && $this->customerLinked($tenantId, $profileId, $order), 409, 'Verify the original store and exact linked customer first.');
            try {
                $response = Http::withHeaders(['X-Shopify-Access-Token' => $store->access_token, 'Accept' => 'application/json'])
                    ->withoutRedirecting()->connectTimeout(3)->timeout(12)->post('https://'.$store->shop_domain.'/admin/api/2026-10/graphql.json',
                        ['query' => self::QUERY, 'variables' => ['id' => 'gid://shopify/Order/'.$order->shopify_order_id]]);
                abort_unless($response->successful() && $response->header('X-Shopify-API-Version') === '2026-10' && strlen($response->body()) <= 1048576, 502);
                $body = $response->json();
                $row = data_get($body, 'data.order');
                abort_unless(empty($body['errors']) && data_get($body, 'data.shop.myshopifyDomain') === $store->shop_domain
                    && is_array($row) && ($row['id'] ?? null) === 'gid://shopify/Order/'.$order->shopify_order_id
                    && data_get($row, 'customer.id') === 'gid://shopify/Customer/'.$order->shopify_customer_id
                    && is_array($row['paymentGatewayNames'] ?? null) && array_is_list($row['paymentGatewayNames'])
                    && is_array($row['transactions'] ?? null) && array_is_list($row['transactions']) && is_string($row['updatedAt'] ?? null), 409);
            } catch (\Throwable) {
                abort(502, 'Original purchase gateway history could not be verified. No history enrichment was saved.');
            }
            $transactions = [];
            if (count($row['transactions']) < 50) {
                foreach ($row['transactions'] as $transaction) {
                    $transactions[] = $transaction;
                    $method = strtolower((string) data_get($transaction, 'paymentDetails.paymentMethodName'));
                    if (in_array($method, ['shop_pay', 'shop pay', 'shoppay'], true) && ($transaction['status'] ?? null) === 'SUCCESS'
                        && in_array($transaction['kind'] ?? null, ['SALE', 'CAPTURE'], true)) {
                        $transactions[] = ['gateway' => 'shop_pay', 'status' => 'success', 'kind' => 'sale'];
                    }
                }
            }
            $observations[] = ['order' => $order, 'store' => $store, 'payload' => ['payment_gateway_names' => $row['paymentGatewayNames'], 'transactions' => $transactions, 'updated_at' => $row['updatedAt']]];
        }

        return DB::transaction(function () use ($tenantId, $actor, $profileId, $ids, $observations, $apply) {
            $this->authorize($tenantId, $actor->fresh());
            MarketingProfile::query()->forTenantId($tenantId)->whereKey($profileId)->lockForUpdate()->firstOrFail();
            abort_unless($this->orderIds($tenantId, $profileId) === $ids, 409, 'The customer purchase links changed during review.');
            $summaries = [];
            foreach ($observations as $observation) {
                $prior = $observation['order'];
                $oldStore = $observation['store'];
                $current = Order::query()->forTenantId($tenantId)->whereKey($prior->id)->lockForUpdate()->firstOrFail();
                $store = ShopifyStore::query()->forTenantId($tenantId)->whereKey($oldStore->id)->lockForUpdate()->firstOrFail();
                abort_unless($store->shop_domain === $oldStore->shop_domain && hash_equals((string) $store->access_token, (string) $oldStore->access_token)
                    && $current->shopify_order_id === $prior->shopify_order_id && $current->shopify_store_key === $prior->shopify_store_key
                    && $current->shopify_customer_id === $prior->shopify_customer_id && $this->customerLinked($tenantId, $profileId, $current), 409, 'Original purchase ownership changed during verification.');
                $presenter = app(PurchasePaymentGateways::class);
                $metadata = $presenter->capture((array) $current->attribution_meta, $observation['payload']);
                if ($apply) {
                    // Metadata only: do not replay order ingestion, fulfillment, loyalty or model events.
                    DB::table('orders')->where('tenant_id', $tenantId)->where('id', $current->id)->update(['attribution_meta' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
                }
                $summaries[] = ['order_id' => $current->id, 'gateways' => array_column($presenter->badges(data_get($metadata, 'payment_gateways.names', [])), 'label')];
            }

            return ['status' => $apply ? 'saved_gateway_metadata_only' : 'preview_only', 'orders' => $summaries,
                'provider_mutations' => false, 'customer_messages' => false, 'orders_examined' => count($observations)];
        });
    }

    private function orderIds(int $tenantId, int $profileId): array
    {
        return MarketingProfileLink::query()->forTenantId($tenantId)->where('marketing_profile_id', $profileId)->where('source_type', 'order')
            ->pluck('source_id')->filter(fn ($id) => preg_match('/\A[1-9][0-9]*\z/', (string) $id))->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    private function customerLinked(int $tenantId, int $profileId, Order $order): bool
    {
        return MarketingProfileLink::query()->forTenantId($tenantId)->where('marketing_profile_id', $profileId)->where('source_type', 'shopify_customer')
            ->where('source_id', $order->shopify_store_key.':'.$order->shopify_customer_id)->exists();
    }

    private function authorize(int $tenantId, User $actor): void
    {
        $member = $actor->tenants()->whereKey($tenantId)->first();
        abort_unless($actor->is_active && $actor->email_verified_at && $actor->canAccessMarketing() && $member && $member->pivot->membership_active
            && in_array(strtolower($member->pivot->role), ['owner', 'tenant_owner', 'admin', 'manager'], true), 403, 'An active verified customer manager is required.');
    }
}
