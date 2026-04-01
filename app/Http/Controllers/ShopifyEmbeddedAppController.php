<?php

namespace App\Http\Controllers;

use App\Services\Search\GlobalSearchCoordinator;
use App\Services\Marketing\CandleCashEarnedReminderService;
use App\Services\Shopify\Dashboard\ShopifyEmbeddedDashboardDataService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantModuleCatalogService;
use App\Services\Tenancy\TenantResolver;
use App\Support\Shopify\ShopifyEmbeddedContextQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ShopifyEmbeddedAppController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function __construct(
        protected ShopifyEmbeddedDashboardDataService $dashboardDataService,
        protected CandleCashEarnedReminderService $candleCashEarnedReminderService
    ) {}

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantDisplayLabelResolver $displayLabelResolver,
        TenantCommercialExperienceService $experienceService
    ): Response {
        $context = $contextService->resolvePageContext($request);
        $fallbackRewardsLabel = $displayLabelResolver->label(null, 'rewards_label', 'Rewards');

        if (($context['status'] ?? '') === 'open_from_shopify') {
            return $this->embeddedResponse(
                response()->view('shopify.dashboard', [
                    'authorized' => false,
                    'status' => 'open_from_shopify',
                    'shopifyApiKey' => null,
                    'shopDomain' => null,
                    'host' => null,
                    'storeLabel' => 'Shopify Admin',
                    'headline' => 'Dashboard',
                    'subheadline' => 'See '.$fallbackRewardsLabel.', customers, and setup status at a glance.',
                    'appNavigation' => $this->embeddedAppNavigation('dashboard', null, null),
                    'pageActions' => [],
                    'pageSubnav' => $this->dashboardExperienceSubnav('overview', null),
                    'dashboardBootstrap' => [
                    'authorized' => false,
                    'status' => 'open_from_shopify',
                    'storeLabel' => 'Shopify Admin',
                        'links' => [],
                        'dataEndpoint' => route('shopify.app.api.dashboard'),
                        'reminderEndpoint' => route('shopify.app.api.dashboard.candle-cash-reminders'),
                        'initialData' => null,
                        'config' => $this->dashboardDataService->payload()['config'],
                    ],
                    'merchantJourney' => null,
                ])
            );
        }

        if (! ($context['ok'] ?? false)) {
            return $this->embeddedResponse(
                response()->view('shopify.dashboard', [
                    'authorized' => false,
                    'status' => 'invalid_request',
                    'shopifyApiKey' => null,
                    'shopDomain' => $context['shop_domain'] ?? null,
                    'host' => $context['host'] ?? null,
                    'storeLabel' => 'Shopify Admin',
                    'headline' => 'Dashboard',
                    'subheadline' => 'See '.$fallbackRewardsLabel.', customers, and setup status at a glance.',
                    'appNavigation' => $this->embeddedAppNavigation('dashboard', null, null),
                    'pageActions' => [],
                    'pageSubnav' => $this->dashboardExperienceSubnav('overview', null),
                    'dashboardBootstrap' => [
                        'authorized' => false,
                        'status' => 'invalid_request',
                        'storeLabel' => 'Shopify Admin',
                        'links' => [],
                        'dataEndpoint' => route('shopify.app.api.dashboard'),
                        'reminderEndpoint' => route('shopify.app.api.dashboard.candle-cash-reminders'),
                        'initialData' => null,
                        'config' => $this->dashboardDataService->payload()['config'],
                    ],
                    'merchantJourney' => null,
                ]),
                401
            );
        }

        /** @var array<string,mixed> $store */
        $store = $context['store'];
        $tenantId = $tenantResolver->resolveTenantIdForStoreContext($store);
        $tenantRewardsLabel = $displayLabelResolver->label($tenantId, 'rewards_label', $fallbackRewardsLabel);
        $dashboardLinks = [
            [
                'label' => $tenantRewardsLabel.' Admin',
                'href' => route('shopify.embedded.rewards', [], false),
            ],
            [
                'label' => 'Customers',
                'href' => route('shopify.app.customers.manage', [], false),
            ],
            [
                'label' => 'Program Settings',
                'href' => route('shopify.app.settings', [], false),
            ],
            [
                'label' => 'Start Here',
                'href' => route('shopify.app.start', [], false),
            ],
            [
                'label' => 'Plans & Add-ons',
                'href' => route('shopify.app.plans', [], false),
            ],
            [
                'label' => 'Integrations',
                'href' => route('shopify.app.integrations', [], false),
            ],
            [
                'label' => 'Birthdays in Backstage',
                'href' => route('birthdays.customers'),
                'external' => true,
            ],
            [
                'label' => 'Marketing Overview',
                'href' => route('marketing.overview'),
                'external' => true,
            ],
        ];
        $dashboardData = $this->dashboardDataService->payload([
            ...$request->query(),
            'tenant_id' => $tenantId,
        ]);
        $merchantJourney = $experienceService->merchantJourneyPayload($tenantId);

        return $this->embeddedResponse(
            response()->view('shopify.dashboard', [
                'authorized' => true,
                'status' => 'ok',
                'shopifyApiKey' => (string) ($store['client_id'] ?? ''),
                'shopDomain' => (string) ($store['shop'] ?? ''),
                'host' => (string) ($context['host'] ?? ''),
                'storeLabel' => ucfirst((string) ($store['key'] ?? 'store')).' Store',
                'headline' => 'Dashboard',
                'subheadline' => 'See '.$tenantRewardsLabel.', customers, and setup status at a glance.',
                'appNavigation' => $this->embeddedAppNavigation('dashboard', null, $tenantId),
                'pageActions' => [],
                'pageSubnav' => $this->dashboardExperienceSubnav('overview', $tenantId),
                'dashboardBootstrap' => [
                    'authorized' => true,
                    'status' => 'ok',
                    'storeLabel' => ucfirst((string) ($store['key'] ?? 'store')).' Store',
                    'links' => $dashboardLinks,
                    'dataEndpoint' => route('shopify.app.api.dashboard'),
                    'reminderEndpoint' => route('shopify.app.api.dashboard.candle-cash-reminders'),
                    'initialData' => $dashboardData,
                    'config' => $dashboardData['config'],
                ],
                'merchantJourney' => $merchantJourney,
            ])
        );
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

    public function startHere(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantCommercialExperienceService $experienceService
    ): Response {
        $context = $contextService->resolvePageContext($request);
        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? $tenantResolver->resolveTenantIdForStoreContext($store)
            : null;

        return $this->embeddedResponse(
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
                'appNavigation' => $this->embeddedAppNavigation('dashboard', null, $tenantId),
                'pageActions' => [],
                'pageSubnav' => $this->dashboardExperienceSubnav('start', $tenantId),
                'onboardingPayload' => $experienceService->onboardingPayload($tenantId),
                'merchantJourney' => $experienceService->merchantJourneyPayload($tenantId),
            ]),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        );
    }

    public function plansAndAddons(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantCommercialExperienceService $experienceService
    ): Response {
        $context = $contextService->resolvePageContext($request);
        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? $tenantResolver->resolveTenantIdForStoreContext($store)
            : null;

        return $this->embeddedResponse(
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
                'appNavigation' => $this->embeddedAppNavigation('dashboard', null, $tenantId),
                'pageActions' => [],
                'pageSubnav' => $this->dashboardExperienceSubnav('plans', $tenantId),
                'plansPayload' => $experienceService->plansPayload($tenantId),
                'merchantJourney' => $experienceService->merchantJourneyPayload($tenantId),
            ]),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        );
    }

    public function integrations(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantCommercialExperienceService $experienceService
    ): Response {
        $context = $contextService->resolvePageContext($request);
        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? $tenantResolver->resolveTenantIdForStoreContext($store)
            : null;

        return $this->embeddedResponse(
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
                'appNavigation' => $this->embeddedAppNavigation('dashboard', null, $tenantId),
                'pageActions' => [],
                'pageSubnav' => $this->dashboardExperienceSubnav('integrations', $tenantId),
                'integrationsPayload' => $experienceService->integrationsPayload($tenantId),
                'merchantJourney' => $experienceService->merchantJourneyPayload($tenantId),
            ]),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        );
    }

    public function moduleStore(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleCatalogService $catalogService
    ): Response {
        $context = $contextService->resolvePageContext($request);
        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? $tenantResolver->resolveTenantIdForStoreContext($store)
            : null;

        return $this->embeddedResponse(
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
                'appNavigation' => $this->embeddedAppNavigation('dashboard', null, $tenantId),
                'pageActions' => [],
                'pageSubnav' => $this->dashboardExperienceSubnav('store', $tenantId),
                'contextToken' => $authorized ? $contextService->issueContextToken($context) : null,
                'moduleStorePayload' => $catalogService->tenantStorePayload($tenantId, 'shopify'),
            ]),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        );
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
                ShopifyEmbeddedContextQuery::appendToUrl(
                    route('shopify.app.store', [], false),
                    ShopifyEmbeddedContextQuery::fromRequest($request, (string) ($context['host'] ?? null))
                )
            )->with('status_error', 'Tenant context is missing for this store.');
        }
        $result = $catalogService->activateModuleForTenant($tenantId, $moduleKey, null, 'shopify_app_store');

        return redirect(
            ShopifyEmbeddedContextQuery::appendToUrl(
                route('shopify.app.store', [], false),
                ShopifyEmbeddedContextQuery::fromRequest($request, (string) ($context['host'] ?? null))
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
                ShopifyEmbeddedContextQuery::appendToUrl(
                    route('shopify.app.store', [], false),
                    ShopifyEmbeddedContextQuery::fromRequest($request, (string) ($context['host'] ?? null))
                )
            )->with('status_error', 'Tenant context is missing for this store.');
        }

        $result = $catalogService->requestModuleAccessForTenant($tenantId, $moduleKey, null, 'shopify_app_store_request');

        return redirect(
            ShopifyEmbeddedContextQuery::appendToUrl(
                route('shopify.app.store', [], false),
                ShopifyEmbeddedContextQuery::fromRequest($request, (string) ($context['host'] ?? null))
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
        $embeddedContext = ShopifyEmbeddedContextQuery::fromRequest($request, (string) ($context['host'] ?? null));
        $appendContext = static function (array $row) use ($embeddedContext): array {
            $url = trim((string) ($row['url'] ?? ''));
            if ($url !== '' && ! str_starts_with($url, 'http')) {
                $row['url'] = ShopifyEmbeddedContextQuery::appendToUrl($url, $embeddedContext);
            }

            return $row;
        };
        $payload['results'] = array_map($appendContext, (array) ($payload['results'] ?? []));
        $payload['groups'] = collect((array) ($payload['groups'] ?? []))
            ->map(fn (array $rows): array => array_map($appendContext, $rows))
            ->all();

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

    /**
     * @return array<int,array{key:string,label:string,href:string,active:bool,module_state?:array<string,mixed>}>
     */
    protected function dashboardExperienceSubnav(string $activeKey, ?int $tenantId): array
    {
        /** @var TenantModuleAccessResolver $resolver */
        $resolver = app(TenantModuleAccessResolver::class);
        $resolved = $resolver->resolveForTenant($tenantId, ['dashboard', 'onboarding', 'integrations']);
        $moduleStates = (array) ($resolved['modules'] ?? []);

        $items = [
            ['key' => 'overview', 'label' => 'Overview', 'href' => route('shopify.app', [], false), 'module_key' => 'dashboard'],
            ['key' => 'start', 'label' => 'Start Here', 'href' => route('shopify.app.start', [], false), 'module_key' => 'onboarding'],
            ['key' => 'plans', 'label' => 'Plans & Add-ons', 'href' => route('shopify.app.plans', [], false), 'module_key' => 'onboarding'],
            ['key' => 'store', 'label' => 'App Store', 'href' => route('shopify.app.store', [], false), 'module_key' => 'onboarding'],
            ['key' => 'integrations', 'label' => 'Integrations', 'href' => route('shopify.app.integrations', [], false), 'module_key' => 'integrations'],
        ];

        return array_map(function (array $item) use ($activeKey, $moduleStates): array {
            $moduleKey = strtolower(trim((string) ($item['module_key'] ?? '')));
            $state = $moduleKey !== '' ? ($moduleStates[$moduleKey] ?? null) : null;
            unset($item['module_key']);

            return array_merge($item, [
                'active' => $item['key'] === $activeKey,
                'module_state' => is_array($state) ? $state : null,
            ]);
        }, $items);
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
            'open_from_shopify' => 'Open the app from Shopify Admin to load this dashboard.',
            'missing_shop' => 'The Shopify shop context is missing from this request.',
            'unknown_shop' => 'This Shopify shop is not mapped to a Backstage store.',
            'invalid_hmac' => 'This Shopify request could not be verified.',
            'missing_api_auth' => 'Shopify Admin verification is unavailable. Reload the dashboard from Shopify Admin and try again.',
            'invalid_session_token' => 'Shopify Admin verification failed. Reload the dashboard from Shopify Admin and try again.',
            'expired_session_token' => 'Your Shopify Admin session expired. Reload the dashboard from Shopify Admin and try again.',
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
