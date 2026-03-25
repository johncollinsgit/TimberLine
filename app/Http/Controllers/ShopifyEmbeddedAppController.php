<?php

namespace App\Http\Controllers;

use App\Services\Marketing\CandleCashEarnedReminderService;
use App\Services\Shopify\Dashboard\ShopifyEmbeddedDashboardDataService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
        TenantResolver $tenantResolver
    ): Response {
        $context = $contextService->resolvePageContext($request);

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
                'subheadline' => 'Rewards performance snapshot',
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
                    'subheadline' => 'Rewards performance snapshot',
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
                ]),
                401
            );
        }

        /** @var array<string,mixed> $store */
        $store = $context['store'];
        $tenantId = $tenantResolver->resolveTenantIdForStoreContext($store);
        $dashboardLinks = [
            [
                'label' => 'Rewards Admin',
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

        return $this->embeddedResponse(
            response()->view('shopify.dashboard', [
                'authorized' => true,
                'status' => 'ok',
                'shopifyApiKey' => (string) ($store['client_id'] ?? ''),
                'shopDomain' => (string) ($store['shop'] ?? ''),
                'host' => (string) ($context['host'] ?? ''),
                'storeLabel' => ucfirst((string) ($store['key'] ?? 'store')).' Store',
                'headline' => 'Dashboard',
                'subheadline' => 'Rewards performance snapshot',
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
                'subheadline' => $this->subheadlineForStatus($status, 'Use this onboarding surface to activate setup-needed modules and track lock/roadmap states.'),
                'appNavigation' => $this->embeddedAppNavigation('dashboard', null, $tenantId),
                'pageActions' => [],
                'pageSubnav' => $this->dashboardExperienceSubnav('start', $tenantId),
                'onboardingPayload' => $experienceService->onboardingPayload($tenantId),
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
                'subheadline' => $this->subheadlineForStatus($status, 'Review current access profile, included modules, add-on candidates, and upgrade placeholders.'),
                'appNavigation' => $this->embeddedAppNavigation('dashboard', null, $tenantId),
                'pageActions' => [],
                'pageSubnav' => $this->dashboardExperienceSubnav('plans', $tenantId),
                'plansPayload' => $experienceService->plansPayload($tenantId),
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
                'subheadline' => $this->subheadlineForStatus($status, 'Review connector availability, fallback paths, and upgrade requirements without triggering live sync behavior.'),
                'appNavigation' => $this->embeddedAppNavigation('dashboard', null, $tenantId),
                'pageActions' => [],
                'pageSubnav' => $this->dashboardExperienceSubnav('integrations', $tenantId),
                'integrationsPayload' => $experienceService->integrationsPayload($tenantId),
            ]),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        );
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
        ];

        return response()->json([
            'ok' => false,
            'message' => $messages[$status] ?? 'This embedded Shopify request could not be verified.',
            'status' => $status,
        ], $status === 'open_from_shopify' ? 400 : 401);
    }
}
