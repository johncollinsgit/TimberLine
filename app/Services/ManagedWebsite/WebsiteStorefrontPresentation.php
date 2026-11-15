<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteCollection;
use App\Models\WebsiteProduct;
use App\Services\Billing\TenantPaymentSetupAccess;
use Illuminate\Http\Request;

/** Read-only presentation of existing gates; never payment or host authority. */
class WebsiteStorefrontPresentation
{
    public function __construct(
        private readonly WebsiteCommerceService $commerce,
        private readonly WebsiteMediaUrls $media,
    ) {}

    public function capabilities(TenantSite $site, Request $request, bool $preview = false): array
    {
        $settings = $this->settings($site, $preview);
        $quoteOnly = data_get($settings, 'domain_choice') === 'everbranch_subdomain';
        $customHost = (bool) $request->attributes->get('managed_website_relay')
            || data_get($request->attributes->get('host_tenant_context'), 'strategy') === 'managed_website_custom_domain';
        $account = ! $preview && ! $customHost && ! $quoteOnly;
        $cart = $account && $this->commerce->enabledFor($site->tenant);
        $readiness = $this->commerce->checkoutReadiness($site->tenant);

        return [
            'is_retail_starter' => in_array(data_get($settings, 'theme_key'), ['starter-candle_maker', 'starter-apparel'], true),
            'quote_only' => $quoteOnly,
            'custom_host' => $customHost,
            'is_preview' => $preview,
            'can_account' => $account,
            'can_cart' => $cart,
            'checkout_ready' => $cart && $readiness['ready'],
            'merchant_ready' => $readiness['ready'],
            'checkout_blockers' => $readiness['blockers'],
        ];
    }

