<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Checkout;
use App\Models\Marketplace\InventoryLevel;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\StockReservation;
use Illuminate\Support\Facades\DB;

/** Native inventory owns allocated units; payment processors never own catalog stock. */
class MarketplaceStock
{
    public function location(Listing $listing, string $group): string
    {
        return $group === 'shipping' ? 'eb:shipping:'.$listing->maker_id : 'eb:'.$group;
    }

    public function adjust(Listing $listing, string $location, int $delta): void
    {
        $level = InventoryLevel::query()->firstOrCreate(['listing_id' => $listing->id, 'shopify_location_gid' => $location], ['quantity' => 0]);
        $level = InventoryLevel::query()->lockForUpdate()->findOrFail($level->id);
        abort_unless($level->quantity + $delta >= 0, 422, 'Some units are sold or reserved. Withdraw only available stock.');
        $level->forceFill(['quantity' => $level->quantity + $delta])->save();
        $listing->forceFill(['stock_quantity' => InventoryLevel::where('listing_id', $listing->id)->sum('quantity'), 'synced_at' => now()])->save();
    }

    public function reserve(Checkout $checkout, Listing $listing, int $quantity, string $group): void
    {
        $location = $this->location($listing, $group);
        $this->adjust($listing, $location, -$quantity);
        StockReservation::create(['checkout_id' => $checkout->id, 'listing_id' => $listing->id, 'location_key' => $location, 'quantity' => $quantity]);
    }

    public function settle(Checkout $checkout, bool $paid): void
    {
        DB::transaction(function () use ($checkout, $paid): void {
            foreach (StockReservation::where('checkout_id', $checkout->id)->orderBy('listing_id')->lockForUpdate()->get() as $reservation) {
                if ($reservation->committed_at || $reservation->released_at) {
                    continue;
                }
                $listing = Listing::query()->lockForUpdate()->findOrFail($reservation->listing_id);
                if (! $paid) {
                    $this->adjust($listing, $reservation->location_key, $reservation->quantity);
                }
                $reservation->forceFill([$paid ? 'committed_at' : 'released_at' => now()])->save();
            }
        });
    }
}
