<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\User;
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
use App\Services\Tenancy\ModernForestryAlphaBootstrapService;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantModuleCatalogService;
use App\Services\Tenancy\TenantResolver;
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
        TenantResolver $tenantResolver
    ): Response {
        $probe = $this->embeddedProbe($request);
        $resolved = $probe->time('context', fn (): array => $this->resolveWholesaleWorkspaceContext($request, $contextService));
        $tenant = $this->wholesaleTenant();
        $tenantId = $tenant?->id ?? $tenantResolver->resolveTenantIdForStoreContext((array) ($resolved['store'] ?? []));
        $probe->forTenant($tenantId);

        $search = trim((string) $request->query('search', ''));
        $status = strtolower(trim((string) $request->query('status', 'pending')));
        $applications = $this->wholesaleApplications($search, $status, $tenant);
        $summary = [
            'pending' => $this->countWholesaleApplicationsByStatus('pending', $tenant),
            'approved' => $this->countWholesaleApplicationsByStatus('approved', $tenant),
            'rejected' => $this->countWholesaleApplicationsByStatus('rejected', $tenant),
        ];
        $actor = $this->resolveWholesaleWorkspaceActor($resolved['context'] ?? null);
        $canManageApproval = $this->canManageWholesaleApprovals($actor);

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.wholesale-applications-index', [
                'authorized' => (bool) ($resolved['authorized'] ?? false),
                'status' => (string) ($resolved['status'] ?? 'invalid_request'),
                'shopifyApiKey' => $resolved['shopifyApiKey'] ?? null,
                'shopDomain' => $resolved['shopDomain'] ?? null,
                'host' => $resolved['host'] ?? null,
                'storeLabel' => $resolved['storeLabel'] ?? 'Wholesale Store',
                'headline' => $this->headlineForStatus((string) ($resolved['status'] ?? 'invalid_request'), 'Wholesale Applications'),
                'subheadline' => $this->subheadlineForStatus((string) ($resolved['status'] ?? 'invalid_request'), 'Review, approve, and track Modern Forestry Wholesale applications in one place.'),
                'appNavigation' => $this->wholesaleEmbeddedNavigation($tenantId),
                'pageActions' => [],
                'pageSubnav' => [],
                'applications' => $applications,
                'summary' => $summary,
                'search' => $search,
                'statusFilter' => $status,
                'tenant' => $tenant,
                'tenantSlug' => $this->wholesaleTenantSlug(),
                'contextToken' => isset($resolved['context']) ? $contextService->issueContextToken($resolved['context']) : null,
                'actor' => $actor,
                'canManageApproval' => $canManageApproval,
            ]),
            (int) ($resolved['httpStatus'] ?? 200)
        ));

        return $probe->addContext([
            'authorized' => (bool) ($resolved['authorized'] ?? false),
            'status' => (string) ($resolved['status'] ?? 'invalid_request'),
        ])->finish($response);
    }

    public function showWholesaleApplication(
        Request $request,
        CustomerAccessRequest $accessRequest,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response {
        $probe = $this->embeddedProbe($request);
        $resolved = $probe->time('context', fn (): array => $this->resolveWholesaleWorkspaceContext($request, $contextService));
        $this->assertWholesaleApplication($accessRequest);

        $accessRequest->load([
            'formSubmission.form.template',
            'user:id,name,email,is_active,approved_at',
            'tenant:id,name,slug',
        ]);

        $tenant = $this->wholesaleTenant();
        $tenantId = $tenant?->id ?? $tenantResolver->resolveTenantIdForStoreContext((array) ($resolved['store'] ?? []));
        $probe->forTenant($tenantId);

        $actor = $this->resolveWholesaleWorkspaceActor($resolved['context'] ?? null);
        $canManageApproval = $this->canManageWholesaleApprovals($actor);

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.wholesale-applications-show', [
                'authorized' => (bool) ($resolved['authorized'] ?? false),
                'status' => (string) ($resolved['status'] ?? 'invalid_request'),
                'shopifyApiKey' => $resolved['shopifyApiKey'] ?? null,
                'shopDomain' => $resolved['shopDomain'] ?? null,
                'host' => $resolved['host'] ?? null,
                'storeLabel' => $resolved['storeLabel'] ?? 'Wholesale Store',
                'headline' => $this->headlineForStatus((string) ($resolved['status'] ?? 'invalid_request'), 'Wholesale Application Review'),
                'subheadline' => $this->subheadlineForStatus((string) ($resolved['status'] ?? 'invalid_request'), 'Review this application and approve or reject it without leaving Shopify Admin.'),
                'appNavigation' => $this->wholesaleEmbeddedNavigation($tenantId),
                'pageActions' => [],
                'pageSubnav' => [],
                'accessRequest' => $accessRequest,
                'detailSections' => $this->wholesaleApplicationDetailSections($accessRequest),
                'detailNarratives' => $this->wholesaleApplicationNarratives($accessRequest),
                'contextToken' => isset($resolved['context']) ? $contextService->issueContextToken($resolved['context']) : null,
                'actor' => $actor,
                'canManageApproval' => $canManageApproval,
            ]),
            (int) ($resolved['httpStatus'] ?? 200)
        ));

        return $probe->addContext([
            'authorized' => (bool) ($resolved['authorized'] ?? false),
            'status' => (string) ($resolved['status'] ?? 'invalid_request'),
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
        $request->query->set('store_key', strtolower(trim((string) $request->query('store_key', ''))) ?: 'wholesale');

        $context = $contextService->resolvePageContext($request);

        if (($context['ok'] ?? false) === true) {
            $store = (array) ($context['store'] ?? []);

            return [
                'authorized' => true,
                'status' => (string) ($context['status'] ?? 'ok'),
                'httpStatus' => 200,
                'store' => $store,
                'shopifyApiKey' => $this->wholesaleEmbeddedClientId((string) ($store['client_id'] ?? '')),
                'shopDomain' => (string) ($context['shop_domain'] ?? ($store['shop'] ?? '')),
                'host' => (string) ($context['host'] ?? ''),
                'storeLabel' => ucfirst((string) ($store['key'] ?? 'wholesale')).' Store',
                'context' => $context,
            ];
        }

        $hintedStore = ShopifyStores::find('wholesale', true);
        if (is_array($hintedStore)) {
            $bootstrapContext = [
                'store' => $hintedStore,
                'shop_domain' => (string) ($hintedStore['shop'] ?? ''),
                'host' => (string) ($context['host'] ?? $request->query('host', '')),
            ];
            $contextService->rememberPageContext($request, $bootstrapContext);

            return [
                'authorized' => true,
                'status' => 'bootstrap_pending',
                'httpStatus' => 200,
                'store' => $hintedStore,
                'shopifyApiKey' => $this->wholesaleEmbeddedClientId((string) ($hintedStore['client_id'] ?? '')),
                'shopDomain' => (string) ($hintedStore['shop'] ?? ''),
                'host' => (string) ($bootstrapContext['host'] ?? ''),
                'storeLabel' => ucfirst((string) ($hintedStore['key'] ?? 'wholesale')).' Store',
                'context' => array_merge($bootstrapContext, [
                    'ok' => true,
                    'status' => 'ok',
                    'signed_query' => [],
                ]),
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

        $this->assertWholesaleApplication($accessRequest);

        $actor = $this->resolveWholesaleWorkspaceActor($context);
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
            'modern-forestry-wholesale'
        );
    }

    protected function wholesaleTenant(): ?Tenant
    {
        return Tenant::query()->where('slug', $this->wholesaleTenantSlug())->first();
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

    protected function resolveWholesaleWorkspaceActor(?array $context = null): ?User
    {
        $sessionUser = auth()->user();
        if ($sessionUser instanceof User) {
            return $sessionUser;
        }

        $email = strtolower(trim((string) ($context['shopify_admin_email'] ?? '')));
        if ($email === '') {
            return null;
        }

        $existing = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($existing instanceof User) {
            $tenant = $this->wholesaleTenant();
            if ($tenant instanceof Tenant) {
                $existing->tenants()->syncWithoutDetaching([
                    (int) $tenant->id => ['role' => 'admin'],
                ]);
            }

            if (! $existing->isAdmin() || ! (bool) $existing->is_active) {
                $existing->forceFill([
                    'role' => 'admin',
                    'is_active' => true,
                ])->save();
            }

            return $existing;
        }

        $tenant = $this->wholesaleTenant();
        $name = trim((string) preg_replace('/[@._-]+/', ' ', strstr($email, '@', true) ?: $email));
        $name = $name !== '' ? \Illuminate\Support\Str::title($name) : 'Wholesale Operator';

        $created = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => \Illuminate\Support\Str::random(40),
            'role' => 'admin',
            'is_active' => true,
            'requested_via' => 'shopify_embedded_wholesale',
        ]);

        $created->forceFill([
            'email_verified_at' => now(),
            'approved_at' => now(),
        ])->save();

        if ($tenant instanceof Tenant) {
            $created->tenants()->syncWithoutDetaching([
                (int) $tenant->id => ['role' => 'admin'],
            ]);
        }

        return $created;
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
        $tenantSlug = $this->wholesaleTenantSlug();

        return CustomerAccessRequest::query()
            ->with([
                'formSubmission.form:id,name,slug',
                'user:id,name,email,is_active',
                'tenant:id,name,slug',
            ])
            ->where(function ($query) use ($tenant, $tenantSlug): void {
                if ($tenant instanceof Tenant) {
                    $query->where('tenant_id', (int) $tenant->id)
                        ->orWhere('requested_tenant_slug', $tenantSlug);

                    return;
                }

                $query->where('requested_tenant_slug', $tenantSlug);
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
        $tenantSlug = $this->wholesaleTenantSlug();

        return CustomerAccessRequest::query()
            ->where('status', $status)
            ->where(function ($query) use ($tenant, $tenantSlug): void {
                if ($tenant instanceof Tenant) {
                    $query->where('tenant_id', (int) $tenant->id)
                        ->orWhere('requested_tenant_slug', $tenantSlug);

                    return;
                }

                $query->where('requested_tenant_slug', $tenantSlug);
            })
            ->count();
    }

    protected function assertWholesaleApplication(CustomerAccessRequest $accessRequest): void
    {
        $tenantSlug = $this->wholesaleTenantSlug();
        $tenantId = Tenant::query()->where('slug', $tenantSlug)->value('id');

        abort_unless(
            $accessRequest->requested_tenant_slug === $tenantSlug
                || ($tenantId !== null && (int) $accessRequest->tenant_id === (int) $tenantId),
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
                ['label' => 'Tenant', 'value' => $accessRequest->tenant?->name ?? 'Modern Forestry Wholesale'],
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
    protected function wholesaleEmbeddedNavigation(?int $tenantId): array
    {
        $navigation = $this->embeddedAppNavigation('home', null, $tenantId);
        $href = $this->urlGenerator->route('shopify.app.wholesale', ['store_key' => 'wholesale'], false);

        $navigation['items'] = [[
            'key' => 'home',
            'label' => 'Applications',
            'href' => $href,
            'children' => [],
            'prefetch_priority' => 'high',
        ]];
        $navigation['activeSection'] = 'home';
        $navigation['activeChild'] = null;
        $navigation['moduleStates'] = [];
        $navigation['displayLabels'] = [];
        $navigation['workspaceLabel'] = 'Wholesale Applications';
        $navigation['commandSearchPlaceholder'] = 'Search wholesale applications';
        $navigation['commandSearchDocuments'] = [];

        return $navigation;
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
            'unknown_shop' => 'This Shopify shop is not connected to an Everbranch store yet.',
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