    /** Bounded actual catalog cards. Preview links must be issued by its owner route. */
    public function cards(TenantSite $site, bool $preview = false, array $previewUrls = []): array
    {
        $quoteOnly = data_get($this->settings($site, $preview), 'domain_choice') === 'everbranch_subdomain';
        $statuses = $preview ? ['draft', 'active'] : ['active'];
        $visible = fn ($query) => $query->where('website_products.tenant_id', $site->tenant_id)
            ->where('website_products.tenant_site_id', $site->id)->where('website_collection_products.tenant_id', $site->tenant_id)->whereIn('website_products.status', $statuses)
            ->when($quoteOnly && ! $preview, fn ($query) => $query->where('product_type', 'quote'));
        $products = WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)
            ->whereIn('status', $statuses)->when($quoteOnly && ! $preview, fn ($query) => $query->where('product_type', 'quote'))
            ->with(['variants' => fn ($query) => $query->where('tenant_id', $site->tenant_id)->orderBy('id')->limit(100)])
            ->orderBy('id')->limit(8)->get()->map(function (WebsiteProduct $product) use ($site, $preview, $previewUrls): array {
                $sellable = $product->variants->filter(fn ($variant) => $variant->is_available
                    && (! $product->track_inventory || $variant->inventory_quantity === null || $variant->inventory_quantity > 0));
                $price = ($sellable->isNotEmpty() ? $sellable : $product->variants)->min('price_cents');
                $image = collect($product->media ?? [])->map(fn ($url) => $this->media->forSite($site, $url))->filter()->first();

                return [
                    'id' => $product->id, 'title' => $product->title, 'status' => $product->status,
                    'description' => $product->description,
                    'url' => $preview ? ($previewUrls['products'][$product->id] ?? route('managed-website.preview.product', ['product' => $product->id, 'tenant' => $site->tenant->slug])) : route('managed-website.store.products.show', ['handle' => $product->handle]),
                    'image' => $image, 'alt' => data_get($product->service_details, 'image_alt') ?: $product->title,
                    'price' => $product->product_type === 'quote' || $price === null ? null : ($product->variants->count() > 1 ? 'From ' : '').'$'.number_format($price / 100, 2),
                    'available' => $sellable->isNotEmpty(), 'quote' => $product->product_type === 'quote',
                ];
            })->all();
        $collections = WebsiteCollection::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)
            ->whereIn('status', $statuses)->whereHas('products', $visible)->orderBy('title')->limit(8)->get()
            ->map(function (WebsiteCollection $collection) use ($site, $preview, $previewUrls): array {
                $shop = $preview ? ($previewUrls['shop'] ?? route('managed-website.preview.shop', ['tenant' => $site->tenant->slug])) : route('managed-website.store.index');

                return [
                    'id' => $collection->id, 'title' => $collection->title, 'status' => $collection->status,
                    'description' => $collection->description,
                    'url' => $shop ? $shop.(str_contains($shop, '?') ? '&' : '?').http_build_query(['collection' => $collection->handle]) : null,
                    'image' => $this->media->forSite($site, $collection->image_url),
                    'alt' => data_get($collection->seo, 'image_alt') ?: $collection->title,
                ];
            })->all();

        return ['products' => $products, 'collections' => $collections];
    }

    public function guide(TenantSite $site, Request $request): ?array
    {
        $capabilities = $this->capabilities($site, $request, true);
        if (! $capabilities['is_retail_starter']) {
            return null;
        }
        $products = WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('status', '!=', 'archived');
        $active = (clone $products)->where('status', 'active');
        $count = $products->count();
        $activeCount = (clone $active)->count();
        $missingPhotos = (clone $active)->where(fn ($query) => $query->whereNull('media')->orWhereJsonLength('media', 0))->count();
        $collectionQuery = WebsiteCollection::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('status', 'active');
        $collections = (clone $collectionQuery)->count();
        $missingCollectionPhotos = (clone $collectionQuery)->where(fn ($query) => $query->whereNull('image_url')->orWhere('image_url', ''))->count();
        $unlinkedCollections = (clone $collectionQuery)->whereDoesntHave('products', fn ($query) => $query
            ->where('website_products.tenant_id', $site->tenant_id)->where('website_products.tenant_site_id', $site->id)
            ->where('website_products.status', 'active')->where('website_collection_products.tenant_id', $site->tenant_id))->count();
        $access = app(TenantPaymentSetupAccess::class);
        $unpaid = app(ManagedWebsiteSelfServicePolicy::class)->unpaidTrial($site->tenant);
        $actor = $request->user();
        $canManageCatalog = ! $unpaid && app(ManagedWebsiteService::class)->editorEnabledFor($site->tenant)
            && $actor instanceof User && $actor->is_active
            && $actor->tenants()->whereKey($site->tenant_id)->wherePivot('membership_active', true)
                ->wherePivotIn('role', ['owner', 'tenant_owner', 'admin', 'manager', 'marketing_manager'])->exists();

        return [
            'product_count' => $count, 'active_products' => $activeCount, 'missing_photos' => $missingPhotos,
            'collections' => $collections, 'missing_collection_photos' => $missingCollectionPhotos, 'unlinked_collections' => $unlinkedCollections,
            'collections_ready' => $collections > 0 && $missingCollectionPhotos === 0 && $unlinkedCollections === 0,
            'photos_ready' => $activeCount > 0 && $missingPhotos === 0,
            'can_manage_catalog' => $canManageCatalog, 'unpaid_trial' => $unpaid,
            'has_shop_link' => collect($site->draftSiteVersion?->navigation ?? [])->contains(fn ($item) => ($item['url'] ?? null) === '/shop'),
            'can_setup_payments' => $access->enabledForSetup($site->tenant) && $access->allows($site->tenant, $request->user()),
            'payments_ready' => $capabilities['merchant_ready'],
        ];
    }

    private function settings(TenantSite $site, bool $preview): array
    {
        return (array) (($preview ? $site->draftSiteVersion?->settings : $site->publishedSiteVersion?->settings) ?? $site->settings);
    }
}
