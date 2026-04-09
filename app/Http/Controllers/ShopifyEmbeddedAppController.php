<?php

namespace App\Http\Controllers;

use App\Services\Search\GlobalSearchCoordinator;
use App\Services\Marketing\CandleCashEarnedReminderService;
use App\Services\Shopify\Dashboard\ShopifyEmbeddedDashboardConfig;
use App\Services\Shopify\Dashboard\ShopifyEmbeddedDashboardDataService;
use App\Services\Shopify\DashboardLite\ShopifyEmbeddedDashboardLiteDataService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedPerformanceProbe;
use App\Services\Shopify\ShopifyEmbeddedShellPayloadBuilder;
use App\Services\Shopify\ShopifyEmbeddedUrlGenerator;
use App\Services\Tenancy\ModernForestryAlphaBootstrapService;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantModuleCatalogService;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ShopifyEmbeddedAppController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function __construct(
        protected ShopifyEmbeddedDashboardDataService $dashboardDataService,
        protected ShopifyEmbeddedDashboardLiteDataService $dashboardLiteDataService,
        protected CandleCashEarnedReminderService $candleCashEarnedReminderService,
        protected ShopifyEmbeddedShellPayloadBuilder $shellPayloadBuilder,
        protected ShopifyEmbeddedUrlGenerator $urlGenerator
    ) {}

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantDisplayLabelResolver $displayLabelResolver,
        TenantCommercialExperienceService $experienceService,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): Response {
        $wantsFullDashboard = filter_var($request->query('full', false), FILTER_VALIDATE_BOOLEAN);
        $probe = $this->embeddedProbe($request);
        $context = $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
        $fallbackRewardsLabel = $probe->time('page_payload', fn (): string => $displayLabelResolver->label(null, 'rewards_label', 'Rewards'));

        if (($context['status'] ?? '') === 'open_from_shopify') {
            $dashboardConfig = $probe->time('page_payload', fn (): array => app(ShopifyEmbeddedDashboardConfig::class)->payload());
            $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('home', null, null));

            $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
                response()->view('shopify.dashboard', [
                    'authorized' => false,
                    'status' => 'open_from_shopify',
                    'shopifyApiKey' => null,
                    'shopDomain' => null,
                    'host' => null,
                    'storeLabel' => 'Shopify Admin',
                    'headline' => 'Dashboard',
                    'subheadline' => 'Revenue and setup at a glance.',
                    'appNavigation' => $appNavigation,
                    'pageActions' => [],
                    'pageSubnav' => [],
                    'dashboardBootstrap' => [
                        'authorized' => false,
                        'status' => 'open_from_shopify',
                        'storeLabel' => 'Shopify Admin',
                        'links' => [],
                        'dataEndpoint' => route('shopify.app.api.dashboard'),
                        'reminderEndpoint' => route('shopify.app.api.dashboard.candle-cash-reminders'),
                        'initialData' => null,
                        'config' => $dashboardConfig,
                    ],
                    'merchantJourney' => null,
                ])
            ));

            return $probe->addContext([
                'authorized' => false,
                'status' => 'open_from_shopify',
            ])->finish($response);
        }

        if (! ($context['ok'] ?? false)) {
            $dashboardConfig = $probe->time('page_payload', fn (): array => app(ShopifyEmbeddedDashboardConfig::class)->payload());
            $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('home', null, null));

            $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
                response()->view('shopify.dashboard', [
                    'authorized' => false,
                    'status' => 'invalid_request',
                    'shopifyApiKey' => null,
                    'shopDomain' => $context['shop_domain'] ?? null,
                    'host' => $context['host'] ?? null,
                    'storeLabel' => 'Shopify Admin',
                    'headline' => 'Dashboard',
                    'subheadline' => 'Revenue and setup at a glance.',
                    'appNavigation' => $appNavigation,
                    'pageActions' => [],
                    'pageSubnav' => [],
                    'dashboardBootstrap' => [
                        'authorized' => false,
                        'status' => 'invalid_request',
                        'storeLabel' => 'Shopify Admin',
                        'links' => [],
                        'dataEndpoint' => route('shopify.app.api.dashboard'),
                        'reminderEndpoint' => route('shopify.app.api.dashboard.candle-cash-reminders'),
                        'initialData' => null,
                        'config' => $dashboardConfig,
                    ],
                    'merchantJourney' => null,
                ]),
                401
            ));

            return $probe->addContext([
                'authorized' => false,
                'status' => (string) ($context['status'] ?? 'invalid_request'),
            ])->finish($response);
        }

        /** @var array<string,mixed> $store */
        $store = $context['store'];
        $tenantId = $probe->time('tenant_resolve', fn (): ?int => $tenantResolver->resolveTenantIdForStoreContext($store));
        // Avoid doing large alpha bootstrap upserts on the lightweight dashboard view.
        // Other embedded pages still run this bootstrap as needed.
        if ($tenantId !== null && $wantsFullDashboard) {
            $probe->time('alpha_defaults', fn (): array => $alphaBootstrapService->ensureForTenant($tenantId, (string) ($store['key'] ?? '')));
        }
        $probe->forTenant($tenantId);

        $tenantRewardsLabel = $probe->time('page_payload', fn (): string => $displayLabelResolver->label($tenantId, 'rewards_label', $fallbackRewardsLabel));
        $dashboardLinks = [
            [
                'label' => 'Customers',
                'href' => route('shopify.app.customers.manage', [], false),
            ],
            [
                'label' => $tenantRewardsLabel,
                'href' => route('shopify.app.rewards', [], false),
            ],
            [
                'label' => 'Open settings',
                'href' => route('shopify.app.settings', [], false),
            ],
        ];
        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('home', null, $tenantId));
        $view = $wantsFullDashboard ? 'shopify.dashboard' : 'shopify.dashboard-lite';
        $subheadline = $wantsFullDashboard
            ? 'Revenue and setup at a glance.'
            : 'Fast loyalty snapshot for recent program activity.';
        $dashboardConfig = $wantsFullDashboard
            ? $probe->time('page_payload', fn (): array => app(ShopifyEmbeddedDashboardConfig::class)->payload())
            : [];

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view($view, [
                'authorized' => true,
                'status' => 'ok',
                'shopifyApiKey' => (string) ($store['client_id'] ?? ''),
                'shopDomain' => (string) ($store['shop'] ?? ''),
                'host' => (string) ($context['host'] ?? ''),
                'storeLabel' => ucfirst((string) ($store['key'] ?? 'store')).' Store',
                'headline' => 'Dashboard',
                'subheadline' => $subheadline,
                'appNavigation' => $appNavigation,
                'pageActions' => [],
                'pageSubnav' => [],
                'dashboardBootstrap' => [
                    'authorized' => true,
                    'status' => 'ok',
                    'storeLabel' => ucfirst((string) ($store['key'] ?? 'store')).' Store',
                    'links' => $dashboardLinks,
                    'dataEndpoint' => route('shopify.app.api.dashboard'),
                    'reminderEndpoint' => route('shopify.app.api.dashboard.candle-cash-reminders'),
                    'initialData' => null,
                    'config' => $dashboardConfig,
                ],
                'merchantJourney' => null,
            ])
        ));

        return $probe->addContext([
            'authorized' => true,
            'status' => 'ok',
        ])->finish($response);
    }

    public function data(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);

        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $tenantId = $tenantResolver->resolveTenantIdForStoreContext((array) ($context['store'] ?? []));

        return response()->json([
            'ok' => true,
            'data' => $this->dashboardDataService->payload([
                ...$request->query(),
                'tenant_id' => $tenantId,
            ]),
        ]);
    }

    public function liteData(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): JsonResponse {
        $startedAt = microtime(true);
        $probe = $this->embeddedProbe($request);
        $context = $probe->time('context', fn (): array => $contextService->resolveAuthenticatedApiContext($request));

        if (! ($context['ok'] ?? false)) {
            /** @var JsonResponse $invalid */
            $invalid = $this->invalidContextResponse($context);

            return $probe->finish($invalid);
        }

        $tenantId = $probe->time('tenant_resolve', fn (): ?int => $tenantResolver->resolveTenantIdForStoreContext((array) ($context['store'] ?? [])));
        $probe->forTenant($tenantId);

        $storeKey = strtolower(trim((string) data_get($context, 'store.key', ''))) ?: null;
        $storeTimezone = trim((string) data_get($context, 'store.timezone', ''));
        $storeTimezone = $storeTimezone !== '' ? $storeTimezone : null;

        $range = (string) $request->query('range', 'today');
        $section = (string) $request->query('section', 'summary');
        $limit = (int) $request->query('limit', 20);

        $data = $probe->time('page_payload', fn (): array => $this->dashboardLiteDataService->payload([
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'timezone' => $storeTimezone,
            'range' => $range,
            'section' => $section,
            'limit' => $limit,
        ]));

        $response = response()->json([
            'ok' => true,
            'data' => $data,
        ]);

        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
        $timingValue = sprintf('dashboard-lite;dur=%s', number_format($durationMs, 2, '.', ''));
        $existingTiming = trim((string) $response->headers->get('Server-Timing', ''));
        $response->headers->set('Server-Timing', $existingTiming !== '' ? ($existingTiming . ', ' . $timingValue) : $timingValue);

        // Light-weight signal for debugging in the browser network panel.
        $response->headers->set('X-Backstage-Dashboard-Lite-Range', strtolower(trim($range)));
        $response->headers->set('X-Backstage-Dashboard-Lite-Section', strtolower(trim($section)));
        if ($storeKey) {
            $response->headers->set('X-Backstage-Dashboard-Lite-Store', $storeKey);
        }
        if ($storeTimezone) {
            $response->headers->set('X-Backstage-Dashboard-Lite-Timezone', $storeTimezone);
        }

        $debugRequested = filter_var($request->query('debug', false), FILTER_VALIDATE_BOOLEAN);
        if ($debugRequested || $durationMs >= 250) {
            Log::info('shopify.embedded.dashboard_lite', [
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'timezone' => $storeTimezone,
                'range' => strtolower(trim($range)),
                'section' => strtolower(trim($section)),
                'duration_ms' => $durationMs,
                'cache' => (array) data_get($data, 'meta.cache', []),
            ]);
        }

        return $probe->finish($response);
    }

    public function startHere(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantCommercialExperienceService $experienceService
    ): Response {
        $probe = $this->embeddedProbe($request);
        $context = $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? $probe->time('tenant_resolve', fn (): ?int => $tenantResolver->resolveTenantIdForStoreContext($store))
            : null;
        $probe->forTenant($tenantId);

        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('home', null, $tenantId));
        $pageSubnav = $probe->time('shell_payload', fn (): array => $this->dashboardExperienceSubnav('start', $tenantId));
        $onboardingPayload = $authorized
            ? $probe->time('page_payload', fn (): array => $experienceService->onboardingPayload($tenantId))
            : null;
        $merchantJourney = $authorized
            ? $probe->time('page_payload', fn (): array => $experienceService->merchantJourneyPayload($tenantId))
            : null;

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.start-here', [
                'authorized' => $authorized,
                'status' => $status,
                'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
                'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
                'host' => $authorized ? (string) ($context['host'] ?? '') : ($context['host'] ?? null),
                'storeLabel' => $authorized
                    ? ucfirst((string) ($store['key'] ?? 'store')).' Store'
                    : 'Shopify Admin',
                'headline' => $this->headlineForStatus($status, 'Start Here'),
                'subheadline' => $this->subheadlineForStatus($status, 'Use this page to finish setup and see which modules are active, locked, or coming soon.'),
                'appNavigation' => $appNavigation,
                'pageActions' => [],
                'pageSubnav' => $pageSubnav,
                'onboardingPayload' => $onboardingPayload,
                'merchantJourney' => $merchantJourney,
            ]),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        ));

        return $probe->addContext([
            'authorized' => $authorized,
            'status' => $status,
        ])->finish($response);
    }

    public function plansAndAddons(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantCommercialExperienceService $experienceService
    ): Response {
        $probe = $this->embeddedProbe($request);
        $context = $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? $probe->time('tenant_resolve', fn (): ?int => $tenantResolver->resolveTenantIdForStoreContext($store))
            : null;
        $probe->forTenant($tenantId);

        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('home', null, $tenantId));
        $pageSubnav = $probe->time('shell_payload', fn (): array => $this->dashboardExperienceSubnav('plans', $tenantId));
        $plansPayload = $authorized
            ? $probe->time('page_payload', fn (): array => $experienceService->plansPayload($tenantId))
            : null;
        $merchantJourney = $authorized
            ? $probe->time('page_payload', fn (): array => $experienceService->merchantJourneyPayload($tenantId))
            : null;

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.plans-addons', [
                'authorized' => $authorized,
                'status' => $status,
                'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
                'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
                'host' => $authorized ? (string) ($context['host'] ?? '') : ($context['host'] ?? null),
                'storeLabel' => $authorized
                    ? ucfirst((string) ($store['key'] ?? 'store')).' Store'
                    : 'Shopify Admin',
                'headline' => $this->headlineForStatus($status, 'Plans & Add-ons'),
                'subheadline' => $this->subheadlineForStatus($status, 'Review your current plan, available add-ons, and module access in plain language.'),
                'appNavigation' => $appNavigation,
                'pageActions' => [],
                'pageSubnav' => $pageSubnav,
                'plansPayload' => $plansPayload,
                'merchantJourney' => $merchantJourney,
            ]),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        ));

        return $probe->addContext([
            'authorized' => $authorized,
            'status' => $status,
        ])->finish($response);
    }

    public function integrations(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantCommercialExperienceService $experienceService
    ): Response {
        $probe = $this->embeddedProbe($request);
        $context = $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? $probe->time('tenant_resolve', fn (): ?int => $tenantResolver->resolveTenantIdForStoreContext($store))
            : null;
        $probe->forTenant($tenantId);

        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('home', null, $tenantId));
        $pageSubnav = $probe->time('shell_payload', fn (): array => $this->dashboardExperienceSubnav('integrations', $tenantId));
        $integrationsPayload = $authorized
            ? $probe->time('page_payload', fn (): array => $experienceService->integrationsPayload($tenantId))
            : null;
        $merchantJourney = $authorized
            ? $probe->time('page_payload', fn (): array => $experienceService->merchantJourneyPayload($tenantId))
            : null;

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.integrations', [
                'authorized' => $authorized,
                'status' => $status,
                'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
                'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
                'host' => $authorized ? (string) ($context['host'] ?? '') : ($context['host'] ?? null),
                'storeLabel' => $authorized
                    ? ucfirst((string) ($store['key'] ?? 'store')).' Store'
                    : 'Shopify Admin',
                'headline' => $this->headlineForStatus($status, 'Integrations'),
                'subheadline' => $this->subheadlineForStatus($status, 'See which connections are ready, what still needs setup, and safe fallback options.'),
                'appNavigation' => $appNavigation,
                'pageActions' => [],
                'pageSubnav' => $pageSubnav,
                'integrationsPayload' => $integrationsPayload,
                'merchantJourney' => $merchantJourney,
            ]),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        ));

        return $probe->addContext([
            'authorized' => $authorized,
            'status' => $status,
        ])->finish($response);
    }

    public function moduleStore(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleCatalogService $catalogService
    ): Response {
        $probe = $this->embeddedProbe($request);
        $context = $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? $probe->time('tenant_resolve', fn (): ?int => $tenantResolver->resolveTenantIdForStoreContext($store))
            : null;
        $probe->forTenant($tenantId);

        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('home', null, $tenantId));
        $pageSubnav = $probe->time('shell_payload', fn (): array => $this->dashboardExperienceSubnav('store', $tenantId));
        $moduleStorePayload = $authorized
            ? $probe->time('page_payload', fn (): array => $catalogService->tenantStorePayload($tenantId, 'shopify'))
            : null;

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.app-store', [
                'authorized' => $authorized,
                'status' => $status,
                'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
                'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
                'host' => $authorized ? (string) ($context['host'] ?? '') : ($context['host'] ?? null),
                'storeLabel' => $authorized
                    ? ucfirst((string) ($store['key'] ?? 'store')).' Store'
                    : 'Shopify Admin',
                'headline' => $this->headlineForStatus($status, 'App Store'),
                'subheadline' => $this->subheadlineForStatus($status, 'Discover active modules, add-ons, and upgrade paths from a single catalog surface.'),
                'appNavigation' => $appNavigation,
                'pageActions' => [],
                'pageSubnav' => $pageSubnav,
                'contextToken' => $authorized ? $contextService->issueContextToken($context) : null,
                'moduleStorePayload' => $moduleStorePayload,
            ]),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        ));

        return $probe->addContext([
            'authorized' => $authorized,
            'status' => $status,
        ])->finish($response);
    }

    public function activateModule(
        Request $request,
        string $moduleKey,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleCatalogService $catalogService
    ) {
        $context = $contextService->resolveMutationContext($request);
        if (! (bool) ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $request->validate([
            'moduleKey' => ['nullable', 'string', 'max:120'],
        ]);

        $store = (array) ($context['store'] ?? []);
        $tenantId = $tenantResolver->resolveTenantIdForStoreContext($store);
        if ($tenantId === null) {
            Log::warning('shopify.app_store.activate.blocked_missing_tenant', [
                'module_key' => strtolower(trim($moduleKey)),
                'store_key' => (string) ($store['key'] ?? ''),
                'shop_domain' => (string) ($context['shop_domain'] ?? ''),
            ]);

            return redirect(
                $this->urlGenerator->redirectToRoute(
                    'shopify.app.store',
                    [],
                    $request,
                    (string) ($context['host'] ?? null)
                )
            )->with('status_error', 'Tenant context is missing for this store.');
        }
        $result = $catalogService->activateModuleForTenant($tenantId, $moduleKey, null, 'shopify_app_store');

        return redirect(
            $this->urlGenerator->redirectToRoute(
                'shopify.app.store',
                [],
                $request,
                (string) ($context['host'] ?? null)
            )
        )->with(($result['ok'] ?? false) ? 'status' : 'status_error', (string) ($result['message'] ?? 'Module action completed.'));
    }

    public function requestModuleAccess(
        Request $request,
        string $moduleKey,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleCatalogService $catalogService
    ) {
        $context = $contextService->resolveMutationContext($request);
        if (! (bool) ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $request->validate([
            'moduleKey' => ['nullable', 'string', 'max:120'],
        ]);

        $store = (array) ($context['store'] ?? []);
        $tenantId = $tenantResolver->resolveTenantIdForStoreContext($store);
        if ($tenantId === null) {
            Log::warning('shopify.app_store.request.blocked_missing_tenant', [
                'module_key' => strtolower(trim($moduleKey)),
                'store_key' => (string) ($store['key'] ?? ''),
                'shop_domain' => (string) ($context['shop_domain'] ?? ''),
            ]);

            return redirect(
                $this->urlGenerator->redirectToRoute(
                    'shopify.app.store',
                    [],
                    $request,
                    (string) ($context['host'] ?? null)
                )
            )->with('status_error', 'Tenant context is missing for this store.');
        }

        $result = $catalogService->requestModuleAccessForTenant($tenantId, $moduleKey, null, 'shopify_app_store_request');

        return redirect(
            $this->urlGenerator->redirectToRoute(
                'shopify.app.store',
                [],
                $request,
                (string) ($context['host'] ?? null)
            )
        )->with(($result['ok'] ?? false) ? 'status' : 'status_error', (string) ($result['message'] ?? 'Module request completed.'));
    }

    public function sendCandleCashEarnedReminders(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $tenantId = $tenantResolver->resolveTenantIdForStoreContext((array) ($context['store'] ?? []));
        $result = $this->candleCashEarnedReminderService->sendManualBatch([
            'limit' => $data['limit'] ?? null,
            'dry_run' => (bool) ($data['dry_run'] ?? false),
            'actor_id' => auth()->id(),
            'tenant_id' => $tenantId,
        ]);

        if ((bool) ($result['blocked'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => (string) ($result['message'] ?? 'Reminder send is blocked by email readiness state.'),
                'data' => $result,
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => (string) ($result['message'] ?? 'Reminder send attempted.'),
            'data' => $result,
        ]);
    }

    public function search(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        GlobalSearchCoordinator $searchCoordinator
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            $context = $contextService->resolvePageContext($request);
        }

        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $tenantId = $tenantResolver->resolveTenantIdForStoreContext((array) ($context['store'] ?? []));
        $payload = $searchCoordinator->search(
            (string) ($validated['q'] ?? ''),
            [
                'tenant_id' => $tenantId,
                'user' => $request->user(),
                'request' => $request,
                'surface' => 'shopify',
                'limit' => $validated['limit'] ?? 10,
            ]
        );
        $embeddedNavigationResults = $this->embeddedSearchResults((string) ($validated['q'] ?? ''), $tenantId, $request);
        $embeddedContext = $this->urlGenerator->contextQuery($request, (string) ($context['host'] ?? null));
        $appendContext = function (array $row) use ($embeddedContext): array {
            $url = trim((string) ($row['url'] ?? ''));
            if ($url !== '' && ! str_starts_with($url, 'http')) {
                $row['url'] = $this->urlGenerator->append($url, $embeddedContext);
            }

            return $row;
        };
        $payload['results'] = collect(array_merge(
            $embeddedNavigationResults,
            (array) ($payload['results'] ?? [])
        ))
            ->map($appendContext)
            ->unique(fn (array $row): string => strtolower(trim((string) ($row['title'] ?? '')).'|'.trim((string) ($row['url'] ?? ''))))
            ->sortByDesc(fn (array $row): int => (int) ($row['score'] ?? 0))
            ->take($validated['limit'] ?? 10)
            ->values()
            ->all();
        $payload['groups'] = collect((array) $payload['results'])
            ->groupBy(fn (array $row): string => (string) ($row['type'] ?? 'other'))
            ->map(fn ($rows): array => $rows->values()->all())
            ->all();
        $payload['total'] = count((array) $payload['results']);
        $payload['empty_state'] = $payload['total'] === 0
            ? [
                'title' => 'No exact match yet',
                'subtitle' => 'Try a customer name, section name, sync status, or settings keyword.',
            ]
            : null;

        return response()->json($payload);
    }

    protected function embeddedResponse(Response $response, int $status = 200): Response
    {
        $response->setStatusCode($status);
        $response->headers->set(
            'Content-Security-Policy',
            'frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;'
        );
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    protected function embeddedProbe(Request $request): ShopifyEmbeddedPerformanceProbe
    {
        /** @var ShopifyEmbeddedPerformanceProbe $probe */
        $probe = app(ShopifyEmbeddedPerformanceProbe::class);

        return $probe->forRequest($request);
    }

    /**
     * @return array<int,array{key:string,label:string,href:string,active:bool,module_state?:array<string,mixed>}>
     */
    protected function dashboardExperienceSubnav(string $activeKey, ?int $tenantId): array
    {
        return $this->shellPayloadBuilder->dashboardSubnav($activeKey, $tenantId, request());
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function embeddedSearchResults(string $query, ?int $tenantId, ?Request $request = null): array
    {
        return $this->shellPayloadBuilder->embeddedSearchResults($query, $tenantId, $request);
    }

    protected function headlineForStatus(string $status, string $defaultHeadline): string
    {
        return match ($status) {
            'open_from_shopify' => 'Open this app from Shopify Admin',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'We could not verify this Shopify request',
            default => $defaultHeadline,
        };
    }

    protected function subheadlineForStatus(string $status, string $defaultSubheadline): string
    {
        return match ($status) {
            'open_from_shopify' => 'This page is meant to load inside Shopify Admin so store context and module access state can be verified.',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'Open the app again from Shopify Admin. If this repeats, verify store app configuration and embedded auth context.',
            default => $defaultSubheadline,
        };
    }

    protected function invalidContextResponse(array $context): JsonResponse
    {
        $status = (string) ($context['status'] ?? 'invalid_request');

        $messages = [
            'open_from_shopify' => 'Open the app from Shopify Admin to load Home.',
            'missing_shop' => 'The Shopify shop context is missing from this request.',
            'unknown_shop' => 'This Shopify shop is not mapped to a Backstage store.',
            'invalid_hmac' => 'This Shopify request could not be verified.',
            'missing_api_auth' => 'Shopify Admin verification is unavailable. Reload Home from Shopify Admin and try again.',
            'invalid_session_token' => 'Shopify Admin verification failed. Reload Home from Shopify Admin and try again.',
            'expired_session_token' => 'Your Shopify Admin session expired. Reload Home from Shopify Admin and try again.',
            'missing_context_token' => 'This embedded action is missing its page context token. Reload the App Store and try again.',
            'invalid_context_token' => 'This embedded action could not be matched to the current Shopify page context.',
        ];

        return response()->json([
            'ok' => false,
            'message' => $messages[$status] ?? 'This embedded Shopify request could not be verified.',
            'status' => $status,
        ], $status === 'open_from_shopify' ? 400 : 401);
    }
}
