<?php

namespace App\Services\PointOfSale;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteProductVariant;
use Illuminate\Support\Facades\Validator;

/** Read-only keyboard-wedge lookup. A scanned code must equal the native SKU. */
class PosCatalogLookup
{
    public function __construct(private PosAccess $access) {}

    public function find(Tenant $tenant, User $actor, int $registerId, mixed $code): array
    {
        $register = $this->access->register($tenant, $actor, $registerId);
        $data = Validator::make(['code' => $code], ['code' => ['required', 'string', 'max:120', 'regex:/\A[^\p{C}]+\z/u']])->validate();
        $code = trim($data['code']);
        abort_if($code === '', 422, 'Enter the exact catalog SKU.');
        $query = WebsiteProductVariant::query()->forTenantId($tenant->id)->where('is_available', true)
            ->whereHas('product', fn ($products) => $products->where('tenant_id', $tenant->id)->where('tenant_site_id', $register->tenant_site_id)
                ->where('status', 'active')->where('product_type', 'physical'));
        // MySQL's ordinary string collation can match different case or trailing
        // spaces. Scanner lookup intentionally requires exact stored SKU bytes.
        if (in_array($query->getModel()->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->whereRaw('BINARY website_product_variants.sku = ?', [$code]);
        } else {
            $query->where('sku', $code);
        }
        $matches = $query->with('product')->limit(2)->get()->filter(fn ($variant) => (string) $variant->sku === $code)->values();
        abort_if($matches->isEmpty(), 404, 'No available merchandise matches this exact SKU in this register.');
        abort_if($matches->count() !== 1, 409, 'More than one merchandise item uses this SKU. Ask the owner to review the catalog.');
        $variant = $matches->first();
        abort_unless($variant->price_cents >= 1 && $variant->price_cents <= 100000000, 422, 'This item needs a catalog price review.');
        $available = null;
        if ($variant->product->track_inventory) {
            $held = (int) WebsiteInventoryReservation::query()->forTenantId($tenant->id)->where('website_product_variant_id', $variant->id)
                ->where('status', 'active')->sum('quantity');
            $available = max(0, (int) $variant->inventory_quantity - $held);
            abort_if($available < 1, 409, 'This SKU has no unreserved stock available at this register.');
        }

        return ['variant_id' => $variant->id, 'title' => $variant->product->title, 'variant_title' => $variant->title,
            'sku' => $variant->sku, 'price_cents' => $variant->price_cents, 'available_quantity' => $available];
    }
}
