<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Audit;
use App\Models\Marketplace\Identity;
use App\Models\Marketplace\InventoryAllocation;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Membership;
use App\Models\Marketplace\PickupLocation;
use App\Models\User;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketplaceCatalog
{
    public function apply(Identity $identity, array $data): Maker
    {
        return DB::transaction(function () use ($identity, $data): Maker {
            $maker = Maker::query()->create([...$data, 'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(6)), 'status' => 'pending']);
            Membership::query()->create(['maker_id' => $maker->id, 'identity_id' => $identity->id, 'role' => 'owner', 'active' => true]);
            Audit::query()->create(['identity_id' => $identity->id, 'action' => 'maker.applied', 'subject_id' => $maker->id]);

            return $maker;
        });
    }

    public function save(Maker $maker, array $data, ?Listing $listing = null): Listing
    {
        if ($listing) {
            abort_unless((int) $listing->maker_id === (int) $maker->id, 404);
        }
        $pickupIds = array_values(array_unique(array_map('intval', $data['pickup_location_ids'] ?? [])));
        if (count($pickupIds) !== PickupLocation::query()->whereIn('id', $pickupIds)->where('active', true)->count()) {
            throw ValidationException::withMessages(['pickup_location_ids' => 'Choose an approved pickup location.']);
        }
        foreach ($pickupIds as $pickupId) {
            abort_unless(app(MarketplaceMarkets::class)->pickupAvailable($maker, $pickupId), 422, 'This maker is not approved for that market pickup, or its order cutoff has passed.');
        }
        if (empty($data['shipping']) && $pickupIds === []) {
            throw ValidationException::withMessages(['shipping' => 'Offer shipping or an approved pickup location.']);
        }
        $allowed = ['title', 'variant_label', 'category', 'description', 'images', 'price_cents', 'shipping', 'lead_time'];
        $values = array_intersect_key($data, array_flip($allowed));
        $values['pickup_location_ids'] = $pickupIds;
        $listing ??= new Listing(['maker_id' => $maker->id, 'slug' => Str::slug($data['title']).'-'.Str::lower(Str::random(6))]);
        $changed = ! $listing->exists;
        foreach ($values as $key => $value) {
            if ($listing->getAttribute($key) != $value) {
                $changed = true;
            }
        }
        $listing->fill($values);
        if ($changed) {
            $listing->revision = $listing->exists ? $listing->revision + 1 : 1;
            $listing->status = 'pending';
            $listing->approved_by = null;
        }
        $listing->save();
        if ($changed && $listing->shopify_product_gid) {
            app(MarketplaceShopify::class)->pause($listing);
        }

        return $listing;
    }

    public function approve(Listing $listing, User $actor): void
    {
        abort_unless($listing->maker->status === 'approved', 422, 'Approve the maker first.');
        abort_unless($listing->images && $listing->title && $listing->description && ($listing->shipping || $listing->pickup_location_ids), 422, 'Complete product photos, details, and delivery options first.');
        $rates = app(MarketplaceRates::class);
        $rates->amounts($listing->price_cents, $rates->resolve($listing));
        abort_unless($rates->accepted($listing), 422, 'The maker must accept the current rate agreement first.');
        $listing->forceFill(['status' => 'approved', 'approved_by' => $actor->id])->save();
        Audit::query()->create(['actor_user_id' => $actor->id, 'action' => 'listing.approved', 'subject_id' => $listing->id, 'details' => ['revision' => $listing->revision]]);
    }

    public function importNative(Maker $maker, User $actor, int $variantId): Listing
    {
        abort_unless($maker->tenant_id, 422);
        app(MarketplaceAccess::class)->assertWorkspaceManager($actor, (int) $maker->tenant_id);
        $variant = WebsiteProductVariant::query()->where('tenant_id', $maker->tenant_id)->whereKey($variantId)->with('product')->firstOrFail();
        abort_unless($variant->product && (int) $variant->product->tenant_id === (int) $maker->tenant_id && $variant->product->product_type === 'physical', 422, 'Select a native physical product.');
        $existing = $maker->listings()->where('source_variant_id', $variant->id)->first();
        if ($existing) {
            return $existing;
        }
        $media = collect($variant->product->media ?? [])->map(fn ($image) => is_string($image) ? $image : ($image['url'] ?? null))->filter(fn ($url) => is_string($url) && str_starts_with($url, 'https://'))->values()->all();

        return $maker->listings()->create([
            'slug' => Str::slug($variant->product->title).'-'.Str::lower(Str::random(6)),
            'title' => $variant->product->title, 'variant_label' => $variant->title,
            'category' => 'Locally made', 'description' => strip_tags($variant->product->description ?? ''),
            'images' => $media, 'price_cents' => $variant->price_cents, 'shipping' => true,
            'source_variant_id' => $variant->id, 'status' => 'pending',
        ]);
    }

    public function allocate(Listing $listing, int $quantity, string $location, string $uuid, ?User $actor, ?Identity $identity): InventoryAllocation
    {
        $native = config('marketplace.checkout_provider') === 'everbranch';
        abort_unless(Str::isUuid($uuid) && $quantity !== 0 && abs($quantity) <= 100000 && ($native || preg_match('#^gid://shopify/Location/[0-9]+$#', $location)), 422);

        return DB::transaction(function () use ($listing, $quantity, $location, $uuid, $actor, $identity): InventoryAllocation {
            $listing = Listing::query()->lockForUpdate()->findOrFail($listing->id);
            if ($previous = InventoryAllocation::query()->where('request_uuid', $uuid)->first()) {
                abort_unless((int) $previous->listing_id === (int) $listing->id && $previous->quantity === $quantity && $previous->shopify_location_gid === $location, 409);

                return $previous;
            }
            $native = config('marketplace.checkout_provider') === 'everbranch';
            $allowed = $native ? array_filter([$listing->shipping ? 'eb:shipping:'.$listing->maker_id : null, ...PickupLocation::query()->whereIn('id', $listing->pickup_location_ids ?? [])->where('active', true)->pluck('id')->map(fn ($id) => 'eb:pickup:'.$id)->all()]) : [$listing->shipping_location_gid, ...PickupLocation::query()->whereIn('id', $listing->pickup_location_ids ?? [])->where('active', true)->pluck('shopify_location_gid')->all()];
            abort_unless(in_array($location, $allowed, true), 422, 'Choose this listing’s shipping or pickup location.');
            $balance = (int) InventoryAllocation::query()->where('listing_id', $listing->id)->where('shopify_location_gid', $location)->sum('quantity');
            abort_unless($balance + $quantity >= 0, 422, 'Cannot remove more than the allocated stock.');
            if ($listing->source_variant_id) {
                abort_unless($actor && $listing->maker->tenant_id, 403, 'Reconnect through Everbranch to allocate source inventory.');
                $tenant = app(MarketplaceAccess::class)->assertWorkspaceManager($actor, (int) $listing->maker->tenant_id);
                $source = WebsiteProductVariant::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($listing->source_variant_id);
                abort_unless($source->product?->track_inventory && $source->inventory_quantity !== null, 422, 'Native inventory must be tracked before allocation.');
                $reserved = (int) WebsiteInventoryReservation::query()->where('tenant_id', $tenant->id)->where('website_product_variant_id', $source->id)->where('expires_at', '>', now())->sum('quantity');
                abort_unless($quantity < 0 || $quantity <= $source->inventory_quantity - $reserved, 422, 'The available native stock is already sold or reserved.');
                // A reduction is held in the marketplace until Shopify confirms withdrawal.
                if ($quantity > 0) {
                    $source->decrement('inventory_quantity', $quantity);
                    WebsiteInventoryMovement::query()->create(['tenant_id' => $tenant->id, 'website_product_variant_id' => $source->id, 'quantity_delta' => -$quantity, 'reason' => 'marketplace_allocation', 'reference_type' => 'marketplace_listing', 'reference_id' => $listing->id, 'actor_user_id' => $actor->id]);
                }
            }
            $allocation = InventoryAllocation::query()->create(['listing_id' => $listing->id, 'quantity' => $quantity, 'shopify_location_gid' => $location, 'request_uuid' => $uuid, 'source_variant_id' => $listing->source_variant_id, 'actor_user_id' => $actor?->id, 'identity_id' => $identity?->id, 'reason' => $quantity > 0 ? 'stock_allocated' : 'stock_withdrawal']);
            if ($native) {
                app(MarketplaceStock::class)->adjust($listing, $location, $quantity);
                if ($quantity < 0 && isset($source)) {
                    $source->increment('inventory_quantity', -$quantity);
                    WebsiteInventoryMovement::create(['tenant_id' => $source->tenant_id, 'website_product_variant_id' => $source->id, 'quantity_delta' => -$quantity, 'reason' => 'marketplace_return', 'reference_type' => 'marketplace_allocation', 'reference_id' => $allocation->id, 'actor_user_id' => $actor?->id]);
                }
                $allocation->forceFill(['applied_at' => now()])->save();
            }
            Audit::query()->create(['actor_user_id' => $actor?->id, 'identity_id' => $identity?->id, 'action' => 'inventory.allocated', 'subject_id' => $listing->id, 'details' => ['quantity' => $quantity, 'location' => $location, 'request' => $uuid]]);

            return $allocation;
        });
    }
}
