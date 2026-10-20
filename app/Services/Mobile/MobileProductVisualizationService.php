<?php

namespace App\Services\Mobile;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteProduct;
use App\Services\ManagedWebsite\ManagedWebsiteDomainService;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\ProductVisualizationService;

/** Published Website preview data only; never an editor or a new entitlement. */
class MobileProductVisualizationService
{
    public function __construct(
        private readonly ManagedWebsiteService $websites,
        private readonly ManagedWebsiteDomainService $domains,
        private readonly ProductVisualizationService $visuals,
    ) {}

    public function available(Tenant $tenant): bool
    {
        return $this->context($tenant) !== null;
    }

    public function page(Tenant $tenant, ?string $cursor): array
    {
        $context = $this->context($tenant);
        abort_unless($context !== null, 404);
        ['site' => $site, 'app_origin' => $appOrigin, 'public_origin' => $publicOrigin] = $context;
        $rows = WebsiteProduct::query()->forTenant($tenant)->where('tenant_site_id', $site->id)
            ->where('status', 'active')->where('product_type', 'physical')
            ->where('service_details->visualization->enabled', true)
            ->where('service_details->visualization->dimensions_confirmed', true)
            ->where('id', '>', (int) ($cursor ?? 0))->orderBy('id')->limit(26)->get();
        $scanned = $rows->take(25);
        $products = $scanned->map(function (WebsiteProduct $product) use ($site, $appOrigin, $publicOrigin): ?array {
            $descriptor = $this->visuals->descriptor($site, $product);
            if ($descriptor === null) {
                return null;
            }
            $asset = fn (int $mediaId): string => $appOrigin.route('product-visualization.asset', ['product' => $product->id, 'media' => $mediaId], false);
            $mediaId = (int) data_get($product->service_details, 'visualization.model_media_id');
            // A finite data-only contract. Remote executable bundle URLs are deliberately absent.
            $nativeDescriptor = collect($descriptor)->only(['version', 'productId', 'title', 'placement', 'lengthMm', 'grip', 'rotationDeg'])->all();
            $nativeDescriptor['modelUrl'] = $asset($mediaId);
            $nativeDescriptor['variants'] = array_map(function (array $variant) use ($appOrigin): array {
                $path = (string) parse_url($variant['modelUrl'], PHP_URL_PATH);

                return ['id' => $variant['id'], 'label' => $variant['label'], 'modelUrl' => $appOrigin.$path];
            }, $descriptor['variants']);

            return ['id' => (int) $product->id, 'title' => (string) $product->title, 'handle' => (string) $product->handle,
                'descriptor' => $nativeDescriptor,
                'public_viewer_url' => $publicOrigin.route('managed-website.store.products.visualize', ['handle' => $product->handle], false)];
        })->filter()->values()->all();

        return ['version' => 1, 'products' => $products,
            'allowed_hosts' => array_values(array_unique([parse_url($appOrigin, PHP_URL_HOST), parse_url($publicOrigin, PHP_URL_HOST)])),
            'next_cursor' => $rows->count() > 25 ? (string) $scanned->last()->id : null];
    }

    private function context(Tenant $tenant): ?array
    {
        $site = TenantSite::query()->forTenant($tenant)->where('status', 'published')->where('public_enabled', true)->first();
        $appOrigin = $this->httpsOrigin((string) config('app.url'));
        if ($site === null || $appOrigin === null || ! $this->visuals->enabledFor($site) || $this->websites->publicPage($tenant, '') === null) {
            return null;
        }
        // Keep the existing included-subdomain restriction authoritative, even
        // when an older custom-domain record is still present.
        foreach ([$this->domains->publicUrl($site), $this->domains->platformUrl($site)] as $url) {
            $origin = $this->httpsOrigin($url);
            if ($origin !== null && $this->websites->publicHostAllowed($site, (string) parse_url($origin, PHP_URL_HOST))) {
                return ['site' => $site, 'app_origin' => $appOrigin, 'public_origin' => $origin];
            }
        }

        return null;
    }

    private function httpsOrigin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
            || ! filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || array_intersect(['user', 'pass', 'port', 'query', 'fragment'], array_keys($parts)) !== []
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        return 'https://'.strtolower($parts['host']);
    }
}
