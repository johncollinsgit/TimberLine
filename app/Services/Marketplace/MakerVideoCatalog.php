<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\VideoCatalogSource;
use App\Models\TenantSite;
use App\Models\WebsiteProductVariant;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class MakerVideoCatalog
{
    public function options(Maker $maker): array
    {
        $cards = [];
        foreach ($maker->listings()->where('status', 'approved')->orderBy('title')->get() as $listing) {
            $cards[] = $this->listingCard($maker, $listing);
        }
        foreach (VideoCatalogSource::where('maker_id', $maker->id)->orderBy('id')->get() as $source) {
            foreach ($source->selections ?? [] as $id => $snapshot) {
                if ($source->provider === 'shopify') {
                    // The editor can show a saved label while Shopify is offline. Saving or opening a tag revalidates it.
                    $cards[] = $this->card($maker, 'shopify:'.$source->id.':'.$id, $snapshot['label'], $snapshot['variant'], false, null);
                } elseif ($card = $this->nativeCard($maker, $source, (int) $id)) {
                    $cards[] = $card;
                }
            }
        }

        return $cards;
    }

    public function resolve(Maker $maker, string $key): ?array
    {
        if (preg_match('/^marketplace:([1-9][0-9]{0,18})$/D', $key, $parts)) {
            $listing = $maker->listings()->where('status', 'approved')->find($parts[1]);

            return $listing ? $this->listingCard($maker, $listing) : null;
        }
        if (! preg_match('/^(everbranch|shopify):([1-9][0-9]{0,18}):([1-9][0-9]{0,19})$/D', $key, $parts)) {
            return null;
        }
        [, $provider, $ref, $id] = $parts;
        $source = VideoCatalogSource::where('maker_id', $maker->id)->where('provider', $provider)
            ->where($provider === 'shopify' ? 'id' : 'reference', $ref)->first();
        if (! $source || ! isset($source->selections[$id])) {
            return null;
        }
        if ($provider === 'everbranch') {
            return $this->nativeCard($maker, $source, (int) $id);
        }
        $live = Cache::remember('mm-video-variant:'.$source->id.':'.$id, 45, function () use ($source, $id) {
            try {
                return app(MakerVideoShopify::class)->variants($source, id: $id)['variants'][$id] ?? [];
            } catch (ValidationException) {
                return [];
            }
        });
        if (! $live) {
            return null;
        }

        return $this->card($maker, $key, $live['label'], $live['variant'], $live['available'], $live['href']);
    }

    private function nativeCard(Maker $maker, VideoCatalogSource $source, int $id): ?array
    {
        if (! $maker->tenant_id) {
            return null;
        }
        $site = TenantSite::forTenantId($maker->tenant_id)->where('public_enabled', true)->where('status', 'published')
            ->with('tenant', 'publishedSiteVersion')->find($source->reference);
        if (! $site || data_get($site->publishedSiteVersion?->settings ?? $site->settings, 'domain_choice') === 'everbranch_subdomain') {
            return null;
        }
        $variant = WebsiteProductVariant::forTenantId($maker->tenant_id)->with('product')
            ->whereHas('product', fn ($q) => $q->where('tenant_id', $maker->tenant_id)->where('tenant_site_id', $site->id)
                ->where('status', 'active')->where('product_type', 'physical'))->find($id);
        if (! $variant) {
            return null;
        }
        $key = 'everbranch:'.$site->id.':'.$id;
        // A maker who allocated this variant to marketplace checkout should receive its existing order and fee terms.
        $listing = $maker->listings()->where('source_variant_id', $id)->where('status', 'approved')->where('stock_quantity', '>', 0)->first();
        if ($listing) {
            $market = $this->listingCard($maker, $listing);
            if ($market['available']) {
                return ['key' => $key, ...array_diff_key($market, ['key' => true])];
            }
        }
        $websites = app(ManagedWebsiteService::class);
        $host = $site->subdomain.'.'.config('tenancy.domains.canonical.base_domain');
        $product = $variant->product;
        $public = $websites->publicPage($site->tenant, '');
        $available = ($public['site']->id ?? null) === $site->id && $websites->publicHostAllowed($site, $host)
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            && app(WebsiteCommerceService::class)->checkoutReadiness($site->tenant)['ready']
            && $variant->is_available && (! $product->track_inventory || $variant->inventory_quantity === null || $variant->inventory_quantity > 0);

        return $this->card($maker, $key, $product->title, $variant->title, (bool) $available, 'https://'.$host.'/products/'.rawurlencode($product->handle).'?variant='.$id);
    }

    private function listingCard(Maker $maker, Listing $listing): array
    {
        $available = $listing->stock_quantity > 0 && app(MarketplaceRates::class)->accepted($listing)
            && app(MarketplaceCheckout::class)->blockers() === [];
        if ($available && config('marketplace.checkout_provider') === 'shopify') {
            $available = $listing->shopify_variant_gid && $listing->published_revision === $listing->revision
                && (int) $listing->connection_id === (int) app(MarketplaceAccess::class)->settings()->connection_id;
        }
        $card = $this->card($maker, 'marketplace:'.$listing->id, $listing->title, $listing->variant_label ?? '', (bool) $available, 'https://'.config('marketplace.host').'/products/'.rawurlencode($listing->slug));

        return [...$card, 'lane' => 'marketplace', 'listing_id' => $listing->id];
    }

    private function card(Maker $maker, string $key, string $label, string $variant, bool $available, ?string $href): array
    {
        $available = $available && $maker->status === 'approved';

        return ['key' => $key, 'label' => $label, 'variant' => $variant, 'seller' => $maker->name,
            'available' => $available, 'href' => $available ? $href : null, 'lane' => 'maker_store'];
    }
}
