<?php

namespace App\Services\Shopify;

use App\Models\MarketingStorefrontEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class ShopifyStorefrontTrackingSetupService
{
    protected const RECENT_LOOKBACK_DAYS = 7;

    /**
     * @var array<int,string>
     */
    protected array $funnelEventTypes = [
        'session_started',
        'landing_page_viewed',
        'product_viewed',
        'wishlist_added',
        'add_to_cart',
        'checkout_started',
        'checkout_completed',
    ];

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
        $requestedScopes = $this->requestedScopesFromAppConfig($appConfigPath);
        $pixelStatus = $store !== [] ? $this->webPixelConnectionService->status($store) : [
            'ok' => false,
            'status' => 'store_missing',
            'connected' => false,
            'label' => 'Store not resolved',
            'message' => 'Open this page from Shopify Admin to check the app pixel status.',
        ];
        $recentSignal = $store !== [] ? $this->recentStorefrontSignal($store) : $this->emptyRecentSignal();
        $grantedScopes = $this->normalizeScopes(
            (array) Arr::get($pixelStatus, 'granted_scopes', $store['scopes'] ?? [])
        );
        $missingRequestedScopes = $requestedScopes === []
            ? []
            : array_values(array_diff($requestedScopes, $grantedScopes));
        $scopeState = [
            'requested' => $requestedScopes,
            'granted' => $grantedScopes,
            'missing_requested' => $missingRequestedScopes,
            'missing_pixel_required' => array_values((array) Arr::get($pixelStatus, 'missing_scopes', [])),
            'source' => (string) Arr::get($pixelStatus, 'scope_source', 'stored_snapshot'),
            'verified' => (bool) Arr::get($pixelStatus, 'scope_verified', false),
            'lookup_error' => $this->nullableString(Arr::get($pixelStatus, 'scope_lookup_error')),
        ];
        $themeTrackerSignal = (array) Arr::get($recentSignal, 'trackers.theme_app_embed', []);
        $webPixelTrackerSignal = (array) Arr::get($recentSignal, 'trackers.web_pixel', []);
        $unknownTrackerSignal = (array) Arr::get($recentSignal, 'trackers.unknown', []);
        $themeEmbedInferredEnabled = (bool) Arr::get($themeTrackerSignal, 'has_events', false);
        $eventInference = (string) ($recentSignal['inference'] ?? 'configuration_only');

        $themeEditorHref = $adminBaseUrl !== null
            ? $adminBaseUrl.'/themes/current/editor?context=apps'
            : null;
        $customerEventsHref = $adminBaseUrl !== null
            ? $adminBaseUrl.'/settings/customer_events'
            : null;
        $extensionsDocsHref = $adminBaseUrl !== null
            ? $adminBaseUrl.'/apps'
            : null;
        $reconnectHref = filled($store['key'] ?? null)
            ? route('shopify.reinstall', ['store' => (string) $store['key']], false)
            : null;

        $shopifyNative = [
            'requested_scopes' => [
                'read_customer_events' => in_array('read_customer_events', $requestedScopes, true),
                'read_pixels' => in_array('read_pixels', $requestedScopes, true),
                'write_pixels' => in_array('write_pixels', $requestedScopes, true),
                'read_analytics' => in_array('read_analytics', $requestedScopes, true),
                'read_reports' => in_array('read_reports', $requestedScopes, true),
            ],
            'granted_scopes' => [
                'read_customer_events' => in_array('read_customer_events', $grantedScopes, true),
                'read_pixels' => in_array('read_pixels', $grantedScopes, true),
                'write_pixels' => in_array('write_pixels', $grantedScopes, true),
                'read_analytics' => in_array('read_analytics', $grantedScopes, true),
                'read_reports' => in_array('read_reports', $grantedScopes, true),
            ],
            'analytics_and_reports' => [
                'requested' => in_array('read_analytics', $requestedScopes, true) || in_array('read_reports', $requestedScopes, true),
                'granted' => in_array('read_analytics', $grantedScopes, true) && in_array('read_reports', $grantedScopes, true),
                'api_calls_detected' => false,
                'notes' => 'Backstage verifies pixel/scopes in Shopify Admin. No Shopify native analytics/report query flow is wired for storefront funnel reporting yet.',
            ],
            'customer_events' => [
                'pixel_management_api' => true,
                'connected' => (bool) Arr::get($pixelStatus, 'connected', false),
                'status' => (string) Arr::get($pixelStatus, 'status', 'unknown'),
            ],
        ];

        $healthSummary = [
            'setup_inference' => $eventInference,
            'theme_embed' => [
                'bundle_present' => $embedPresent,
                'inferred_enabled' => $themeEmbedInferredEnabled,
                'event_count' => (int) Arr::get($themeTrackerSignal, 'count', 0),
                'last_event_type' => Arr::get($themeTrackerSignal, 'last_event_type'),
                'last_event_at' => Arr::get($themeTrackerSignal, 'last_event_at'),
            ],
            'web_pixel' => [
                'bundle_present' => $pixelPresent,
                'connected' => (bool) Arr::get($pixelStatus, 'connected', false),
                'status' => (string) Arr::get($pixelStatus, 'status', 'unknown'),
                'event_flow_detected' => (bool) Arr::get($webPixelTrackerSignal, 'has_events', false),
                'event_count' => (int) Arr::get($webPixelTrackerSignal, 'count', 0),
                'last_event_type' => Arr::get($webPixelTrackerSignal, 'last_event_type'),
                'last_event_at' => Arr::get($webPixelTrackerSignal, 'last_event_at'),
            ],
            'scopes' => [
                'verified' => (bool) ($scopeState['verified'] ?? false),
                'source' => (string) ($scopeState['source'] ?? 'stored_snapshot'),
                'missing_requested' => (array) ($scopeState['missing_requested'] ?? []),
                'missing_pixel_required' => (array) ($scopeState['missing_pixel_required'] ?? []),
            ],
            'events' => [
                'recent_count' => (int) ($recentSignal['count'] ?? 0),
                'last_event_type' => $recentSignal['last_event_type'] ?? null,
                'last_event_at' => $recentSignal['last_event_at'] ?? null,
                'checkout_completion_seen_recently' => (bool) ($recentSignal['has_recent_checkout_completed'] ?? false),
                'last_checkout_completed_at' => $recentSignal['last_checkout_completed_at'] ?? null,
            ],
            'unknown_tracker_events' => [
                'count' => (int) Arr::get($unknownTrackerSignal, 'count', 0),
                'last_event_type' => Arr::get($unknownTrackerSignal, 'last_event_type'),
                'last_event_at' => Arr::get($unknownTrackerSignal, 'last_event_at'),
            ],
        ];

        return [
            'app_config_present' => $appConfigPresent,
            'scope_state' => $scopeState,
            'shopify_native' => $shopifyNative,
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
                'inferred_enabled' => $themeEmbedInferredEnabled,
                'event_count' => (int) Arr::get($themeTrackerSignal, 'count', 0),
                'last_event_type' => Arr::get($themeTrackerSignal, 'last_event_type'),
                'last_event_at' => Arr::get($themeTrackerSignal, 'last_event_at'),
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
                'can_connect' => $pixelPresent && ! (bool) Arr::get($pixelStatus, 'connected', false),
                'granted_scopes' => $grantedScopes,
                'missing_scopes' => array_values((array) Arr::get($pixelStatus, 'missing_scopes', [])),
                'scope_source' => (string) Arr::get($pixelStatus, 'scope_source', 'stored_snapshot'),
                'scope_verified' => (bool) Arr::get($pixelStatus, 'scope_verified', false),
                'scope_lookup_error' => $this->nullableString(Arr::get($pixelStatus, 'scope_lookup_error')),
                'pixel_id' => Arr::get($pixelStatus, 'pixel_id'),
                'settings' => (array) Arr::get($pixelStatus, 'settings', []),
                'event_flow_detected' => (bool) Arr::get($webPixelTrackerSignal, 'has_events', false),
                'event_count' => (int) Arr::get($webPixelTrackerSignal, 'count', 0),
                'last_event_type' => Arr::get($webPixelTrackerSignal, 'last_event_type'),
                'last_event_at' => Arr::get($webPixelTrackerSignal, 'last_event_at'),
            ],
            'recent_events' => $recentSignal,
            'health_summary' => $healthSummary,
            'commands' => [
                'dev' => 'npm run shopify:app:dev -- --store modernforestry.myshopify.com',
                'deploy' => 'npm run shopify:app:deploy',
                'info' => 'npm run shopify:app:info',
            ],
            'actions' => [
                'theme_editor_href' => $themeEditorHref,
                'customer_events_href' => $customerEventsHref,
                'extensions_href' => $extensionsDocsHref,
                'reconnect_href' => $reconnectHref,
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
                    'done' => $themeEmbedInferredEnabled,
                    'hint' => $themeEmbedInferredEnabled
                        ? sprintf('Verified by %d recent theme embed event(s) for this shop.', (int) Arr::get($themeTrackerSignal, 'count', 0))
                        : ($themeEditorHref !== null
                        ? 'Use the Theme Editor button below, turn the embed on under App embeds, and save the theme.'
                        : 'Open Shopify Theme Editor, go to Theme settings -> App embeds, and enable Forestry storefront tracking.'),
                ],
                [
                    'key' => 'verify_storefront_events',
                    'label' => 'Verify a tagged storefront visit creates funnel events',
                    'done' => (bool) ($recentSignal['has_events'] ?? false),
                    'hint' => (bool) ($recentSignal['has_events'] ?? false)
                        ? sprintf('Backstage has already recorded %d recent storefront funnel event(s) for this shop.', (int) ($recentSignal['count'] ?? 0))
                        : 'Open a tracked email-style URL, then confirm Message Analytics detail shows sessions, product views, cart activity, and checkout progression.',
                ],
            ],
            'tracking_inventory' => $this->trackingInventory($embedPresent, $pixelPresent, $pixelStatus, $recentSignal, $scopeState, $shopifyNative),
        ];
    }

    /**
     * @param  array<string,mixed>  $store
     * @return array<string,mixed>
     */
    protected function recentStorefrontSignal(array $store): array
    {
        if (! Schema::hasTable('marketing_storefront_events')) {
            return $this->emptyRecentSignal();
        }

        $storeKey = strtolower(trim((string) ($store['key'] ?? '')));
        if ($storeKey === '') {
            return $this->emptyRecentSignal();
        }

        $baseQuery = MarketingStorefrontEvent::query()
            ->where('source_type', 'shopify_storefront_funnel')
            ->whereIn('event_type', $this->funnelEventTypes)
            ->where('occurred_at', '>=', now()->subDays(self::RECENT_LOOKBACK_DAYS))
            ->where('meta->store_key', $storeKey);

        $events = (clone $baseQuery)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'event_type',
                'occurred_at',
                'meta',
            ]);

        if ($events->isEmpty()) {
            return $this->emptyRecentSignal();
        }

        $count = $events->count();
        $latest = $events->first();
        $checkoutCompleted = $events
            ->first(fn (MarketingStorefrontEvent $event): bool => (string) $event->event_type === 'checkout_completed');

        $eventTypeCounts = $events
            ->groupBy(fn (MarketingStorefrontEvent $event): string => (string) $event->event_type)
            ->map(fn (Collection $group): int => $group->count())
            ->sortKeys()
            ->all();

        $trackerBuckets = [
            'theme_app_embed' => collect(),
            'web_pixel' => collect(),
            'unknown' => collect(),
        ];

        foreach ($events as $event) {
            $meta = is_array($event->meta ?? null) ? $event->meta : [];
            $tracker = $this->trackerFromMeta($meta);
            if (! array_key_exists($tracker, $trackerBuckets)) {
                $tracker = 'unknown';
            }

            $trackerBuckets[$tracker]->push($event);
        }

        return [
            'has_events' => $count > 0,
            'count' => (int) $count,
            'lookback_days' => self::RECENT_LOOKBACK_DAYS,
            'last_event_type' => $latest?->event_type ? (string) $latest->event_type : null,
            'last_event_at' => $this->isoDateTime($latest?->occurred_at),
            'has_recent_checkout_completed' => $checkoutCompleted !== null,
            'last_checkout_completed_at' => $this->isoDateTime($checkoutCompleted?->occurred_at),
            'event_type_counts' => $eventTypeCounts,
            'trackers' => [
                'theme_app_embed' => $this->trackerSignal($trackerBuckets['theme_app_embed']),
                'web_pixel' => $this->trackerSignal($trackerBuckets['web_pixel']),
                'unknown' => $this->trackerSignal($trackerBuckets['unknown']),
            ],
            'sample' => $events
                ->take(20)
                ->map(function (MarketingStorefrontEvent $event): array {
                    $meta = is_array($event->meta ?? null) ? $event->meta : [];

                    return [
                        'event_type' => (string) $event->event_type,
                        'tracker' => $this->trackerFromMeta($meta),
                        'occurred_at' => $this->isoDateTime($event->occurred_at),
                        'page_path' => $this->nullableString($meta['page_path'] ?? null) ?? $this->nullableString($meta['landing_path'] ?? null),
                        'checkout_token' => $this->nullableString($meta['checkout_token'] ?? null),
                    ];
                })
                ->values()
                ->all(),
            'inference' => 'recent_storefront_events',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function emptyRecentSignal(): array
    {
        return [
            'has_events' => false,
            'count' => 0,
            'lookback_days' => self::RECENT_LOOKBACK_DAYS,
            'last_event_type' => null,
            'last_event_at' => null,
            'has_recent_checkout_completed' => false,
            'last_checkout_completed_at' => null,
            'event_type_counts' => [],
            'trackers' => [
                'theme_app_embed' => $this->trackerSignal(collect()),
                'web_pixel' => $this->trackerSignal(collect()),
                'unknown' => $this->trackerSignal(collect()),
            ],
            'sample' => [],
            'inference' => 'configuration_only',
        ];
    }

    /**
     * @param  Collection<int,MarketingStorefrontEvent>  $events
     * @return array<string,mixed>
     */
    protected function trackerSignal(Collection $events): array
    {
        /** @var MarketingStorefrontEvent|null $latest */
        $latest = $events->first();

        return [
            'has_events' => $events->isNotEmpty(),
            'count' => $events->count(),
            'last_event_type' => $latest?->event_type ? (string) $latest->event_type : null,
            'last_event_at' => $this->isoDateTime($latest?->occurred_at),
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function trackerFromMeta(array $meta): string
    {
        $payloadMeta = is_array($meta['payload_meta'] ?? null) ? $meta['payload_meta'] : [];
        $tracker = strtolower(trim((string) ($payloadMeta['tracker'] ?? '')));

        if ($tracker !== '') {
            return $tracker;
        }

        $via = strtolower(trim((string) data_get($meta, 'properties.via', '')));
        if ($via !== '' && (str_starts_with($via, 'theme_') || str_contains($via, 'wishlist'))) {
            return 'theme_app_embed';
        }

        return 'unknown';
    }

    /**
     * @return array<int,string>
     */
    protected function normalizeScopes(mixed $scopes): array
    {
        if (is_array($scopes)) {
            $values = $scopes;
        } else {
            $values = explode(',', (string) $scopes);
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($scope): string => strtolower(trim((string) $scope)),
            $values
        ))));
    }

    /**
     * @return array<int,string>
     */
    protected function requestedScopesFromAppConfig(string $appConfigPath): array
    {
        if (! is_file($appConfigPath)) {
            return [];
        }

        $contents = (string) file_get_contents($appConfigPath);
        if ($contents === '') {
            return [];
        }

        if (! preg_match('/^\s*scopes\s*=\s*"([^"]*)"/m', $contents, $matches)) {
            return [];
        }

        return $this->normalizeScopes($matches[1] ?? '');
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function isoDateTime(mixed $value): ?string
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return $value->format(\DateTimeInterface::ATOM);
    }

    /**
     * @param  array<string,mixed>  $pixelStatus
     * @param  array<string,mixed>  $recentSignal
     * @param  array<string,mixed>  $scopeState
     * @param  array<string,mixed>  $shopifyNative
     * @return array<int,array<string,mixed>>
     */
    protected function trackingInventory(
        bool $embedPresent,
        bool $pixelPresent,
        array $pixelStatus,
        array $recentSignal,
        array $scopeState,
        array $shopifyNative
    ): array {
        $themeSignal = (array) Arr::get($recentSignal, 'trackers.theme_app_embed', []);
        $webPixelSignal = (array) Arr::get($recentSignal, 'trackers.web_pixel', []);

        return [
            [
                'source' => 'Theme app embed: Forestry tracking',
                'runs_in' => 'Shopify storefront theme (App embeds)',
                'tracks' => ['session_started', 'landing_page_viewed', 'product_viewed', 'wishlist_added', 'add_to_cart', 'checkout_started'],
                'status' => ! $embedPresent
                    ? 'bundle_missing'
                    : ((bool) Arr::get($themeSignal, 'has_events', false) ? 'flow_detected' : 'not_verified'),
                'known_gaps' => [
                    'Checkout completion is not emitted directly by the theme embed path.',
                ],
            ],
            [
                'source' => 'Shopify web pixel extension',
                'runs_in' => 'Shopify Customer Events',
                'tracks' => ['landing_page_viewed', 'product_viewed', 'add_to_cart', 'checkout_started', 'checkout_completed'],
                'status' => ! $pixelPresent
                    ? 'bundle_missing'
                    : ((bool) Arr::get($pixelStatus, 'connected', false) ? 'connected' : (string) Arr::get($pixelStatus, 'status', 'unknown')),
                'known_gaps' => [],
            ],
            [
                'source' => 'Backstage funnel ingestion (/apps/forestry/funnel/event)',
                'runs_in' => 'Backstage app proxy endpoint',
                'tracks' => $this->funnelEventTypes,
                'status' => (bool) ($recentSignal['has_events'] ?? false) ? 'events_recorded' : 'no_recent_events',
                'known_gaps' => [],
            ],
            [
                'source' => 'Message Analytics storefront funnel reporting',
                'runs_in' => 'Backstage embedded admin (Messaging > Analytics detail)',
                'tracks' => ['message-attributed storefront funnel progression', 'checkout_abandoned_candidates'],
                'status' => 'enabled',
                'known_gaps' => [
                    'Checkout abandonment is directional and depends on checkout_started versus checkout_completed signal coverage.',
                ],
            ],
            [
                'source' => 'Shopify native analytics/report scopes',
                'runs_in' => 'Shopify Admin app install scopes',
                'tracks' => ['scope availability only'],
                'status' => ((bool) data_get($shopifyNative, 'analytics_and_reports.granted', false)) ? 'scopes_granted' : 'not_granted_or_unverified',
                'known_gaps' => [
                    'Backstage does not yet query Shopify native analytics/reports APIs for storefront funnel reporting.',
                    'Missing requested scopes: '.implode(', ', (array) ($scopeState['missing_requested'] ?? [])),
                ],
            ],
            [
                'source' => 'Web pixel flow verification (event signals)',
                'runs_in' => 'Backstage event diagnostics',
                'tracks' => ['recent web pixel-originated funnel events'],
                'status' => (bool) Arr::get($webPixelSignal, 'has_events', false) ? 'flow_detected' : 'not_verified',
                'known_gaps' => [],
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
