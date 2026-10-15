<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\WebsiteCart;
use App\Models\WebsiteProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WebsiteShippingReadinessService
{
    /** @param array<string,mixed> $variant @return array<string,mixed> */
    public function normalizeWeight(array $variant, string $field = 'shipping_weight'): array
    {
        if (! array_key_exists('shipping_weight', $variant)) {
            return $variant;
        }

        $value = $variant['shipping_weight'];
        if ($value === null || $value === '') {
            $variant['shipping_weight_ounces'] = null;

            return $variant;
        }

        $factor = match ($variant['shipping_weight_unit'] ?? 'oz') {
            'oz' => 1,
            'lb' => 16,
            'g' => 1 / 28.349523125,
            default => null,
        };
        if ($factor === null || ! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
            throw ValidationException::withMessages([$field => 'Enter a positive shipping weight in ounces, pounds, or grams.']);
        }

        // Existing native shipping records store whole ounces. Never round down
        // a measured weight or substitute the package tare for a missing weight.
        $ounces = ceil((float) $value * $factor - 0.000000001);
        if ($ounces < 1 || $ounces > 1000000) {
            throw ValidationException::withMessages([$field => 'Enter a shipping weight between 1 and 1,000,000 ounces.']);
        }
        $variant['shipping_weight_ounces'] = (int) $ounces;

        return $variant;
    }

    public function missingWeights(TenantSite $site): Builder
    {
        return WebsiteProductVariant::query()->forTenantId($site->tenant_id)
            ->where('is_available', true)
            ->whereHas('product', fn (Builder $query) => $query->forTenantId($site->tenant_id)
                ->where('tenant_site_id', $site->id)->where('product_type', 'physical')->where('status', '!=', 'archived'))
            ->where(fn (Builder $query) => $query->whereNull('shipping_weight_ounces')->orWhere('shipping_weight_ounces', '<=', 0));
    }

    /** @return Collection<int,\App\Models\WebsiteCartItem> */
    public function missingCartWeights(WebsiteCart $cart): Collection
    {
        return $cart->load('items.variant.product')->items
            ->filter(fn ($item) => $item->variant?->product?->product_type === 'physical'
                && (int) $item->variant->shipping_weight_ounces <= 0)->values();
    }

    public function assertCartCanShip(WebsiteCart $cart): void
    {
        $missing = $this->missingCartWeights($cart);
        if ($missing->isNotEmpty()) {
            $this->unavailable($missing->map(fn ($item) => $this->variantLabel($item->variant))->unique()->implode(', '));
        }
    }

    public function assertVariantCanShip(WebsiteProductVariant $variant): void
    {
        if ($variant->product?->product_type === 'physical' && (int) $variant->shipping_weight_ounces <= 0) {
            $this->unavailable($this->variantLabel($variant));
        }
    }

    private function variantLabel(WebsiteProductVariant $variant): string
    {
        return $variant->product->title.(in_array($variant->title, ['Default', 'Default Title', ''], true) ? '' : ' ('.$variant->title.')');
    }

    private function unavailable(string $items): never
    {
        throw ValidationException::withMessages([
            'cart' => 'Shipping is not available for '.$items.' yet. Choose another delivery option or remove these items.',
        ]);
    }
}
