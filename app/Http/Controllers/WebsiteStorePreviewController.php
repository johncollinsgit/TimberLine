<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteCollection;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use App\Services\ManagedWebsite\ConnectedWebsiteService;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteStorefrontPresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Owned catalog inspection only: no public-host, cart, shopper or payment authority. */
class WebsiteStorePreviewController extends Controller
{
    public function shop(Request $request, ManagedWebsiteService $websites, WebsiteStorefrontPresentation $presentation): Response
    {
        [$tenant, $site] = $this->context($request, $websites);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'collection' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'category' => ['nullable', 'string', 'max:160'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $collection = (string) ($filters['collection'] ?? '');
        $category = data_get($site->draftSiteVersion?->settings ?? $site->settings, 'theme_key') === 'sawyer-naturals'
            ? (string) ($filters['category'] ?? 'all') : 'all';
        $collectionQuery = WebsiteCollection::query()->forTenant($tenant)->where('tenant_site_id', $site->id)->whereIn('status', ['draft', 'active']);
        $selectedCollection = $collection !== '' ? (clone $collectionQuery)->where('handle', $collection)->firstOrFail() : null;
        $collections = $collectionQuery->orderBy('title')->limit(100)->get();
        if ($selectedCollection && ! $collections->contains('id', $selectedCollection->id)) {
            $collections->push($selectedCollection);
        }
        $query = WebsiteProduct::query()->forTenant($tenant)->where('tenant_site_id', $site->id)->whereIn('status', ['draft', 'active'])
            ->with(['variants' => fn ($variants) => $variants->where('tenant_id', $tenant->id)->orderBy('id')]);
        if ($selectedCollection) {
            $query->whereHas('collections', fn ($groups) => $groups->where('website_collections.id', $selectedCollection->id)
                ->where('website_collections.tenant_id', $tenant->id)->where('website_collection_products.tenant_id', $tenant->id)
                ->where('tenant_site_id', $site->id)->whereIn('status', ['draft', 'active']));
        }
        if ($search !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($products) => $products->whereRaw("title LIKE ? ESCAPE '!'", [$like])->orWhereRaw("description LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('variants', fn ($variants) => $variants->where('tenant_id', $tenant->id)->whereRaw("sku LIKE ? ESCAPE '!'", [$like])));
        }
        if ($category !== 'all') {
            $query->whereJsonContains('service_details->categories', $category);
        }
        $products = $query->orderBy('id')->paginate(24)->withQueryString();

        return $this->response('managed-website.shop', $this->payload($request, $site, $presentation, $products->pluck('id')->all())
            + compact('products', 'search', 'collection', 'collections', 'selectedCollection', 'category'));
    }

    public function product(Request $request, WebsiteProduct $product, ManagedWebsiteService $websites, WebsiteStorefrontPresentation $presentation): Response
    {
        [$tenant, $site] = $this->context($request, $websites);
        abort_unless((int) $product->tenant_id === (int) $tenant->id && (int) $product->tenant_site_id === (int) $site->id
            && in_array($product->status, ['draft', 'active'], true), 404);
        $product->load(['variants' => fn ($variants) => $variants->where('tenant_id', $tenant->id)->orderBy('id')]);
        $input = $request->validate(['variant' => ['nullable', 'integer', 'min:1']]);
        $variantChoices = $product->variants->map(function (WebsiteProductVariant $variant) use ($product): array {
            $tracked = $product->track_inventory && $variant->inventory_quantity !== null;
            $available = $variant->is_available && (! $tracked || $variant->inventory_quantity > 0);

            return ['id' => $variant->id, 'title' => $variant->title, 'price' => '$'.number_format($variant->price_cents / 100, 2),
                'compare_price' => $variant->compare_at_price_cents > $variant->price_cents ? '$'.number_format($variant->compare_at_price_cents / 100, 2) : '',
                'available' => $available, 'availability' => $available ? 'In stock' : 'Currently unavailable',
                'maximum' => $available ? ($tracked ? min(20, $variant->inventory_quantity) : 20) : 0, 'action' => ''];
        });
        $selectedVariant = isset($input['variant']) ? $variantChoices->firstWhere('id', (int) $input['variant'])
            : ($variantChoices->firstWhere('available', true) ?? $variantChoices->first());
        abort_if(isset($input['variant']) && ! $selectedVariant, 404);

        return $this->response('managed-website.product', $this->payload($request, $site, $presentation, [$product->id])
            + compact('product', 'variantChoices', 'selectedVariant')
            + ['productUrl' => route('managed-website.preview.product', ['product' => $product, 'tenant' => $tenant->slug])]);
    }

    private function context(Request $request, ManagedWebsiteService $websites): array
    {
        $tenant = $request->attributes->get('current_tenant');
        $actor = $request->user();
        abort_unless($tenant instanceof Tenant && $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail(), 403);
        abort_unless($actor->tenants()->whereKey($tenant->id)->wherePivot('membership_active', true)
            ->wherePivotIn('role', ['owner', 'tenant_owner', 'admin', 'manager', 'marketing_manager'])->exists(), 403);
        abort_unless($websites->draftViewingEnabledFor($tenant), 423, 'Private preview is not available for this workspace.');
        $site = TenantSite::query()->forTenant($tenant)->with(['draftSiteVersion', 'pages.draftVersion'])->firstOrFail();
        $draft = $site->draftSiteVersion;
        abort_if($site->draft_site_version_id && (! $draft || (int) $draft->tenant_id !== (int) $tenant->id
            || (int) $draft->tenant_site_id !== (int) $site->id), 404);
        abort_if(app(ConnectedWebsiteService::class)->connected($site), 409, 'Use the connected website preview for this site.');

        return [$tenant, $site];
    }

    private function payload(Request $request, TenantSite $site, WebsiteStorefrontPresentation $presentation, array $productIds): array
    {
        $tenant = $site->tenant;
        $shopUrl = route('managed-website.preview.shop', ['tenant' => $tenant->slug]);
        $productUrls = collect($productIds)->mapWithKeys(fn ($id) => [$id => route('managed-website.preview.product', ['product' => $id, 'tenant' => $tenant->slug])])->all();
        $previewLinks = $site->pages->filter(fn ($page) => (int) $page->tenant_id === (int) $site->tenant_id
            && $page->draftVersion !== null && (int) $page->draftVersion->tenant_id === (int) $site->tenant_id
            && (int) $page->draftVersion->tenant_site_id === (int) $site->id)
            ->mapWithKeys(fn ($page) => [$page->slug === '/' ? '/' : '/'.ltrim($page->slug, '/') => route('managed-website.editor.preview.site', ['page' => $page, 'tenant' => $tenant->slug])])->all();
        $previewLinks['/shop'] = $shopUrl;

        return [
            'tenant' => $tenant, 'site' => $site, 'storePreview' => true,
            'storeSettings' => (array) ($site->draftSiteVersion?->settings ?? $site->settings),
            'storefront' => $presentation->capabilities($site, $request, true),
            'retailCards' => $presentation->cards($site, true, ['shop' => $shopUrl, 'products' => $productUrls]),
            'shopUrl' => $shopUrl, 'productUrls' => $productUrls, 'previewLinks' => $previewLinks,
            'homeUrl' => $previewLinks['/'] ?? route('managed-website.index', ['tenant' => $tenant->slug]),
            'contactUrl' => $previewLinks['/contact'] ?? null,
            'editorUrl' => route('managed-website.index', ['tenant' => $tenant->slug]),
            'previewBackLabel' => 'Back to Website', 'cartEnabled' => false, 'checkoutReady' => false,
            'quoteRequestAllowed' => false, 'cart' => null,
        ];
    }

    private function response(string $view, array $payload): Response
    {
        return response()->view($view, $payload)->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'same-origin')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "frame-ancestors 'none'")->header('X-Frame-Options', 'DENY');
    }
}
