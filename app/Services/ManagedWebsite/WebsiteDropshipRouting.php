<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\WebsiteCart;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderLine;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Links reviewed Website variants to supplier variants within one seller and site. */
class WebsiteDropshipRouting
{
    /** @return Collection<int,WebsiteOrderLine> */
    public function orderLines(WebsiteOrder $order): Collection
    {
        if ($order->source !== 'native') {
            return collect();
        }

        return $order->loadMissing('lines')->lines->filter(fn (WebsiteOrderLine $line): bool => $line->tenant_id === $order->tenant_id
            && $line->product_type === 'physical' && is_array(data_get($line->snapshot, 'dropshipping')));
    }

    /** @return array<int,array{provider:string,supplier:string,variant:string,location_id:int}> */
    public function forCart(WebsiteCart $cart, TenantSite $site): array
    {
        $routes = config('managed_website.dropshipping_routes.'.$site->tenant_id.'.'.$site->id, []);
        if ($routes === []) {
            return [];
        }

        abort_unless($cart->tenant_id === $site->tenant_id && $cart->tenant_site_id === $site->id, 404);
        abort_unless(is_array($routes), 423, 'Dropshipping needs a valid supplier setup.');

        $cart->loadMissing('items.variant.product');
        $physicalCount = 0;
        $assigned = [];
        $destinations = [];
        foreach ($cart->items as $item) {
            $variant = $item->variant;
            abort_unless($variant && $variant->tenant_id === $site->tenant_id
                && $variant->product?->tenant_id === $site->tenant_id
                && $variant->product?->tenant_site_id === $site->id, 404);
            if ($variant->product->product_type === 'physical') {
                $physicalCount++;
            }
            if (! array_key_exists($variant->id, $routes)) {
                continue;
            }

            $route = $routes[$variant->id];
            abort_unless(is_array($route) && $variant->product->product_type === 'physical'
                && in_array($route['provider'] ?? null, ['shopify', 'printful', 'manual'], true)
                && $this->validId($route['supplier'] ?? null)
                && $this->validId($route['variant'] ?? null)
                && is_int($route['location_id'] ?? null) && $route['location_id'] > 0, 423, 'Dropshipping needs a valid supplier setup.');
            $assigned[$variant->id] = [
                'provider' => $route['provider'],
                'supplier' => $route['supplier'],
                'variant' => $route['variant'],
                'location_id' => $route['location_id'],
            ];
            $destinations[] = [$route['provider'], $route['supplier'], $route['location_id']];
        }

        if ($assigned !== [] && ($physicalCount !== count($assigned) || count(array_unique($destinations, SORT_REGULAR)) !== 1)) {
            throw ValidationException::withMessages(['cart' => 'Items shipped by different suppliers must be ordered separately.']);
        }

        ksort($assigned, SORT_NUMERIC);

        return $assigned;
    }

    private function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D', $value) === 1;
    }
}
