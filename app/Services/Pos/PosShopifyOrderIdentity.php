<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use Illuminate\Support\Facades\Schema;

/** A provider tag is a hint; the retained original order reference is the proof. */
final class PosShopifyOrderIdentity
{
    public function __construct(private PosStore $store, private CanonicalMarketingProfileResolver $identities) {}

    public function link(Order $order): void
    {
        $id = data_get($order->attribution_meta, 'customer_commerce.everbranch_pos_tag');
        if (! is_string($id) || ! preg_match('/^[a-f0-9]{32}$/D', $id)
            || ! Schema::hasTable('pos_live_sales') || ! filled(config('pos_live.runtime_id')) || ! $order->tenant_id) {
            return;
        }
        $tenant = (int) $order->tenant_id;
        $sale = $this->store->rows('sales', $tenant)->where('id', $id)->first();
        if (! $sale || ! $sale->source_reference) {
            return;
        }
        $snapshot = app(PosService::class)->snapshot($sale);
        $reference = $this->store->decrypt($sale->source_reference);
        if (empty($snapshot['customer']['id']) || ($reference['order_id'] ?? '') !== 'gid://shopify/Order/'.$order->shopify_order_id
            || ($snapshot['customer']['shopify_store_key'] ?? '') !== $order->shopify_store_key) {
            return;
        }
        $this->store->db->transaction(function () use ($tenant, $snapshot, $sale, $order): void {
            $profile = MarketingProfile::query()->forTenantId($tenant)->whereKey($snapshot['customer']['id'])->lockForUpdate()->firstOrFail();
            $canonical = $this->identities->canonical($profile, $tenant);
            if (! $canonical) {
                throw new PosException('customer_identity_review_required');
            }
            $links = MarketingProfileLink::query()->forTenantId($tenant)->where('source_type', 'order')
                ->where('source_id', (string) $order->id)->lockForUpdate()->get();
            if ($links->contains(fn ($link) => $this->identities->byId($tenant, (int) $link->marketing_profile_id)?->id !== $canonical->id)) {
                throw new PosException('customer_identity_review_required');
            }
            if ($links->isEmpty()) {
                MarketingProfileLink::query()->create(['tenant_id' => $tenant, 'marketing_profile_id' => $profile->id,
                    'source_type' => 'order', 'source_id' => (string) $order->id, 'match_method' => 'original_pos_selection',
                    'source_meta' => ['pos_sale_id' => $sale->id, 'purpose' => 'customer_purchase_history']]);
                $this->store->event($tenant, $sale->id, 'shopify_customer_order_linked', ['order_id' => $order->id, 'profile_id' => $profile->id]);
            }
        }, 3);
    }
}
