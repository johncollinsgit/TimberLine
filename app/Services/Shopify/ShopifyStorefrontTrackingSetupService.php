<?php

namespace App\Services\Shopify;

use Illuminate\Support\Arr;

class ShopifyStorefrontTrackingSetupService
{
    public function __construct(
        protected ShopifyWebPixelConnectionService $webPixelConnectionService
    ) {}

    public function build(array $store = [], ?string $host = null): array
    {
        $appConfigPath = base_path('shopify.app.toml');
        $embedManifestPath = base_path('extensions/forestry-marketing-embed/shopify.extension.toml');
        $embedBlockPath = base_path('extensions/forestry-marketing-embed/blocks/marketing-app-embed.liquid');
        $embedAssetPath = base_path('extensions/forestry-marketing-embed/assets/marketing-storefront-tracker.js');
        $pixelManifestPath = base_path('extensions/forestry-marketing-pixel/shopify.extension.toml');
        $pixelSourcePath = base_path('extensions/forestry-marketing-pixel/src/index.js');

        $appConfigPresent = is_file($appConfigPath);
        $embedPresent = is_file($embedManifestPath) && is_file($embedBlockPath) && is_file($embedAssetPath);
        $pixelPresent = is_file($pixelManifestPath) && is_file($pixelSourcePath);
        $proxyEnabled = (bool) config('marketing.shopify.app_proxy_enabled', false);
        $proxySecretPresent = trim((string) (config('marketing.shopify.app_proxy_secret') ?: config('marketing.shopify.signing_secret') ?: '')) !== '';
        $signingSecretPresent = trim((string) config('marketing.shopify.signing_secret', '')) !== '';
        $adminBaseUrl = $this->adminBaseUrl($host);
        $pixelStatus = $store !== [] ? $this->webPixelConnectionService->status($store) : [
            'ok' => false,
            'status' => 'store_missing',
            'connected' => false,
            'label' => 'Store not resolved',
            'message' => 'Open this page from Shopify Admin to check the app pixel status.',
        ];

        $themeEditorHref = $adminBaseUrl !== null
            ? $adminBaseUrl.'/themes/current/editor?context=apps'
            : null;
        $customerEventsHref = $adminBaseUrl !== null
            ? $adminBaseUrl.'/settings/customer_events'
            : null;
        $extensionsDocsHref = $adminBaseUrl !== null
            ? $adminBaseUrl.'/apps'
            : null;

        return [
            'app_config_present' => $appConfigPresent,
            'app_proxy' => [
                'ready' => $proxyEnabled && $proxySecretPresent && $signingSecretPresent,
                'app_proxy_enabled' => $proxyEnabled,
                'has_app_proxy_secret' => $proxySecretPresent,
                'has_signing_secret' => $signingSecretPresent,
                'health_path' => '/apps/forestry/health',
                'funnel_path' => '/apps/forestry/funnel/event',
                'customer_status_path' => '/apps/forestry/customer/status',
            ],
            'theme_embed' => [
                'project_present' => $embedPresent,
                'manifest_path' => $embedManifestPath,
                'block_path' => $embedBlockPath,
                'asset_path' => $embedAssetPath,
                'theme_editor_href' => $themeEditorHref,
            ],
            'web_pixel' => [
                'project_present' => $pixelPresent,
                'manifest_path' => $pixelManifestPath,
                'source_path' => $pixelSourcePath,
                'customer_events_href' => $customerEventsHref,
                'status' => Arr::get($pixelStatus, 'status', 'unknown'),
                'connected' => (bool) Arr::get($pixelStatus, 'connected', false),
                'label' => (string) Arr::get($pixelStatus, 'label', 'Unknown'),
                'message' => (string) Arr::get($pixelStatus, 'message', ''),
                'can_connect' => (bool) Arr::get($pixelStatus, 'can_connect', false),
                'missing_scopes' => array_values((array) Arr::get($pixelStatus, 'missing_scopes', [])),
                'pixel_id' => Arr::get($pixelStatus, 'pixel_id'),
                'settings' => (array) Arr::get($pixelStatus, 'settings', []),
            ],
            'commands' => [
                'dev' => 'npm run shopify:app:dev -- --store modernforestry.myshopify.com',
                'deploy' => 'npm run shopify:app:deploy',
                'info' => 'npm run shopify:app:info',
            ],
            'actions' => [
                'theme_editor_href' => $themeEditorHref,
                'customer_events_href' => $customerEventsHref,
                'extensions_href' => $extensionsDocsHref,
            ],
            'steps' => [
                [
                    'key' => 'storefront_proxy_runtime',
                    'label' => 'Storefront app proxy runtime is ready',
                    'done' => $proxyEnabled && $proxySecretPresent && $signingSecretPresent,
                    'hint' => $proxyEnabled && $proxySecretPresent && $signingSecretPresent
                        ? 'Storefront requests can use /apps/forestry/health and /apps/forestry/funnel/event.'
                        : 'Backstage still needs Shopify app proxy signing config before storefront events can flow safely.',
                ],
                [
                    'key' => 'theme_embed_bundle',
                    'label' => 'Theme app embed bundle is present in this repo',
                    'done' => $embedPresent,
                    'hint' => $embedPresent
                        ? 'Deploy it with `npm run shopify:app:deploy`, then enable Forestry storefront tracking in Theme Editor.'
                        : 'Theme app embed files are missing from the repo.',
                ],
                [
                    'key' => 'web_pixel_bundle',
                    'label' => 'Web pixel bundle is present in this repo',
                    'done' => $pixelPresent,
                    'hint' => $pixelPresent
                        ? 'After deploy, connect the Forestry storefront pixel in Shopify Customer Events so Shopify-side events begin flowing.'
                        : 'Web pixel extension files are missing from the repo.',
                ],
                [
                    'key' => 'web_pixel_connected',
                    'label' => 'Shopify Customer Events pixel is connected',
                    'done' => $pixelPresent && (bool) Arr::get($pixelStatus, 'connected', false),
                    'hint' => $pixelPresent
                        ? (string) Arr::get($pixelStatus, 'message', 'Connect the app pixel in Shopify Customer Events.')
                        : 'Deploy the web pixel extension first.',
                ],
                [
                    'key' => 'theme_editor_enable',
                    'label' => 'Enable Forestry storefront tracking embed in Theme Editor',
                    'done' => false,
                    'hint' => $themeEditorHref !== null
                        ? 'Use the Theme Editor button below, turn the embed on under App embeds, and save the theme.'
                        : 'Open Shopify Theme Editor, go to Theme settings -> App embeds, and enable Forestry storefront tracking.',
                ],
                [
                    'key' => 'verify_storefront_events',
                    'label' => 'Verify a tagged storefront visit creates funnel events',
                    'done' => false,
                    'hint' => 'Open a tracked email-style URL, then confirm Message Analytics detail shows sessions, product views, cart activity, and checkout progression.',
                ],
            ],
        ];
    }

    protected function adminBaseUrl(?string $host): ?string
    {
        $decoded = $this->decodeHost($host);
        if ($decoded === null) {
            return null;
        }

        if (str_starts_with($decoded, 'https://')) {
            return rtrim($decoded, '/');
        }

        return 'https://'.ltrim($decoded, '/');
    }

    protected function decodeHost(?string $host): ?string
    {
        $normalized = trim((string) $host);
        if ($normalized === '') {
            return null;
        }

        $decoded = base64_decode(strtr($normalized, '-_', '+/'), true);
        if (! is_string($decoded) || trim($decoded) === '') {
            return null;
        }

        $decoded = trim($decoded);

        return str_contains($decoded, 'admin.shopify.com/store/') ? $decoded : null;
    }
}
