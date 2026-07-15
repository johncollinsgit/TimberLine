<?php

namespace App\Http\Controllers;

use App\Jobs\RunWholesaleProspectDiscovery;
use App\Models\CustomerAccessRequest;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantWholesaleSetting;
use App\Models\User;
use App\Models\WholesaleFollowUp;
use App\Models\WholesaleProspect;
use App\Models\WholesaleProspectDiscoveryRun;
use App\Models\WholesaleSuggestion;
use App\Services\Marketing\CandleCashEarnedReminderService;
use App\Services\Onboarding\CustomerAccessApprovalService;
use App\Services\Search\GlobalSearchCoordinator;
use App\Services\Shopify\Dashboard\ShopifyEmbeddedDashboardConfig;
use App\Services\Shopify\Dashboard\ShopifyEmbeddedDashboardDataService;
use App\Services\Shopify\DashboardLite\ShopifyEmbeddedDashboardLiteDataService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedAppCredentials;
use App\Services\Shopify\ShopifyEmbeddedPerformanceProbe;
use App\Services\Shopify\ShopifyEmbeddedShellPayloadBuilder;
use App\Services\Shopify\ShopifyEmbeddedUrlGenerator;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Services\Tenancy\ModernForestryAlphaBootstrapService;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantModuleCatalogService;
use App\Services\Tenancy\TenantResolver;
use App\Services\Wholesale\WholesaleModuleSetupService;
use App\Services\Wholesale\WholesaleOperationsService;
use App\Services\Wholesale\WholesaleProspectWorkflowService;
use App\Services\Wholesale\WholesaleSuggestionDecisionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        $context = null;

        try {
            $context = $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
            $fallbackRewardsLabel = $probe->time('page_payload', fn (): string => $displayLabelResolver->label(null, 'rewards_label', 'Rewards'));
            $hintedStore = $this->hintedBootstrapStore($request, $context);

            if ($hintedStore !== null) {
                $view = $wantsFullDashboard ? 'shopify.dashboard' : 'shopify.dashboard-lite';
                $dashboardConfig = $wantsFullDashboard
                    ? $probe->time('page_payload', fn (): array => app(ShopifyEmbeddedDashboardConfig::class)->payload())
                    : [];

                $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
                    response()->view($view, [
                        'authorized' => true,
                        'status' => 'bootstrap_pending',
                        'shopifyApiKey' => (string) ($hintedStore['client_id'] ?? ''),
                        'shopDomain' => (string) ($hintedStore['shop'] ?? ''),
                        'host' => (string) ($request->query('host') ?? ''),
                        'storeLabel' => ucfirst((string) ($hintedStore['key'] ?? 'store')).' Store',
                        'headline' => 'Dashboard',
                        'subheadline' => $wantsFullDashboard
                            ? 'Revenue and setup at a glance.'
                            : 'Fast loyalty snapshot for recent program activity.',
                        'appNavigation' => $this->fallbackEmbeddedNavigation(),
                        'pageActions' => [],
                        'pageSubnav' => [],
                        'dashboardBootstrap' => [
                            'authorized' => true,
                            'status' => 'bootstrap_pending',
                            'storeLabel' => ucfirst((string) ($hintedStore['key'] ?? 'store')).' Store',
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
                    'authorized' => true,
                    'status' => 'bootstrap_pending',
                ])->finish($response);
            }

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
        } catch (Throwable $exception) {
            Log::error('shopify.embedded.dashboard_show_failed', [
                'shop_domain' => (string) ($request->query('shop', '')),
                'host' => (string) ($request->query('host', '')),
                'full' => $wantsFullDashboard,
                'store_key' => (string) data_get($context, 'store.key', ''),
                'status' => (string) data_get($context, 'status', 'unknown'),
                'exception' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            $store = is_array(data_get($context, 'store')) ? (array) data_get($context, 'store') : [];
            $authorized = (bool) data_get($context, 'ok', false) && $store !== [];
            $view = $wantsFullDashboard ? 'shopify.dashboard' : 'shopify.dashboard-lite';

            $response = $this->embeddedResponse(response()->view($view, [
                'authorized' => $authorized,
                'status' => 'runtime_fallback',
                'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
                'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ((string) data_get($context, 'shop_domain', '') ?: null),
                'host' => $authorized ? (string) data_get($context, 'host', '') : ((string) data_get($context, 'host', '') ?: null),
                'storeLabel' => $authorized
                    ? ucfirst((string) ($store['key'] ?? 'store')).' Store'
                    : 'Shopify Admin',
                'headline' => 'Dashboard',
                'subheadline' => 'Temporary recovery mode while we finish loading store data.',
                'appNavigation' => $this->fallbackEmbeddedNavigation(),
                'pageActions' => [],
                'pageSubnav' => [],
                'dashboardBootstrap' => [
                    'authorized' => $authorized,
                    'status' => 'runtime_fallback',
                    'storeLabel' => $authorized
                        ? ucfirst((string) ($store['key'] ?? 'store')).' Store'
                        : 'Shopify Admin',
                    'links' => [],
                    'dataEndpoint' => route('shopify.app.api.dashboard'),
                    'reminderEndpoint' => route('shopify.app.api.dashboard.candle-cash-reminders'),
                    'initialData' => null,
                    'config' => [],
                ],
                'merchantJourney' => null,
            ]));

            return $probe->addContext([
                'authorized' => $authorized,
                'status' => 'runtime_fallback',
            ])->finish($response);
        }
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

        try {
            $data = $probe->time('page_payload', fn (): array => $this->dashboardLiteDataService->payload([
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'timezone' => $storeTimezone,
                'range' => $range,
                'section' => $section,
                'limit' => $limit,
            ]));
        } catch (Throwable $exception) {
            Log::error('shopify.embedded.dashboard_lite_failed', [
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'timezone' => $storeTimezone,
                'range' => strtolower(trim($range)),
                'section' => strtolower(trim($section)),
                'exception' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            $data = $this->emptyDashboardLitePayload($range, $storeKey, $storeTimezone, true);
        }

        $response = response()->json([
            'ok' => true,
            'data' => $data,
        ]);

        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
        $timingValue = sprintf('dashboard-lite;dur=%s', number_format($durationMs, 2, '.', ''));
        $existingTiming = trim((string) $response->headers->get('Server-Timing', ''));
        $response->headers->set('Server-Timing', $existingTiming !== '' ? ($existingTiming.', '.$timingValue) : $timingValue);

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

    /**
     * @return array<string,mixed>
     */
    protected function fallbackEmbeddedNavigation(): array
    {
        return [
            'items' => [],
            'activeSection' => 'home',
            'activeChild' => null,
            'moduleStates' => [],
            'tenantId' => null,
            'displayLabels' => [],
            'workspaceLabel' => 'Commerce',
            'commandSearchEndpoint' => route('shopify.app.api.search'),
            'commandSearchPlaceholder' => 'Search the workspace or jump to a task',
            'commandSearchDocuments' => [],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $context
     * @return array<string,mixed>|null
     */
    protected function hintedBootstrapStore(Request $request, ?array $context = null): ?array
    {
        $status = strtolower(trim((string) data_get($context, 'status', '')));
        if (! in_array($status, ['open_from_shopify', 'missing_shop', 'unknown_shop', 'invalid_hmac'], true)) {
            return null;
        }

        $storeKey = strtolower(trim((string) $request->query('store_key', '')));
        if ($storeKey === '') {
            return null;
        }

        $store = ShopifyStores::find($storeKey, true);
        if (! is_array($store)) {
            return null;
        }

        $clientId = trim((string) ($store['client_id'] ?? ''));
        $shopDomain = trim((string) ($store['shop'] ?? ''));

        if ($clientId === '' || $shopDomain === '') {
            return null;
        }

        return $store;
    }

    public function showWholesale(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        WholesaleOperationsService $operations
    ): Response {
        $probe = $this->embeddedProbe($request);
        $resolved = $probe->time('context', fn (): array => $this->resolveWholesaleWorkspaceContext($request, $contextService));
        $tenantId = $this->resolvedWholesaleTenantId($resolved, $tenantResolver);
        $workspaceState = $this->wholesaleAuthorizationState($resolved, $tenantId);
        $probe->forTenant($tenantId);

        $payload = ($resolved['authorized'] ?? false) && $tenantId !== null
            ? $probe->time('page_payload', fn (): array => $operations->overview($tenantId))
            : null;

        if (is_array($payload) && $tenantId !== null) {
            $payload['attention']['applications'] = $this->countWholesaleApplicationsByStatus('pending', Tenant::query()->find($tenantId));
            $payload['attention']['suggestions'] = WholesaleSuggestion::query()->forAllTenants()
                ->where('tenant_id', $tenantId)->whereIn('status', ['pending', 'account_review', 'data_review'])->count();
            $payload['attention']['follow_ups_due'] = WholesaleFollowUp::query()->forAllTenants()
                ->where('tenant_id', $tenantId)->whereIn('status', ['open', 'in_progress'])->where('due_at', '<=', now()->endOfDay())->count();
        }

        $actor = $this->resolveWholesaleWorkspaceActor($resolved['context'] ?? null, $tenantId);

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.wholesale-overview', [
                'authorized' => $workspaceState['authorized'],
                'status' => $workspaceState['status'],
                'shopifyApiKey' => $resolved['shopifyApiKey'] ?? null,
                'shopDomain' => $resolved['shopDomain'] ?? null,
                'host' => $resolved['host'] ?? null,
                'storeLabel' => $resolved['storeLabel'] ?? 'Wholesale Store',
                'headline' => $this->headlineForStatus($workspaceState['status'], 'Wholesale Operations'),
                'subheadline' => $this->subheadlineForStatus($workspaceState['status'], 'Review customer performance, reorder opportunities, follow-ups, risks, and wholesale sales suggestions.'),
                'appNavigation' => $this->wholesaleEmbeddedNavigation($tenantId, 'overview'),
                'pageActions' => [],
                'pageSubnav' => [],
                'payload' => $payload,
                'contextToken' => isset($resolved['context']) ? $contextService->issueContextToken($resolved['context']) : null,
                'actor' => $actor,
            ]),
            $workspaceState['httpStatus']
        ));

        return $probe->addContext([
            'authorized' => (bool) ($resolved['authorized'] ?? false),
            'status' => (string) ($resolved['status'] ?? 'invalid_request'),
        ])->finish($response);
    }

    public function showWholesaleApplications(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response {
        $probe = $this->embeddedProbe($request);
        $resolved = $probe->time('context', fn (): array => $this->resolveWholesaleWorkspaceContext($request, $contextService));
        $tenantId = $this->resolvedWholesaleTenantId($resolved, $tenantResolver);
        $tenant = $tenantId !== null ? Tenant::query()->find($tenantId) : null;
        $workspaceState = $this->wholesaleAuthorizationState($resolved, $tenantId);
        $probe->forTenant($tenantId);

        $search = trim((string) $request->query('search', ''));
        $status = strtolower(trim((string) $request->query('status', 'pending')));
        $authorized = $workspaceState['authorized'];
        $applications = $authorized ? $this->wholesaleApplications($search, $status, $tenant) : collect();
        $summary = $authorized ? [
            'pending' => $this->countWholesaleApplicationsByStatus('pending', $tenant),
            'approved' => $this->countWholesaleApplicationsByStatus('approved', $tenant),
            'rejected' => $this->countWholesaleApplicationsByStatus('rejected', $tenant),
        ] : ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        $actor = $this->resolveWholesaleWorkspaceActor($resolved['context'] ?? null, $tenantId);

        return $probe->finish($this->embeddedResponse(response()->view('shopify.wholesale-applications-index', [
            'authorized' => $authorized,
            'status' => $workspaceState['status'],
            'shopifyApiKey' => $resolved['shopifyApiKey'] ?? null,
            'shopDomain' => $resolved['shopDomain'] ?? null,
            'host' => $resolved['host'] ?? null,
            'storeLabel' => $resolved['storeLabel'] ?? 'Wholesale Store',
            'headline' => $this->headlineForStatus($workspaceState['status'], 'Wholesale Applications'),
            'subheadline' => $this->subheadlineForStatus($workspaceState['status'], 'Review, approve, and track tenant-owned wholesale applications in one place.'),
            'appNavigation' => $this->wholesaleEmbeddedNavigation($tenantId, 'applications'),
            'pageActions' => [],
            'pageSubnav' => [],
            'applications' => $applications,
            'summary' => $summary,
            'search' => $search,
            'statusFilter' => $status,
            'tenant' => $tenant,
            'tenantSlug' => $tenant?->slug,
            'contextToken' => isset($resolved['context']) ? $contextService->issueContextToken($resolved['context']) : null,
            'actor' => $actor,
            'canManageApproval' => $this->canManageWholesaleApprovals($actor),
        ]), $workspaceState['httpStatus']));
    }

    public function showWholesaleCustomers(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        WholesaleOperationsService $operations
    ): Response {
        $resolved = $this->resolveWholesaleWorkspaceContext($request, $contextService);
        $tenantId = $this->resolvedWholesaleTenantId($resolved, $tenantResolver);
        $customers = ($resolved['authorized'] ?? false) && $tenantId !== null ? $operations->customers($tenantId) : collect();
        $search = strtolower(trim((string) $request->query('search', '')));
        $filter = strtolower(trim((string) $request->query('filter', 'all')));
        if ($search !== '') {
            $customers = $customers->filter(fn (array $customer): bool => str_contains(strtolower(implode(' ', [
                $customer['company'], $customer['primary_buyer'], $customer['email'], $customer['phone'],
            ])), $search))->values();
        }
        $customers = match ($filter) {
            'active', 'due', 'at_risk', 'lapsed' => $customers->where('timing_state', $filter)->values(),
            'new' => $customers->filter(fn (array $customer): bool => $customer['first_order_at']?->gte(now()->startOfMonth()) ?? false)->values(),
            'repeat' => $customers->where('order_count', '>', 1)->values(),
            'first_order_only' => $customers->where('order_count', 1)->values(),
            'high_value' => $customers->sortByDesc('lifetime_revenue')->take(max(1, (int) ceil($customers->count() * .2)))->values(),
            default => $customers,
        };

        return $this->wholesaleWorkspaceResponse($resolved, $tenantId, 'customers', 'Wholesale Customers',
            'Confirmed accounts and customer identities backed only by qualified wholesale activity.',
            'shopify.wholesale-customers', ['customers' => $customers, 'search' => $search, 'filter' => $filter]);
    }

    public function showWholesaleCustomer(
        Request $request,
        string $accountKey,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        WholesaleOperationsService $operations
    ): Response {
        $resolved = $this->resolveWholesaleWorkspaceContext($request, $contextService);
        $tenantId = $this->resolvedWholesaleTenantId($resolved, $tenantResolver);
        $customer = ($resolved['authorized'] ?? false) && $tenantId !== null ? $operations->customer($tenantId, $accountKey) : null;
        if (($resolved['authorized'] ?? false) && $tenantId !== null && $customer === null) {
            abort(404);
        }

        return $this->wholesaleWorkspaceResponse($resolved, $tenantId, 'customers',
            (string) ($customer['company'] ?? 'Wholesale Customer'),
            'Wholesale-only performance, buying history, and reorder timing.',
            'shopify.wholesale-customer-show', ['customer' => $customer]);
    }

    public function showWholesaleOrders(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        WholesaleOperationsService $operations
    ): Response {
        $resolved = $this->resolveWholesaleWorkspaceContext($request, $contextService);
        $tenantId = $this->resolvedWholesaleTenantId($resolved, $tenantResolver);
        $orders = ($resolved['authorized'] ?? false) && $tenantId !== null ? $operations->recentOrders($tenantId) : collect();

        return $this->wholesaleWorkspaceResponse($resolved, $tenantId, 'orders', 'Wholesale Orders',
            'Unified order history restricted to qualified wholesale records.',
            'shopify.wholesale-orders', ['orders' => $orders]);
    }

    public function showWholesaleSuggestions(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response {
        $resolved = $this->resolveWholesaleWorkspaceContext($request, $contextService);
        $tenantId = $this->resolvedWholesaleTenantId($resolved, $tenantResolver);
        $suggestions = collect();
        if (($resolved['authorized'] ?? false) && $tenantId !== null) {
            $suggestions = WholesaleSuggestion::query()->forAllTenants()
                ->with(['decisions' => fn ($query) => $query->orderByDesc('decided_at'), 'followUps'])
                ->where('tenant_id', $tenantId)
                ->orderByRaw("case when priority = 'urgent' then 0 when priority = 'high' then 1 else 2 end")
                ->orderByDesc('created_at')
                ->limit(200)
                ->get();
        }

        return $this->wholesaleWorkspaceResponse($resolved, $tenantId, 'suggestions', 'Wholesale Suggestions',
            'Review explainable recommendations and record what happened after each decision.',
            'shopify.wholesale-suggestions', [
                'suggestions' => $suggestions,
                'contextToken' => isset($resolved['context']) ? $contextService->issueContextToken($resolved['context']) : null,
                'canDecide' => $this->canManageWholesaleApprovals($this->resolveWholesaleWorkspaceActor($resolved['context'] ?? null, $tenantId)),
            ]);
    }

    public function showWholesaleFollowUps(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response {
        $resolved = $this->resolveWholesaleWorkspaceContext($request, $contextService);
        $tenantId = $this->resolvedWholesaleTenantId($resolved, $tenantResolver);
        $followUps = collect();
        if (($resolved['authorized'] ?? false) && $tenantId !== null) {
            $followUps = WholesaleFollowUp::query()->forAllTenants()
                ->with('suggestion')
                ->where('tenant_id', $tenantId)
                ->orderByRaw("case when status in ('open', 'in_progress') then 0 else 1 end")
                ->orderBy('due_at')
                ->limit(200)
                ->get();
        }

        return $this->wholesaleWorkspaceResponse($resolved, $tenantId, 'follow_ups', 'Wholesale Follow-Ups',
            'Open, overdue, and completed tasks created from reviewed wholesale evidence.',
            'shopify.wholesale-follow-ups', ['followUps' => $followUps]);
    }

    public function decideWholesaleSuggestion(
        Request $request,
        string $suggestionPublicId,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        WholesaleSuggestionDecisionService $decisions
    ): RedirectResponse|JsonResponse {
        $context = $contextService->resolveMutationContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $mappedTenantId = $this->resolvedMappedWholesaleTenantId($context, $tenantResolver);
        if ($mappedTenantId === null) {
            return response()->json(['ok' => false, 'message' => 'The Shopify installation is not mapped to this wholesale workspace.'], 403);
        }
        $tenantId = $mappedTenantId;
        $actor = $this->resolveWholesaleWorkspaceActor($context, $tenantId);
        if ($tenantId === null || ! app(TenantModuleAccessResolver::class)->canAccess($tenantId, 'wholesale_operations') || ! $this->canManageWholesaleApprovals($actor)) {
            return response()->json(['ok' => false, 'message' => 'Suggestion decisions require an authorized wholesale operator.'], 403);
        }

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:accept,create_follow_up,snooze,dismiss,already_handled,mark_incorrect,request_review'],
            'note' => ['nullable', 'string', 'max:2000'],
            'dismissal_reason' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'due_at' => ['nullable', 'date'],
            'snoozed_until' => ['nullable', 'date', 'after:now'],
            'assigned_user_id' => ['nullable', 'integer'],
        ]);
        if (isset($validated['assigned_user_id'])) {
            $assignee = User::query()->whereKey((int) $validated['assigned_user_id'])->where('is_active', true)->first();
            if (! $assignee || ! $assignee->tenants()->whereKey($tenantId)->exists()) {
                return response()->json(['ok' => false, 'message' => 'The selected assignee is not an active member of this wholesale workspace.'], 422);
            }
        }

        try {
            $decision = $decisions->decide($tenantId, $suggestionPublicId, $actor, $validated);
        } catch (DomainException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }

        $redirectUrl = $this->wholesaleEmbeddedRoute($request, 'shopify.app.wholesale.suggestions', [], (string) ($context['host'] ?? null));
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'decision_id' => $decision->id, 'redirect_url' => $redirectUrl]);
        }

        return redirect()->to($redirectUrl)->with('status', 'Wholesale suggestion decision recorded.');
    }

    public function showWholesaleProspects(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response {
        $resolved = $this->resolveWholesaleWorkspaceContext($request, $contextService);
        $tenantId = $this->resolvedWholesaleTenantId($resolved, $tenantResolver);
        $prospects = collect();
        if (($resolved['authorized'] ?? false) && $tenantId !== null) {
            $prospects = WholesaleProspect::query()->forAllTenants()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('fit_score')
                ->orderByDesc('discovered_at')
                ->limit(200)
                ->get();
        }

        $analytics = [
            'total' => $prospects->count(),
            'new' => $prospects->where('status', 'newly_discovered')->count(),
            'qualified' => $prospects->where('status', 'qualified')->count(),
            'converted' => $prospects->where('status', 'converted')->count(),
            'conversion_rate' => $prospects->count() > 0 ? round(($prospects->where('status', 'converted')->count() / $prospects->count()) * 100, 1) : 0,
        ];

        return $this->wholesaleWorkspaceResponse($resolved, $tenantId, 'prospects', 'Wholesale Prospects',
            'Discovered businesses remain separate from confirmed wholesale customers until a user verifies conversion.',
            'shopify.wholesale-prospects', ['prospects' => $prospects, 'analytics' => $analytics]);
    }

    public function showWholesaleProspectDiscovery(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response {
        $resolved = $this->resolveWholesaleWorkspaceContext($request, $contextService);
        $tenantId = $this->resolvedWholesaleTenantId($resolved, $tenantResolver);
        $runs = collect();
        if (($resolved['authorized'] ?? false) && $tenantId !== null) {
            $runs = WholesaleProspectDiscoveryRun::query()->forAllTenants()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->limit(25)
                ->get();
        }

        return $this->wholesaleWorkspaceResponse($resolved, $tenantId, 'prospect_discovery', 'Discover Prospects',
            'Run controlled Google Places searches with a review queue, cost estimate, deduplication, and no automatic outreach.',
            'shopify.wholesale-prospect-discovery', [
                'runs' => $runs,
                'contextToken' => isset($resolved['context']) ? $contextService->issueContextToken($resolved['context']) : null,
                'estimatedCostPerRequest' => (float) config('services.google_places.estimated_cost_per_request'),
                'defaultSampleSize' => max(1, min(20, (int) config('services.google_places.default_sample_size', 5))),
                'largeSearchThreshold' => (int) config('services.google_places.large_search_threshold', 40),
                'canRunDiscovery' => $this->canManageWholesaleApprovals($this->resolveWholesaleWorkspaceActor($resolved['context'] ?? null, $tenantId)),
            ]);
    }

    public function showWholesaleProspect(
        Request $request,
        string $prospectPublicId,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response {
        $resolved = $this->resolveWholesaleWorkspaceContext($request, $contextService);
        $tenantId = $this->resolvedWholesaleTenantId($resolved, $tenantResolver);
        $prospect = null;
        if (($resolved['authorized'] ?? false) && $tenantId !== null) {
            $prospect = WholesaleProspect::query()->forAllTenants()
                ->with(['evidence', 'followUps', 'convertedAccount'])
                ->where('tenant_id', $tenantId)
                ->where('public_id', $prospectPublicId)
                ->first();
            abort_if(! $prospect, 404);
        }

        return $this->wholesaleWorkspaceResponse($resolved, $tenantId, 'prospects',
            $prospect?->business_name ?? 'Wholesale Prospect',
            'Qualification evidence and an explainable fit score for user review.',
            'shopify.wholesale-prospect-show', [
                'prospect' => $prospect,
                'contextToken' => isset($resolved['context']) ? $contextService->issueContextToken($resolved['context']) : null,
                'canManage' => $this->canManageWholesaleApprovals($this->resolveWholesaleWorkspaceActor($resolved['context'] ?? null, $tenantId)),
            ]);
    }

    public function updateWholesaleProspect(
        Request $request,
        string $prospectPublicId,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        WholesaleProspectWorkflowService $workflow,
        LandlordOperatorActionAuditService $audit
    ): RedirectResponse|JsonResponse {
        $context = $contextService->resolveMutationContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $tenantId = $this->resolvedMappedWholesaleTenantId($context, $tenantResolver);
        $actor = $this->resolveWholesaleWorkspaceActor($context, $tenantId);
        if (
            $tenantId === null
            || ! app(TenantModuleAccessResolver::class)->canAccess($tenantId, 'wholesale_operations')
            || ! $this->canManageWholesaleApprovals($actor)
        ) {
            return response()->json(['ok' => false, 'message' => 'Prospect actions require an authorized wholesale operator.'], 403);
        }

        $prospect = WholesaleProspect::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('public_id', $prospectPublicId)
            ->firstOrFail();
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:qualify,reject,mark_duplicate,set_priority,mark_do_not_contact,clear_do_not_contact,add_note,request_research,record_call,record_contact_attempt,schedule_follow_up,convert'],
            'note' => ['nullable', 'string', 'max:3000'],
            'rejection_reason' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'due_at' => ['nullable', 'date'],
            'assigned_user_id' => ['nullable', 'integer'],
        ]);
        if (isset($validated['assigned_user_id'])) {
            $assignee = User::query()->whereKey((int) $validated['assigned_user_id'])->where('is_active', true)->first();
            if (! $assignee || ! $assignee->tenants()->whereKey($tenantId)->exists()) {
                return response()->json(['ok' => false, 'message' => 'The selected owner is not an active member of this wholesale workspace.'], 422);
            }
        }

        $before = $prospect->only(['status', 'opportunity_priority', 'do_not_contact', 'converted_wholesale_account_id', 'next_action_at']);
        try {
            $prospect = $workflow->apply($prospect, $actor, $validated);
        } catch (DomainException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
        $after = $prospect->only(['status', 'opportunity_priority', 'do_not_contact', 'converted_wholesale_account_id', 'next_action_at']);
        $audit->record($tenantId, (int) $actor->id, 'wholesale.prospect.'.(string) $validated['action'], targetType: 'wholesale_prospect', targetId: $prospect->id, context: [
            'surface' => 'shopify_embedded_wholesale',
            'prospect_public_id' => $prospect->public_id,
        ], beforeState: $before, afterState: $after);

        $redirectUrl = $this->wholesaleEmbeddedRoute($request, 'shopify.app.wholesale.prospects.show', ['prospectPublicId' => $prospect->public_id], (string) ($context['host'] ?? null));
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $prospect->status, 'redirect_url' => $redirectUrl]);
        }

        return redirect()->to($redirectUrl)->with('status', 'Prospect action recorded.');
    }

    public function runWholesaleProspectDiscovery(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        LandlordOperatorActionAuditService $audit
    ): RedirectResponse|JsonResponse {
        $context = $contextService->resolveMutationContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $mappedTenantId = $this->resolvedMappedWholesaleTenantId($context, $tenantResolver);
        if ($mappedTenantId === null) {
            return response()->json(['ok' => false, 'message' => 'The Shopify installation is not mapped to this wholesale workspace.'], 403);
        }
        $tenantId = $mappedTenantId;
        $actor = $this->resolveWholesaleWorkspaceActor($context, $tenantId);
        if ($tenantId === null || ! app(TenantModuleAccessResolver::class)->canAccess($tenantId, 'wholesale_operations') || ! $this->canManageWholesaleApprovals($actor)) {
            return response()->json(['ok' => false, 'message' => 'Prospect discovery requires an authorized wholesale operator.'], 403);
        }

        $validated = $request->validate([
            'search_region' => ['required', 'string', 'max:190'],
            'search_phrases' => ['required', 'string', 'max:3000'],
            'maximum_results' => ['required', 'integer', 'min:1', 'max:200'],
            'campaign_name' => ['nullable', 'string', 'max:190'],
            'website_enrichment' => ['nullable', 'boolean'],
            'instagram_enrichment' => ['nullable', 'boolean'],
            'large_search_confirmed' => ['nullable', 'boolean'],
        ]);

        $phrases = collect(preg_split('/[,\r\n]+/', (string) $validated['search_phrases']))
            ->map(fn (string $value): string => trim($value))->filter()->unique()->take(20)->values()->all();
        if ($phrases === []) {
            return response()->json(['ok' => false, 'message' => 'Add at least one search phrase.'], 422);
        }

        $maximum = (int) $validated['maximum_results'];
        $largeThreshold = max(1, (int) config('services.google_places.large_search_threshold', 40));
        $confirmed = (bool) ($validated['large_search_confirmed'] ?? false);
        if ($maximum > $largeThreshold && ! $confirmed) {
            return response()->json(['ok' => false, 'message' => 'Confirm the large search after reviewing its estimated external API cost.'], 422);
        }
        if ((bool) ($validated['instagram_enrichment'] ?? false)) {
            return response()->json(['ok' => false, 'message' => 'Instagram enrichment is disabled until an approved Meta authorization is configured.'], 422);
        }

        $estimatedRequests = min(count($phrases), (int) ceil($maximum / 20));
        $estimatedCost = round($estimatedRequests * (float) config('services.google_places.estimated_cost_per_request'), 4);
        $run = WholesaleProspectDiscoveryRun::query()->create([
            'tenant_id' => $tenantId,
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'queued',
            'search_region' => $validated['search_region'],
            'search_phrases' => $phrases,
            'maximum_results' => $maximum,
            'website_enrichment' => (bool) ($validated['website_enrichment'] ?? false),
            'instagram_enrichment' => false,
            'campaign_name' => $validated['campaign_name'] ?? null,
            'estimated_api_cost' => $estimatedCost,
            'large_search_confirmed' => $confirmed,
            'requested_by_user_id' => (int) $actor->id,
        ]);

        $audit->record($tenantId, (int) $actor->id, 'wholesale.prospect_discovery.queued', targetType: 'wholesale_prospect_discovery_run', targetId: $run->id, context: [
            'surface' => 'shopify_embedded_wholesale',
            'estimated_api_cost' => $estimatedCost,
            'maximum_results' => $maximum,
        ], afterState: ['status' => 'queued']);
        RunWholesaleProspectDiscovery::dispatch((int) $run->id, $tenantId);

        $redirectUrl = $this->wholesaleEmbeddedRoute($request, 'shopify.app.wholesale.prospects.discover', [], (string) ($context['host'] ?? null));
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'run_id' => $run->public_id, 'estimated_api_cost' => $estimatedCost, 'redirect_url' => $redirectUrl], 202);
        }

        return redirect()->to($redirectUrl)->with('status', 'Prospect discovery queued for review.');
    }

    public function showWholesaleApplication(
        Request $request,
        CustomerAccessRequest $accessRequest,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response {
        $probe = $this->embeddedProbe($request);
        $resolved = $probe->time('context', fn (): array => $this->resolveWholesaleWorkspaceContext($request, $contextService));
        $tenantId = $this->resolvedWholesaleTenantId($resolved, $tenantResolver);
        $tenant = $tenantId !== null ? Tenant::query()->find($tenantId) : null;
        $workspaceState = $this->wholesaleAuthorizationState($resolved, $tenantId);
        $probe->forTenant($tenantId);

        if ($workspaceState['authorized']) {
            $this->assertWholesaleApplication($accessRequest, $tenant);
            $accessRequest->load([
                'formSubmission.form.template',
                'user:id,name,email,is_active,approved_at',
                'tenant:id,name,slug',
            ]);
        }

        $actor = $this->resolveWholesaleWorkspaceActor($resolved['context'] ?? null, $tenantId);
        $canManageApproval = $this->canManageWholesaleApprovals($actor);

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.wholesale-applications-show', [
                'authorized' => $workspaceState['authorized'],
                'status' => $workspaceState['status'],
                'shopifyApiKey' => $resolved['shopifyApiKey'] ?? null,
                'shopDomain' => $resolved['shopDomain'] ?? null,
                'host' => $resolved['host'] ?? null,
                'storeLabel' => $resolved['storeLabel'] ?? 'Wholesale Store',
                'headline' => $this->headlineForStatus($workspaceState['status'], 'Wholesale Application Review'),
                'subheadline' => $this->subheadlineForStatus($workspaceState['status'], 'Review this application and approve or reject it without leaving Shopify Admin.'),
                'appNavigation' => $this->wholesaleEmbeddedNavigation($tenantId, 'applications'),
                'pageActions' => [],
                'pageSubnav' => [],
                'accessRequest' => $accessRequest,
                'detailSections' => $workspaceState['authorized'] ? $this->wholesaleApplicationDetailSections($accessRequest) : [],
                'detailNarratives' => $workspaceState['authorized'] ? $this->wholesaleApplicationNarratives($accessRequest) : [],
                'contextToken' => isset($resolved['context']) ? $contextService->issueContextToken($resolved['context']) : null,
                'actor' => $actor,
                'canManageApproval' => $canManageApproval,
            ]),
            $workspaceState['httpStatus']
        ));

        return $probe->addContext([
            'authorized' => $workspaceState['authorized'],
            'status' => $workspaceState['status'],
        ])->finish($response);
    }

    public function approveWholesaleApplication(
        Request $request,
        CustomerAccessRequest $accessRequest,
        ShopifyEmbeddedAppContext $contextService,
        CustomerAccessApprovalService $approvalService
    ): RedirectResponse|JsonResponse {
        return $this->handleWholesaleApplicationDecision(
            $request,
            $accessRequest,
            $contextService,
            fn (int $actorUserId, ?string $note) => $approvalService->approve((int) $accessRequest->id, $actorUserId, $note),
            'decision_note',
            'Wholesale application approved and activation email sent.'
        );
    }

    public function rejectWholesaleApplication(
        Request $request,
        CustomerAccessRequest $accessRequest,
        ShopifyEmbeddedAppContext $contextService,
        CustomerAccessApprovalService $approvalService
    ): RedirectResponse|JsonResponse {
        return $this->handleWholesaleApplicationDecision(
            $request,
            $accessRequest,
            $contextService,
            fn (int $actorUserId, ?string $note) => $approvalService->reject((int) $accessRequest->id, $actorUserId, $note),
            'rejection_note',
            'Wholesale application rejected.'
        );
    }

    public function resendWholesaleApplicationActivation(
        Request $request,
        CustomerAccessRequest $accessRequest,
        ShopifyEmbeddedAppContext $contextService,
        CustomerAccessApprovalService $approvalService
    ): RedirectResponse|JsonResponse {
        return $this->handleWholesaleApplicationDecision(
            $request,
            $accessRequest,
            $contextService,
            fn (int $actorUserId, ?string $note) => $approvalService->resendActivation((int) $accessRequest->id, $actorUserId, $note),
            'decision_note',
            'Activation email resend processed.'
        );
    }

    /**
     * @return array{
     *   authorized:bool,
     *   status:string,
     *   httpStatus:int,
     *   store?:array<string,mixed>,
     *   shopifyApiKey:?string,
     *   shopDomain:?string,
     *   host:?string,
     *   storeLabel:string,
     *   context?:array<string,mixed>
     * }
     */
    protected function resolveWholesaleWorkspaceContext(Request $request, ShopifyEmbeddedAppContext $contextService): array
    {
        $context = $contextService->resolvePageContext($request);

        if (($context['ok'] ?? false) === true) {
            $store = (array) ($context['store'] ?? []);
            $authenticatedShop = strtolower(trim((string) ($context['shop_domain'] ?? '')));
            $persistedStore = $authenticatedShop !== ''
                ? ShopifyStore::query()->whereRaw('LOWER(shop_domain) = ?', [$authenticatedShop])->first()
                : null;

            if (! $persistedStore || strtolower(trim((string) $persistedStore->store_role)) !== 'wholesale') {
                return [
                    'authorized' => false,
                    'status' => 'wrong_shop',
                    'httpStatus' => 403,
                    'shopifyApiKey' => null,
                    'shopDomain' => $context['shop_domain'] ?? null,
                    'host' => $context['host'] ?? null,
                    'storeLabel' => 'Wholesale Store',
                ];
            }

            return [
                'authorized' => true,
                'status' => (string) ($context['status'] ?? 'ok'),
                'httpStatus' => 200,
                'store' => $store,
                'shopifyApiKey' => app(ShopifyEmbeddedAppCredentials::class)->clientIdForStore($store)
                    ?? (string) ($store['client_id'] ?? ''),
                'shopDomain' => (string) ($context['shop_domain'] ?? ($store['shop'] ?? '')),
                'host' => (string) ($context['host'] ?? ''),
                'storeLabel' => 'Wholesale Store',
                'context' => $context,
            ];
        }

        return [
            'authorized' => false,
            'status' => (string) ($context['status'] ?? 'invalid_request'),
            'httpStatus' => (string) ($context['status'] ?? '') === 'open_from_shopify' ? 200 : 401,
            'shopifyApiKey' => null,
            'shopDomain' => $context['shop_domain'] ?? null,
            'host' => $context['host'] ?? null,
            'storeLabel' => 'Wholesale Store',
        ];
    }

    protected function handleWholesaleApplicationDecision(
        Request $request,
        CustomerAccessRequest $accessRequest,
        ShopifyEmbeddedAppContext $contextService,
        callable $decision,
        string $noteField,
        string $successMessage
    ): RedirectResponse|JsonResponse {
        $context = $contextService->resolveMutationContext($request);
        if (! (bool) ($context['ok'] ?? false)) {
            $message = (string) data_get($this->invalidContextResponse($context)->getData(true), 'message', 'This embedded Shopify request could not be verified.');
            $redirectUrl = $this->wholesaleEmbeddedRoute($request, 'shopify.app.wholesale.applications.show', ['accessRequest' => (int) $accessRequest->id]);

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                    'redirect_url' => $redirectUrl,
                ], 401);
            }

            return redirect()->to($redirectUrl)->with('error', $message);
        }

        $tenantResolver = app(TenantResolver::class);
        $mappedTenantId = $this->resolvedMappedWholesaleTenantId($context, $tenantResolver);
        if (
            $mappedTenantId === null
            || (int) $accessRequest->tenant_id !== $mappedTenantId
            || ! app(TenantModuleAccessResolver::class)->canAccess($mappedTenantId, 'wholesale_operations')
        ) {
            return response()->json([
                'ok' => false,
                'message' => 'This application is not available from the authenticated wholesale workspace.',
            ], 403);
        }

        $this->assertWholesaleApplication($accessRequest, Tenant::query()->find($mappedTenantId));

        $actor = $this->resolveWholesaleWorkspaceActor($context, $mappedTenantId);
        if (! $actor instanceof User) {
            $message = 'Approval actions require an Everbranch operator account that matches your Shopify admin email.';
            $redirectUrl = $this->wholesaleEmbeddedRoute($request, 'shopify.app.wholesale.applications.show', ['accessRequest' => (int) $accessRequest->id], (string) ($context['host'] ?? null));

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                    'redirect_url' => $redirectUrl,
                ], 403);
            }

            return redirect()->to($redirectUrl)->with('error', $message);
        }

        if (! $this->canManageWholesaleApprovals($actor)) {
            $message = 'Your account can review applications here, but approval actions are reserved for wholesale operators.';
            $redirectUrl = $this->wholesaleEmbeddedRoute($request, 'shopify.app.wholesale.applications.show', ['accessRequest' => (int) $accessRequest->id], (string) ($context['host'] ?? null));

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                    'redirect_url' => $redirectUrl,
                ], 403);
            }

            return redirect()->to($redirectUrl)->with('error', $message);
        }

        $validated = $request->validate([
            $noteField => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $decision((int) $actor->id, $validated[$noteField] ?? null);
            $redirectUrl = $this->wholesaleEmbeddedRoute($request, 'shopify.app.wholesale.applications.show', ['accessRequest' => (int) $accessRequest->id], (string) ($context['host'] ?? null));

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => $successMessage,
                    'redirect_url' => $redirectUrl,
                ]);
            }

            return redirect()->to($redirectUrl)->with('status', $successMessage);
        } catch (DomainException $e) {
            $message = (string) $e->getMessage();
            $redirectUrl = $this->wholesaleEmbeddedRoute($request, 'shopify.app.wholesale.applications.show', ['accessRequest' => (int) $accessRequest->id], (string) ($context['host'] ?? null));

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                    'redirect_url' => $redirectUrl,
                ], 422);
            }

            return redirect()->to($redirectUrl)->with('error', $message);
        } catch (Throwable $e) {
            report($e);
            $message = 'This action failed. Please reload the application and try again.';
            $redirectUrl = $this->wholesaleEmbeddedRoute($request, 'shopify.app.wholesale.applications.show', ['accessRequest' => (int) $accessRequest->id], (string) ($context['host'] ?? null));

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                    'redirect_url' => $redirectUrl,
                ], 500);
            }

            return redirect()->to($redirectUrl)->with('error', $message);
        }
    }

    protected function wholesaleEmbeddedRoute(
        Request $request,
        string $routeName,
        array $parameters = [],
        ?string $hostOverride = null
    ): string {
        return $this->urlGenerator->redirectToRoute(
            $routeName,
            array_merge($parameters, ['store_key' => 'wholesale']),
            $request,
            $hostOverride
        );
    }

    protected function wholesaleTenantSlug(): string
    {
        return (string) config(
            'product_surfaces.access_request.wholesale_storefront_tenant_slug',
            'modern-forestry'
        );
    }

    protected function wholesaleTenant(): ?Tenant
    {
        return Tenant::query()->where('slug', $this->wholesaleTenantSlug())->first();
    }

    /** @return array<int,string> */
    protected function wholesaleApplicationTenantAliases(): array
    {
        return array_values(array_unique([
            $this->wholesaleTenantSlug(),
            'modern-forestry-wholesale',
        ]));
    }

    protected function resolvedWholesaleTenantId(array $resolved, TenantResolver $tenantResolver): ?int
    {
        if (! (bool) ($resolved['authorized'] ?? false)) {
            return null;
        }

        $mappedTenantId = $this->resolvedMappedWholesaleTenantId((array) ($resolved['context'] ?? []), $tenantResolver);
        if ($mappedTenantId === null) {
            return null;
        }

        $storeContext = (array) data_get($resolved, 'context.store', []);
        $module = app(TenantModuleAccessResolver::class)
            ->resolveForStoreContext($storeContext, ['wholesale_operations'])['modules']['wholesale_operations'] ?? [];
        if (! (bool) ($module['enabled'] ?? false) || (string) ($module['setup_status'] ?? '') !== 'configured') {
            return null;
        }

        return $mappedTenantId;
    }

    protected function resolvedMappedWholesaleTenantId(array $context, TenantResolver $tenantResolver): ?int
    {
        $store = (array) ($context['store'] ?? []);
        $resolvedTenantId = $tenantResolver->resolveTenantIdForStoreContext($store);
        $shopDomain = strtolower(trim((string) ($context['shop_domain'] ?? ($store['shop'] ?? ''))));

        if ($resolvedTenantId === null || $shopDomain === '') {
            return null;
        }

        $persistedStore = ShopifyStore::query()
            ->whereRaw('LOWER(shop_domain) = ?', [$shopDomain])
            ->first(['id', 'tenant_id', 'store_role']);

        if (! $persistedStore
            || (int) $persistedStore->tenant_id !== (int) $resolvedTenantId
            || strtolower((string) $persistedStore->store_role) !== 'wholesale'
            || ! TenantWholesaleSetting::query()
                ->where('tenant_id', (int) $resolvedTenantId)
                ->where('shopify_store_id', (int) $persistedStore->id)
                ->whereNotNull('confirmed_at')
                ->exists()) {
            return null;
        }

        return (int) $persistedStore->tenant_id;
    }

    protected function wholesaleEmbeddedClientId(?string $fallback = null): string
    {
        $store = ShopifyStores::find('wholesale', true);
        if (is_array($store)) {
            $clientId = app(ShopifyEmbeddedAppCredentials::class)->clientIdForStore($store);
            if ($clientId !== null) {
                return $clientId;
            }
        }

        return trim((string) $fallback);
    }

    protected function resolveWholesaleWorkspaceActor(?array $context = null, ?int $tenantId = null): ?User
    {
        $tenant = $tenantId !== null ? Tenant::query()->find($tenantId) : null;
        $sessionUser = auth()->user();
        if ($sessionUser instanceof User) {
            if (! $tenant instanceof Tenant || ! (bool) $sessionUser->is_active) {
                return null;
            }

            return $sessionUser->tenants()->whereKey((int) $tenant->id)->exists() ? $sessionUser : null;
        }

        $email = strtolower(trim((string) ($context['shopify_admin_email'] ?? '')));
        if ($email === '') {
            return null;
        }

        $existing = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($existing instanceof User) {
            if (! $tenant instanceof Tenant || ! (bool) $existing->is_active) {
                return null;
            }

            return $existing->tenants()->whereKey((int) $tenant->id)->exists() ? $existing : null;
        }

        return null;
    }

    protected function canManageWholesaleApprovals(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $allowedRoles = array_values(array_filter(
            array_map(static fn (mixed $role): string => strtolower(trim((string) $role)), (array) config('tenancy.landlord.operator_roles', ['admin'])),
            static fn (string $role): bool => $role !== ''
        ));

        return in_array(strtolower(trim((string) $user->role)), $allowedRoles, true);
    }

    protected function wholesaleApplications(string $search, string $status, ?Tenant $tenant)
    {
        $tenantAliases = $tenant?->slug === $this->wholesaleTenantSlug()
            ? $this->wholesaleApplicationTenantAliases()
            : [];

        return CustomerAccessRequest::query()
            ->with([
                'formSubmission.form:id,name,slug',
                'user:id,name,email,is_active',
                'tenant:id,name,slug',
            ])
            ->where(function ($query) use ($tenant, $tenantAliases): void {
                if ($tenant instanceof Tenant) {
                    $query->where('tenant_id', (int) $tenant->id);
                    if ($tenantAliases !== []) {
                        $query->orWhere(function ($legacy) use ($tenantAliases): void {
                            $legacy->whereNull('tenant_id')->whereIn('requested_tenant_slug', $tenantAliases);
                        });
                    }

                    return;
                }

                $query->whereNull('tenant_id')->whereIn('requested_tenant_slug', $tenantAliases);
            })
            ->when(in_array($status, ['pending', 'approved', 'rejected'], true), function ($query) use ($status): void {
                $query->where('status', $status);
            })
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($builder) use ($search): void {
                    $builder
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('company', 'like', '%'.$search.'%');
                });
            })
            ->orderByRaw("case when status = 'pending' then 0 when status = 'approved' then 1 when status = 'rejected' then 2 else 3 end")
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();
    }

    protected function countWholesaleApplicationsByStatus(string $status, ?Tenant $tenant): int
    {
        $tenantAliases = $tenant?->slug === $this->wholesaleTenantSlug()
            ? $this->wholesaleApplicationTenantAliases()
            : [];

        return CustomerAccessRequest::query()
            ->where('status', $status)
            ->where(function ($query) use ($tenant, $tenantAliases): void {
                if ($tenant instanceof Tenant) {
                    $query->where('tenant_id', (int) $tenant->id);
                    if ($tenantAliases !== []) {
                        $query->orWhere(function ($legacy) use ($tenantAliases): void {
                            $legacy->whereNull('tenant_id')->whereIn('requested_tenant_slug', $tenantAliases);
                        });
                    }

                    return;
                }

                $query->whereNull('tenant_id')->whereIn('requested_tenant_slug', $tenantAliases);
            })
            ->count();
    }

    protected function assertWholesaleApplication(CustomerAccessRequest $accessRequest, ?Tenant $tenant = null): void
    {
        $aliases = $tenant?->slug === $this->wholesaleTenantSlug()
            ? $this->wholesaleApplicationTenantAliases()
            : [];

        abort_unless(
            ($tenant instanceof Tenant && (int) $accessRequest->tenant_id === (int) $tenant->id)
                || ($accessRequest->tenant_id === null && $aliases !== [] && in_array(
                    (string) $accessRequest->requested_tenant_slug,
                    $aliases,
                    true
                )),
            404
        );
    }

    /**
     * @return array<int,array{label:string,value:string}>
     */
    protected function wholesaleApplicationDetailRows(CustomerAccessRequest $accessRequest): array
    {
        $payload = (array) optional($accessRequest->formSubmission)->payload;
        $metadata = (array) ($accessRequest->metadata ?? []);

        $read = function (string ...$keys) use ($payload, $metadata, $accessRequest): string {
            foreach ($keys as $key) {
                $value = match ($key) {
                    'name' => $accessRequest->name,
                    'email' => $accessRequest->email,
                    'company' => $accessRequest->company,
                    'message' => $accessRequest->message,
                    default => $payload[$key] ?? $metadata[$key] ?? null,
                };

                $string = trim((string) $value);
                if ($string !== '') {
                    return $string;
                }
            }

            return '—';
        };

        return [
            ['label' => 'Name', 'value' => $read('name')],
            ['label' => 'Email', 'value' => $read('email')],
            ['label' => 'Phone', 'value' => $read('phone')],
            ['label' => 'Company', 'value' => $read('company')],
            ['label' => 'Store type', 'value' => $read('store_type', 'business_type')],
            ['label' => 'Website', 'value' => $read('website')],
            ['label' => 'Position', 'value' => $read('position')],
            ['label' => 'Referral source', 'value' => $read('referral')],
            ['label' => 'Address', 'value' => $read('address')],
            ['label' => 'Address line 2', 'value' => $read('address2')],
            ['label' => 'City', 'value' => $read('city')],
            ['label' => 'State', 'value' => $read('state')],
            ['label' => 'Postal / ZIP', 'value' => $read('zip')],
            ['label' => 'Country', 'value' => $read('country')],
            ['label' => 'Retail license / resale #', 'value' => $read('retail_license_number')],
            ['label' => 'Contact preference', 'value' => $read('contact_preference')],
            ['label' => 'Current suppliers', 'value' => $read('current_suppliers')],
            ['label' => 'Business info', 'value' => $read('business_info', 'message')],
            ['label' => 'Agreement accepted', 'value' => $this->wholesaleAgreementValue($payload, $metadata)],
        ];
    }

    /**
     * @return array{
     *   summary:array<int,array{label:string,value:string}>,
     *   business:array<int,array{label:string,value:string}>,
     *   location:array<int,array{label:string,value:string}>,
     *   compliance:array<int,array{label:string,value:string}>,
     *   system:array<int,array{label:string,value:string}>
     * }
     */
    protected function wholesaleApplicationDetailSections(CustomerAccessRequest $accessRequest): array
    {
        $payload = (array) optional($accessRequest->formSubmission)->payload;
        $metadata = (array) ($accessRequest->metadata ?? []);

        $read = function (string ...$keys) use ($payload, $metadata, $accessRequest): string {
            foreach ($keys as $key) {
                $value = match ($key) {
                    'name' => $accessRequest->name,
                    'email' => $accessRequest->email,
                    'company' => $accessRequest->company,
                    'message' => $accessRequest->message,
                    default => $payload[$key] ?? $metadata[$key] ?? null,
                };

                $string = trim((string) $value);
                if ($string !== '') {
                    return $string;
                }
            }

            return '—';
        };

        $addressLines = array_values(array_filter([
            $this->normalizeWholesaleDisplayValue($payload['address'] ?? $metadata['address'] ?? null),
            $this->normalizeWholesaleDisplayValue($payload['address2'] ?? $metadata['address2'] ?? null),
        ]));

        $city = $this->normalizeWholesaleDisplayValue($payload['city'] ?? $metadata['city'] ?? null);
        $state = $this->normalizeWholesaleDisplayValue($payload['state'] ?? $metadata['state'] ?? null);
        $zip = $this->normalizeWholesaleDisplayValue($payload['zip'] ?? $metadata['zip'] ?? null);
        $locality = collect([$city, $state, $zip])
            ->filter(fn (?string $value): bool => filled($value))
            ->implode(', ');

        return [
            'summary' => [
                ['label' => 'Applicant', 'value' => $read('name')],
                ['label' => 'Email', 'value' => $read('email')],
                ['label' => 'Phone', 'value' => $read('phone')],
                ['label' => 'Company', 'value' => $read('company')],
                ['label' => 'Website', 'value' => $read('website')],
                ['label' => 'Store type', 'value' => $read('store_type', 'business_type')],
            ],
            'business' => [
                ['label' => 'Role / title', 'value' => $read('position')],
                ['label' => 'Referral source', 'value' => $read('referral')],
                ['label' => 'Contact preference', 'value' => $read('contact_preference')],
                ['label' => 'Current suppliers', 'value' => $read('current_suppliers')],
            ],
            'location' => [
                ['label' => 'Street address', 'value' => $addressLines !== [] ? implode("\n", $addressLines) : '—'],
                ['label' => 'City / state / ZIP', 'value' => $locality !== '' ? $locality : '—'],
                ['label' => 'Country', 'value' => $read('country')],
            ],
            'compliance' => [
                ['label' => 'Retail license / resale #', 'value' => $read('retail_license_number')],
                ['label' => 'Agreement accepted', 'value' => $this->wholesaleAgreementValue($payload, $metadata)],
            ],
            'system' => [
                ['label' => 'Application ID', 'value' => (string) $accessRequest->id],
                ['label' => 'Submitted', 'value' => optional($accessRequest->created_at)->format('F j, Y \a\t g:i A') ?: '—'],
                ['label' => 'Tenant', 'value' => $accessRequest->tenant?->name ?? 'Wholesale workspace'],
                ['label' => 'Tenant slug', 'value' => $accessRequest->requested_tenant_slug ?: ($accessRequest->tenant?->slug ?? '—')],
                ['label' => 'Shopify user record', 'value' => $accessRequest->user?->email ?? 'Not linked yet'],
                ['label' => 'Submission capture', 'value' => $accessRequest->formSubmission?->id ? 'Captured' : 'Missing'],
            ],
        ];
    }

    /**
     * @return array<int,array{label:string,value:string}>
     */
    protected function wholesaleApplicationNarratives(CustomerAccessRequest $accessRequest): array
    {
        $payload = (array) optional($accessRequest->formSubmission)->payload;
        $metadata = (array) ($accessRequest->metadata ?? []);

        $narratives = [
            'Business background' => $payload['business_info'] ?? $metadata['business_info'] ?? null,
            'Applicant note' => $this->displayableWholesaleApplicantNote($accessRequest),
        ];

        return collect($narratives)
            ->map(function (mixed $value, string $label): ?array {
                $normalized = $this->normalizeWholesaleDisplayValue($value);

                if ($normalized === null) {
                    return null;
                }

                return [
                    'label' => $label,
                    'value' => $normalized,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function displayableWholesaleApplicantNote(CustomerAccessRequest $accessRequest): ?string
    {
        $message = $this->normalizeWholesaleDisplayValue($accessRequest->message);
        if ($message === null) {
            return null;
        }

        $normalized = strtolower(trim($message));
        $looksLikeGeneratedSummary = str_contains($normalized, 'wholesale application')
            && str_contains($normalized, 'contact')
            && str_contains($normalized, 'business')
            && str_contains($normalized, 'address')
            && str_contains($normalized, 'agreement accepted');

        return $looksLikeGeneratedSummary ? null : $message;
    }

    protected function normalizeWholesaleDisplayValue(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function wholesaleAgreementValue(array $payload, array $metadata): string
    {
        $value = $payload['agreement'] ?? $metadata['agreement'] ?? null;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
    }

    /**
     * @return array<string,mixed>
     */
    protected function emptyDashboardLitePayload(string $range, ?string $storeKey, ?string $timezone, bool $fallback = false): array
    {
        return [
            'meta' => [
                'generatedAt' => now()->toIso8601String(),
                'cacheTtlSeconds' => 0,
                'cache' => [
                    'summary' => ['hit' => false, 'key' => null],
                    'activity' => ['hit' => false, 'key' => null],
                ],
                'fallback' => $fallback,
            ],
            'query' => [
                'range' => strtolower(trim($range)) ?: 'today',
                'from' => now()->startOfDay()->toIso8601String(),
                'to' => now()->toIso8601String(),
                'timezone' => $timezone ?: (string) config('app.timezone', 'UTC'),
                'storeKey' => $storeKey,
            ],
            'summary' => [
                'kpis' => [
                    'customersPurchased' => 0,
                    'purchaseCount' => 0,
                    'returningCustomers' => 0,
                    'returningRatePct' => 0.0,
                    'candleCashEarned' => ['formatted' => '$0.00', 'points' => 0],
                    'candleCashRedeemed' => ['formatted' => '$0.00', 'points' => 0],
                    'openRewardCodes' => ['formatted' => '$0.00', 'count' => 0],
                    'outstandingBalance' => ['formatted' => '$0.00', 'points' => 0],
                ],
                'movement' => [
                    'earned' => ['formatted' => '$0.00', 'points' => 0],
                    'redeemed' => ['formatted' => '$0.00', 'points' => 0],
                    'net' => ['formatted' => '$0.00', 'points' => 0],
                ],
            ],
            'activity' => [
                'rows' => [],
                'count' => 0,
            ],
        ];
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

    public function wholesaleModuleSetup(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        WholesaleModuleSetupService $setupService
    ): Response {
        $context = $contextService->resolvePageContext($request);
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized ? $tenantResolver->resolveTenantIdForStoreContext($store) : null;
        $stores = $tenantId !== null ? $setupService->eligibleStores($tenantId) : [];
        $setting = $tenantId !== null
            ? TenantWholesaleSetting::query()->where('tenant_id', $tenantId)->first()
            : null;

        return $this->embeddedResponse(response()->view('shopify.wholesale-module-setup', [
            'authorized' => $authorized && $tenantId !== null,
            'status' => (string) ($context['status'] ?? 'invalid_request'),
            'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
            'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
            'host' => $context['host'] ?? null,
            'storeLabel' => $authorized ? ucfirst((string) ($store['key'] ?? 'store')).' Store' : 'Shopify Admin',
            'headline' => 'Set up Wholesale Operations',
            'subheadline' => 'Choose the connected store that is dedicated to wholesale orders.',
            'appNavigation' => $this->embeddedAppNavigation('home', null, $tenantId),
            'pageActions' => [],
            'pageSubnav' => $this->dashboardExperienceSubnav('store', $tenantId),
            'contextToken' => $authorized ? $contextService->issueContextToken($context) : null,
            'stores' => $stores,
            'setting' => $setting,
        ]), $authorized && $tenantId !== null ? 200 : 401);
    }

    public function configureWholesaleModule(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        WholesaleModuleSetupService $setupService
    ): RedirectResponse|JsonResponse {
        $context = $contextService->resolveMutationContext($request);
        if (! (bool) ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $tenantId = $tenantResolver->resolveTenantIdForStoreContext((array) ($context['store'] ?? []));
        if ($tenantId === null) {
            return response()->json(['ok' => false, 'message' => 'Tenant context is missing for this store.'], 403);
        }

        $validated = $request->validate([
            'shopify_store_id' => ['required', 'integer'],
            'confirm_wholesale_only' => ['required', 'accepted'],
        ]);
        $actor = $this->resolveWholesaleWorkspaceActor($context, $tenantId);
        $setupService->configure(
            $tenantId,
            (int) $validated['shopify_store_id'],
            (bool) $validated['confirm_wholesale_only'],
            $actor instanceof User ? (int) $actor->id : null
        );

        return redirect($this->urlGenerator->redirectToRoute(
            'shopify.app.wholesale',
            [],
            $request,
            (string) ($context['host'] ?? null)
        ))->with('status', 'Wholesale Operations is configured for the selected store.');
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
     * @return array<string,mixed>
     */
    protected function wholesaleEmbeddedNavigation(?int $tenantId, string $activeSection = 'overview'): array
    {
        $navigation = $this->embeddedAppNavigation('home', null, $tenantId);
        $routes = [
            'home' => ['Overview', 'shopify.app.wholesale'],
            'suggestions' => ['Suggestions', 'shopify.app.wholesale.suggestions'],
            'customers' => ['Customers', 'shopify.app.wholesale.customers'],
            'orders' => ['Orders', 'shopify.app.wholesale.orders'],
            'follow_ups' => ['Follow-Ups', 'shopify.app.wholesale.follow-ups'],
            'prospects' => ['Prospects', 'shopify.app.wholesale.prospects'],
            'prospect_discovery' => ['Discover', 'shopify.app.wholesale.prospects.discover'],
            'applications' => ['Applications', 'shopify.app.wholesale.applications'],
        ];

        $navigation['items'] = collect($routes)->map(
            fn (array $definition, string $key): array => [
                'key' => $key,
                'label' => $definition[0],
                'href' => $this->urlGenerator->route($definition[1], [], false, request()),
                'children' => [],
                'prefetch_priority' => $key === 'home' ? 'high' : 'normal',
            ]
        )->values()->all();
        $navigation['activeSection'] = $activeSection;
        $navigation['activeChild'] = null;
        $navigation['moduleStates'] = [];
        $navigation['displayLabels'] = [];
        $navigation['workspaceLabel'] = 'Wholesale Operations';
        $navigation['commandSearchPlaceholder'] = 'Search wholesale operations';
        $navigation['commandSearchDocuments'] = [];

        return $navigation;
    }

    /** @param array<string,mixed> $viewData */
    protected function wholesaleWorkspaceResponse(
        array $resolved,
        ?int $tenantId,
        string $activeSection,
        string $headline,
        string $subheadline,
        string $view,
        array $viewData = []
    ): Response {
        $workspaceState = $this->wholesaleAuthorizationState($resolved, $tenantId);

        return $this->embeddedResponse(response()->view($view, array_merge([
            'authorized' => $workspaceState['authorized'],
            'status' => $workspaceState['status'],
            'shopifyApiKey' => $resolved['shopifyApiKey'] ?? null,
            'shopDomain' => $resolved['shopDomain'] ?? null,
            'host' => $resolved['host'] ?? null,
            'storeLabel' => $resolved['storeLabel'] ?? 'Wholesale Store',
            'headline' => $this->headlineForStatus($workspaceState['status'], $headline),
            'subheadline' => $this->subheadlineForStatus($workspaceState['status'], $subheadline),
            'appNavigation' => $this->wholesaleEmbeddedNavigation($tenantId, $activeSection),
            'pageActions' => [],
            'pageSubnav' => [],
        ], $viewData)), $workspaceState['httpStatus']);
    }

    /**
     * @return array{authorized:bool,status:string,httpStatus:int}
     */
    protected function wholesaleAuthorizationState(array $resolved, ?int $tenantId): array
    {
        $storeAuthorized = (bool) ($resolved['authorized'] ?? false);
        $authorized = $storeAuthorized && $tenantId !== null;

        return [
            'authorized' => $authorized,
            'status' => $storeAuthorized && $tenantId === null
                ? 'tenant_not_mapped'
                : (string) ($resolved['status'] ?? 'invalid_request'),
            'httpStatus' => $storeAuthorized && $tenantId === null
                ? 403
                : (int) ($resolved['httpStatus'] ?? 200),
        ];
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
            'missing_shop', 'unknown_shop', 'invalid_hmac', 'wrong_shop', 'tenant_not_mapped' => 'We could not verify this Shopify request',
            default => $defaultHeadline,
        };
    }

    protected function subheadlineForStatus(string $status, string $defaultSubheadline): string
    {
        return match ($status) {
            'open_from_shopify' => 'This page is meant to load inside Shopify Admin so store context and module access state can be verified.',
            'missing_shop', 'unknown_shop', 'invalid_hmac', 'wrong_shop', 'tenant_not_mapped' => 'Open the wholesale app again from its Shopify Admin installation. If this repeats, verify the shop-to-tenant mapping.',
            default => $defaultSubheadline,
        };
    }

    protected function invalidContextResponse(array $context): JsonResponse
    {
        $status = (string) ($context['status'] ?? 'invalid_request');

        $messages = [
            'open_from_shopify' => 'Open the app from Shopify Admin to load Home.',
            'missing_shop' => 'The Shopify shop context is missing from this request.',
            'unknown_shop' => 'This Shopify shop is not connected to an Everbranch store yet.',
            'invalid_hmac' => 'This Shopify request could not be verified.',
            'wrong_shop' => 'This wholesale workspace is available only from the authenticated wholesale Shopify installation.',
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
