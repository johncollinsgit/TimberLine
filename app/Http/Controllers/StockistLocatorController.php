<?php

namespace App\Http\Controllers;

use App\Models\ShopifyStore;
use App\Models\StockistLocatorSetting;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Stockists\StockistLocatorAccess;
use App\Services\Stockists\StockistLocatorService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\Request;

class StockistLocatorController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function index(Request $request, StockistLocatorAccess $access, ShopifyEmbeddedAppContext $contexts)
    {
        $context = $contexts->resolvePageContext($request);
        $store = $access->store($context);

        return response()->view('shopify.stockist-locator', [
            'authorized' => $store !== null,
            'shopifyApiKey' => data_get($context, 'store.client_id'),
            'shopDomain' => $context['shop_domain'] ?? null, 'host' => $context['host'] ?? '',
            'storeLabel' => $store ? ucfirst($store->store_key).' Store' : 'Shopify Admin',
            'headline' => 'Store locator', 'subheadline' => 'Manage the retailers shown on this store’s locator.',
            'appNavigation' => $this->embeddedAppNavigation('home', null, $store?->tenant_id),
            'pageSubnav' => [], 'pageActions' => [],
        ], $store ? 200 : 403)->header('Cache-Control', 'no-store');
    }

    public function data(Request $request, StockistLocatorAccess $access, StockistLocatorService $service)
    {
        [$store] = $access->operator($request);
        $payload = $service->payload($store);
        // The store-hosted preview keeps the merchant's existing browser-key restrictions.
        $payload['preview_url'] = 'https://'.$store->shop_domain.'/apps/forestry/stockists?'.http_build_query(['preview' => $service->previewToken($store)]);

        return response()->json(['ok' => true, 'data' => $payload])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, StockistLocatorAccess $access, StockistLocatorService $service, ?int $location = null)
    {
        [$store, $actor] = $access->operator($request);
        $revision = $request->validate(['revision' => ['required', 'string', 'size:64']])['revision'];
        $service->save($store, $location, $request->all(), $revision, $actor->id);

        return response()->json(['ok' => true, 'message' => 'Draft saved. Publish when your changes are ready.']);
    }

    public function publish(Request $request, StockistLocatorAccess $access, StockistLocatorService $service)
    {
        [$store, $actor] = $access->operator($request);
        $data = $request->validate(['revision' => ['required', 'string', 'size:64'], 'rollback' => ['sometimes', 'boolean']]);
        $service->publish($store, $data['revision'], $actor->id, (bool) ($data['rollback'] ?? false));

        return response()->json(['ok' => true, 'message' => ($data['rollback'] ?? false) ? 'Previous publication restored.' : 'Directory published.']);
    }

    public function configureKey(Request $request, StockistLocatorAccess $access, StockistLocatorService $service)
    {
        [$store, $actor] = $access->operator($request);
        $data = $request->validate(['revision' => ['required', 'string', 'size:64'], 'maps_key' => ['required', 'string', 'max:120']]);
        $service->configureKey($store, $data['maps_key'], $data['revision'], $actor->id);

        return response()->json(['ok' => true, 'message' => 'Maps browser key saved.']);
    }

    public function storefront(Request $request, StockistLocatorService $service)
    {
        // The existing signature middleware authenticates shop before this lookup.
        $store = ShopifyStore::query()->where('shop_domain', strtolower((string) $request->query('shop')))->first();
        abort_unless($store && $store->tenant_id && in_array($store->store_role, ['retail', 'mixed'], true)
            && app(TenantModuleAccessResolver::class)->canAccess((int) $store->tenant_id, 'shopify'), 404);
        $setting = StockistLocatorSetting::query()->forTenantId((int) $store->tenant_id)->where('shopify_store_id', $store->id)->first();
        if ($request->filled('preview')) {
            abort_unless($setting, 404);

            return $this->render($store, $setting, $service->previewRows($store, (string) $request->query('preview')), $service, true, $this->proxyAssetBase($request));
        }
        abort_unless($setting?->status === 'published' && data_get($setting->configuration, 'published.locations'), 404);

        return $this->render($store, $setting, data_get($setting->configuration, 'published.locations'), $service, false, $this->proxyAssetBase($request));
    }

    public function preview(Request $request, int $store, StockistLocatorService $service)
    {
        abort_unless($request->hasValidSignature(), 403);
        $store = ShopifyStore::query()->findOrFail($store);
        abort_unless($store->tenant_id && in_array($store->store_role, ['retail', 'mixed'], true)
            && app(TenantModuleAccessResolver::class)->canAccess((int) $store->tenant_id, 'shopify'), 404);
        abort_unless(hash_equals($service->revision($store), (string) $request->query('revision')), 409, 'The draft changed. Open a fresh preview.');

        return $this->render($store, $service->settings($store), array_values(array_filter($service->draft($store), fn ($row) => $row['visible'])), $service, true);
    }

    public function asset(string $file)
    {
        $entry = $this->assetEntry();
        $allowed = array_map('basename', array_filter([$entry['file'] ?? null, ...($entry['css'] ?? [])]));
        abort_unless(in_array($file, $allowed, true), 404);

        return response()->file(public_path('build/assets/'.$file), [
            'Content-Type' => str_ends_with($file, '.css') ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function assetEntry(): array
    {
        $path = public_path('build/manifest.json');

        return is_file($path) ? (json_decode(file_get_contents($path), true)['resources/js/stockists/locator.js'] ?? []) : [];
    }

    private function proxyAssetBase(Request $request): string
    {
        $prefix = (string) $request->query('path_prefix', '/apps/forestry');
        abort_unless(preg_match('#^/[a-zA-Z0-9/_-]+$#', $prefix), 400);

        return rtrim($prefix, '/').'/stockists/assets/';
    }

    private function render(ShopifyStore $store, StockistLocatorSetting $setting, array $locations, StockistLocatorService $service, bool $preview, string $assetBase = '/stockists/assets/')
    {
        $key = rescue(fn () => $service->mapsKey($setting), null, false);

        return response()->view('stockists.locator', [
            'locations' => $locations, 'mapsKey' => $key, 'preview' => $preview,
            'storeName' => $store->tenant?->name ?? 'Our retailers',
            'assetEntry' => $this->assetEntry(), 'assetBase' => $assetBase,
        ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex')
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
