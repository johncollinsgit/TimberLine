<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Market;
use App\Models\Marketplace\PickupLocation;
use Illuminate\Support\Facades\DB;

class MarketplaceMarkets
{
    public function pickupAvailable(Maker $maker, int $id): bool
    {
        $pickup = PickupLocation::whereKey($id)->where('active', true)->first();
        if (! $pickup) {
            return false;
        }
        $market = Market::where('pickup_location_id', $id)->first();
        if (! $market) {
            return true;
        }

        return $market->status === 'published' && $market->ends_at->isFuture() && (! $market->pickup_cutoff_at || $market->pickup_cutoff_at->isFuture()) && DB::table('marketplace_market_makers')->where('market_id', $market->id)->where('maker_id', $maker->id)->where('status', 'approved')->exists();
    }

    public function reviewPaidPickup(\App\Models\Marketplace\Order $order): void
    {
        if ($order->group_key === 'shipping' || ! empty($order->fulfillment_details['pickup']) || ! $order->lines()->whereNull('fulfilled_at')->whereColumn('refunded_quantity', '<', 'quantity')->exists()) {
            return;
        }
        $frozen = $order->checkout->snapshot['pickup'] ?? null;
        if (! $frozen) {
            return;
        }
        $pickup = PickupLocation::find($frozen['id']);
        $market = Market::where('pickup_location_id', $frozen['id'])->first();
        if (! $pickup || ! $pickup->active || ($market && $market->status !== 'published') || $pickup->name !== $frozen['name'] || $pickup->address !== $frozen['address'] || $pickup->instructions !== $frozen['instructions']) {
            $order->forceFill(['review_required' => true, 'review_reason' => 'The market pickup schedule or location changed. Contact the customer and record a reschedule or refund before fulfillment.'])->save();
        }
    }
}
