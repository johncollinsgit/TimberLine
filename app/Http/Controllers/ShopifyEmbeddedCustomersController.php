<?php

namespace App\Http\Controllers;

use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Services\Marketing\CandleCashService;
use App\Support\Diagnostics\ShopifyEmbeddedCsrfDiagnostics;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedCustomerActionUrlGenerator;
use App\Services\Shopify\ShopifyEmbeddedPerformanceProbe;
use App\Services\Shopify\ShopifyEmbeddedShellPayloadBuilder;
use App\Services\Shopify\ShopifyEmbeddedUrlGenerator;
use App\Services\Shopify\ShopifyEmbeddedCustomerCandleCashAdjustmentService;
use App\Services\Shopify\ShopifyEmbeddedCustomerDetailService;
use App\Services\Shopify\ShopifyEmbeddedCustomerMessagingService;
use App\Services\Shopify\ShopifyEmbeddedCustomerSendCandleCashService;
use App\Services\Shopify\ShopifyEmbeddedCustomersGridService;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantResolver;
use App\Services\Marketing\MarketingConsentService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class ShopifyEmbeddedCustomersController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    private const GIFT_INTENT_OPTIONS = [
        'retention' => 'Retention',
        'apology' => 'Apology',
        'vip' => 'VIP',
        'winback' => 'Win back',
        'delight' => 'Delight',
        'review_recovery' => 'Review recovery',
        'support' => 'Support',
    ];

    private const GIFT_ORIGIN_OPTIONS = [
        'support' => 'Support',
        'marketing' => 'Marketing',
        'wholesale' => 'Wholesale',
        'founder' => 'Founder',
        'ops' => 'Ops',
    ];

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomersGridService $gridService
    ): Response {
        return $this->manage($request, $contextService, $gridService);
    }

    public function manage(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomersGridService $gridService
    ): Response|JsonResponse {
        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            $context = $contextService->resolveAuthenticatedApiContext($request);

            if (! ($context['ok'] ?? false)) {
                return $this->invalidApiContextResponse($context);
            }

            $tenantId = $this->resolveEmbeddedTenantId($context, app(TenantResolver::class));
            $grid = $gridService->resolve($request, $tenantId);
            /** @var ShopifyEmbeddedShellPayloadBuilder $payloadBuilder */
            $payloadBuilder = app(ShopifyEmbeddedShellPayloadBuilder::class);
            $displayLabels = $payloadBuilder->displayLabels($tenantId, $request);

            return response()->json([
                'ok' => true,
                'data' => $this->customersManagePayload($grid, $displayLabels),
            ]);
        }

        $probe = $this->embeddedProbe($request);
        $context = $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
        $authorized = (bool) ($context['ok'] ?? false);
        $tenantId = $authorized
            ? $probe->time('tenant_resolve', fn (): ?int => $this->resolveEmbeddedTenantId($context, app(TenantResolver::class)))
            : null;
        $probe->forTenant($tenantId);
        $grid = $authorized
            ? $probe->time('page_payload', fn (): array => $gridService->resolve($request, $tenantId))
            : $gridService->emptyResult($request);

        Log::info('shopify.embedded.manage.render', [
            'route' => $request->route()?->getName(),
            'query' => $request->query->all(),
        ]);

        return $this->renderPage(
            request: $request,
            contextService: $contextService,
            view: 'shopify.customers-manage',
            subnavKey: 'all',
            defaultHeadline: 'Customers',
            defaultSubheadline: 'Search, segment, and manage customer records.',
            extraViewData: [
                'customers' => $grid['paginator'],
                'gridFilters' => $grid['filters'],
                'gridSortOptions' => $grid['sort_options'],
                'activeFilterCount' => $grid['active_filter_count'],
                'customersManageEndpoint' => $request->url(),
            ],
            resolvedContext: $context,
            resolvedTenantId: $tenantId,
            probe: $probe,
        );
    }

    public function segments(
        Request $request,
        ShopifyEmbeddedAppContext $contextService
    ): Response {
        return $this->renderPage(
            request: $request,
            contextService: $contextService,
            view: 'shopify.customers-segments',
            subnavKey: 'segments',
            defaultHeadline: 'Customers',
            defaultSubheadline: 'Build and review saved customer segments.',
            extraViewData: []
        );
    }

    public function activity(
        Request $request,
        ShopifyEmbeddedAppContext $contextService
    ): Response {
        return $this->renderPage(
            request: $request,
            contextService: $contextService,
            view: 'shopify.customers-activity',
            subnavKey: 'activity',
            defaultHeadline: 'Customers',
            defaultSubheadline: 'Review recent customer events.',
            extraViewData: []
        );
    }

    public function imports(
        Request $request,
        ShopifyEmbeddedAppContext $contextService
    ): Response {
        return $this->renderPage(
            request: $request,
            contextService: $contextService,
            view: 'shopify.customers-imports',
            subnavKey: 'imports',
            defaultHeadline: 'Customers',
            defaultSubheadline: 'Track customer sync status and history.',
            extraViewData: []
        );
    }

    public function detail(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomerDetailService $detailService,
        ShopifyEmbeddedCustomerActionUrlGenerator $actionUrlGenerator,
        TenantResolver $tenantResolver,
        MarketingProfile $marketingProfile
    ): Response {
        $probe = $this->embeddedProbe($request);
        $criticalStartedAt = microtime(true);
        $context = $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
        $authorized = (bool) ($context['ok'] ?? false);
        $tenantId = $authorized
            ? $probe->time('tenant_resolve', fn (): ?int => $this->resolveEmbeddedTenantId($context, $tenantResolver))
            : null;
        $probe->forTenant($tenantId);

        if ($authorized) {
            abort_unless(
                $this->customerBelongsToEmbeddedContext($marketingProfile, $context, $tenantResolver),
                404
            );
        }

        $displayName = 'Customer detail unavailable';
        if ($authorized) {
            $displayName = $this->customerDisplayName($marketingProfile);
        }

        $detail = $authorized ? $probe->time('page_payload', fn (): array => $detailService->buildCritical($marketingProfile, $tenantId)) : [
            'summary' => [],
            'statuses' => [],
            'activity' => [],
            'external_profiles' => collect(),
            'consent' => [],
            'messaging' => [],
        ];
        $criticalDurationMs = round((microtime(true) - $criticalStartedAt) * 1000, 2);

        $detailFormAction = $authorized
            ? $actionUrlGenerator->url('customers.detail', ['marketingProfile' => $marketingProfile->id], $request)
            : '#';
        $formActions = $authorized ? [
            'update' => $detailFormAction,
            'candle_cash_adjust' => $detailFormAction,
            'candle_cash_send' => $detailFormAction,
            'consent' => $detailFormAction,
            'message' => $detailFormAction,
        ] : [];

        $renderedWidgets = $authorized
            ? [
                ['key' => 'identity', 'actionable' => true],
                ['key' => 'loyalty_profile', 'actionable' => false],
                ['key' => 'candle_cash_adjustment', 'actionable' => true],
                ['key' => 'send_candle_cash', 'actionable' => true],
                ['key' => 'reward_completion', 'actionable' => false],
                ['key' => 'consent', 'actionable' => true],
                ['key' => 'message_customer', 'actionable' => true],
                ['key' => 'recent_activity', 'actionable' => false],
                ['key' => 'external_profiles', 'actionable' => false],
            ]
            : [
                ['key' => 'context_missing', 'actionable' => false],
            ];

        Log::info('shopify.embedded.detail.render', [
            'route' => $request->route()?->getName(),
            'profile_id' => $marketingProfile->id,
            'authorized' => $authorized,
            'context_status' => $context['status'] ?? 'unknown',
            'query' => $request->query->all(),
            'form_actions' => $formActions,
            'widgets' => $renderedWidgets,
            'render_state' => ShopifyEmbeddedCsrfDiagnostics::renderState($request),
            'critical_ms' => $criticalDurationMs,
        ]);

        $response = $this->renderPage(
            request: $request,
            contextService: $contextService,
            view: 'shopify.customers-detail',
            subnavKey: 'all',
            defaultHeadline: 'Customer Detail',
            defaultSubheadline: 'View identity, {{rewards_balance_label_lc}}, and recent customer activity.',
            extraViewData: [
                'marketingProfile' => $authorized ? $marketingProfile : new MarketingProfile(),
                'customerDisplayName' => $displayName,
                'detail' => $detail,
                'pageActions' => $authorized ? [
                    [
                        'label' => 'Back to Customers',
                        'href' => $actionUrlGenerator->url('customers.manage', [], $request),
                    ],
                    [
                        'label' => 'Open in Backstage',
                        'href' => route('marketing.customers.show', $marketingProfile),
                    ],
                ] : [],
                'giftIntentOptions' => self::giftIntentOptions(),
                'giftOriginOptions' => self::giftOriginOptions(),
                'customerFormActions' => $formActions,
                'customerMutationBootstrap' => $authorized ? [
                    'identityEndpoint' => route('shopify.app.api.customers.update', ['marketingProfile' => $marketingProfile->id], false),
                    'adjustmentEndpoint' => route('shopify.app.api.customers.candle-cash.adjust', ['marketingProfile' => $marketingProfile->id], false),
                    'sendCandleCashEndpoint' => route('shopify.app.api.customers.candle-cash.send', ['marketingProfile' => $marketingProfile->id], false),
                    'consentEndpoint' => route('shopify.app.api.customers.update-consent', ['marketingProfile' => $marketingProfile->id], false),
                    'messageEndpoint' => route('shopify.app.api.customers.message', ['marketingProfile' => $marketingProfile->id], false),
                ] : null,
                'customerDetailDeferredBootstrap' => $authorized ? [
                    'profileId' => (int) $marketingProfile->id,
                    'sectionsEndpoint' => route('shopify.app.api.customers.detail-sections', ['marketingProfile' => $marketingProfile->id], false),
                    'perfDebug' => $request->boolean('detail_perf'),
                ] : null,
            ],
            resolvedContext: $context,
            resolvedTenantId: $tenantId,
            probe: $probe,
        );

        return $this->withServerTiming($response, [
            'customer-detail-critical' => $criticalDurationMs,
        ]);
    }

    public function detailSectionsJson(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomerDetailService $detailService,
        TenantResolver $tenantResolver,
        MarketingProfile $marketingProfile
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);

        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        if (! $this->customerBelongsToEmbeddedContext($marketingProfile, $context, $tenantResolver)) {
            return $this->customerNotFoundResponse();
        }

        $tenantId = $this->resolveEmbeddedTenantId($context, $tenantResolver);
        $startedAt = microtime(true);
        $deferred = $detailService->buildDeferredSections($marketingProfile, $tenantId);
        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);

        $response = response()->json([
            'ok' => true,
            'data' => [
                'activity_html' => view('shopify.partials.customers-detail-activity-section', [
                    'activity' => $deferred['activity'],
                    'activityCount' => $deferred['activity_count'],
                ])->render(),
                'external_profiles_html' => view('shopify.partials.customers-detail-external-profiles-section', [
                    'externalProfiles' => $deferred['external_profiles'],
                    'externalProfilesCount' => $deferred['external_profiles_count'],
                ])->render(),
                'last_activity_display' => $deferred['deferred_meta']['last_activity_display'] ?? 'No recent activity',
                'activity_count' => (int) ($deferred['activity_count'] ?? 0),
                'external_profiles_count' => (int) ($deferred['external_profiles_count'] ?? 0),
                'timings' => $request->boolean('detail_perf') ? [
                    'build_ms' => $durationMs,
                ] : null,
            ],
        ]);

        return $this->withServerTiming($response, [
            'customer-detail-deferred' => $durationMs,
        ]);
    }

    public function updateJson(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        MarketingIdentityNormalizer $identityNormalizer,
        TenantResolver $tenantResolver,
        MarketingProfile $marketingProfile
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        $this->logCustomerAction($request, $marketingProfile, 'identity.update.json', (bool) ($context['ok'] ?? false));

        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        if (! $this->customerBelongsToEmbeddedContext($marketingProfile, $context, $tenantResolver)) {
            return $this->customerNotFoundResponse();
        }

        try {
            $data = $this->validatedIdentityData($request);
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Customer identity could not be saved.', $exception);
        }

        $this->applyIdentityUpdate($marketingProfile, $data, $identityNormalizer);

        return response()->json([
            'ok' => true,
            'message' => 'Customer identity updated.',
            'notice_style' => 'success',
            'data' => [
                'customer' => $this->customerIdentityPayload($marketingProfile->fresh()),
            ],
        ]);
    }

    public function updateConsentJson(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        MarketingConsentService $consentService,
        TenantResolver $tenantResolver,
        MarketingProfile $marketingProfile
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        $this->logCustomerAction($request, $marketingProfile, 'consent.update.json', (bool) ($context['ok'] ?? false));

        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        if (! $this->customerBelongsToEmbeddedContext($marketingProfile, $context, $tenantResolver)) {
            return $this->customerNotFoundResponse();
        }

        try {
            $data = $this->validatedConsentData($request);
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Consent could not be saved.', $exception);
        }

        $result = $this->applyConsentUpdate($marketingProfile, $data, $consentService);

        return response()->json([
            'ok' => true,
            'message' => $result['notice_message'],
            'notice_style' => $result['notice_style'],
            'data' => [
                'consent' => $this->customerConsentPayload($marketingProfile->fresh()),
            ],
        ]);
    }

    public function adjustCandleCashJson(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomerCandleCashAdjustmentService $adjustmentService,
        ShopifyEmbeddedCustomerMessagingService $messagingService,
        CandleCashService $candleCashService,
        TenantResolver $tenantResolver,
        MarketingProfile $marketingProfile
    ): JsonResponse {
        Log::info('shopify.embedded.candle_cash.adjust.json.entry', [
            'route' => $request->route()?->getName(),
            'profile_id' => $marketingProfile->id,
        ]);

        $context = $contextService->resolveAuthenticatedApiContext($request);
        $this->logCustomerAction($request, $marketingProfile, 'candle_cash.adjust.json', (bool) ($context['ok'] ?? false));

        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        if (! $this->customerBelongsToEmbeddedContext($marketingProfile, $context, $tenantResolver)) {
            return $this->customerNotFoundResponse();
        }

        try {
            $data = $this->validatedAdjustmentData($request);
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Reward balance adjustment could not be saved.', $exception);
        }

        $result = $this->applyCandleCashAdjustment(
            profile: $marketingProfile,
            data: $data,
            adjustmentService: $adjustmentService,
            messagingService: $messagingService,
            candleCashService: $candleCashService
        );

        return response()->json([
            'ok' => true,
            'message' => $result['notice_message'],
            'notice_style' => $result['notice_style'],
            'data' => [
                'transaction_id' => $result['transaction_id'],
                'balance' => $result['balance'],
                'balance_display' => $result['balance_display'],
            ],
        ]);
    }

    public function redirectLegacyToManage(Request $request): Response|RedirectResponse
    {
        Log::info('shopify.embedded.legacy.manage.access', [
            'route' => $request->route()?->getName(),
            'query' => $request->query->all(),
        ]);

        return $this->redirectToEmbeddedRoute($request, 'customers.manage');
    }

    public function redirectLegacyToSegments(Request $request): Response|RedirectResponse
    {
        Log::info('shopify.embedded.legacy.segments.access', [
            'route' => $request->route()?->getName(),
            'query' => $request->query->all(),
        ]);

        return $this->redirectToEmbeddedRoute($request, 'customers.segments');
    }

    public function redirectLegacyToActivity(Request $request): Response|RedirectResponse
    {
        Log::info('shopify.embedded.legacy.activity.access', [
            'route' => $request->route()?->getName(),
            'query' => $request->query->all(),
        ]);

        return $this->redirectToEmbeddedRoute($request, 'customers.activity');
    }

    public function redirectLegacyToDetail(
        Request $request,
        MarketingProfile $marketingProfile
    ): Response|RedirectResponse {
        Log::info('shopify.embedded.legacy.detail.access', [
            'route' => $request->route()?->getName(),
            'profile_id' => $marketingProfile->id,
            'query' => $request->query->all(),
        ]);

        return $this->redirectToEmbeddedRoute(
            $request,
            'customers.detail',
            ['marketingProfile' => $marketingProfile->id]
        );
    }

    public function redirectLegacyToImports(Request $request): Response|RedirectResponse
    {
        Log::info('shopify.embedded.legacy.imports.access', [
            'route' => $request->route()?->getName(),
            'query' => $request->query->all(),
        ]);

        return $this->redirectToEmbeddedRoute($request, 'customers.imports');
    }

    protected function redirectToEmbeddedRoute(
        Request $request,
        string $routeName,
        array $routeParameters = []
    ): Response|RedirectResponse {
        /** @var ShopifyEmbeddedUrlGenerator $urlGenerator */
        $urlGenerator = app(ShopifyEmbeddedUrlGenerator::class);
        $target = $urlGenerator->route(
            'shopify.app.'.$routeName,
            $routeParameters,
            false,
            $request
        );

        if ($target === '' || str_contains($target, '?') === false) {
            return $this->embeddedContextMissingResponse();
        }

        Log::info('shopify.embedded.legacy.redirect', [
            'route' => $routeName,
            'profile_id' => $routeParameters['marketingProfile'] ?? null,
            'target' => $target,
        ]);

        return redirect()->to($target);
    }

    protected function embeddedContextMissingResponse(): Response
    {
        Log::warning('shopify.embedded.context_missing', [
            'url' => request()->fullUrl(),
        ]);

        return response()->view('shopify.embedded-context-missing', [
            'message' => 'This page must be opened from Shopify Admin with the Shopify context.',
        ], 400);
    }

    public function sendMessageJson(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomerMessagingService $messagingService,
        TenantResolver $tenantResolver,
        MarketingProfile $marketingProfile
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        $this->logCustomerAction($request, $marketingProfile, 'message.send.json', (bool) ($context['ok'] ?? false));

        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        if (! $this->customerBelongsToEmbeddedContext($marketingProfile, $context, $tenantResolver)) {
            return $this->customerNotFoundResponse();
        }

        try {
            $data = $this->validatedMessageData($request);
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Message could not be sent.', $exception);
        }

        $result = $this->applyMessageSend($marketingProfile, $data, $messagingService, $context);

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => $result['message'],
                'notice_style' => 'warning',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => $result['message'],
            'notice_style' => 'success',
        ]);
    }

    public function sendCandleCashJson(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomerSendCandleCashService $sendService,
        ShopifyEmbeddedCustomerMessagingService $messagingService,
        CandleCashService $candleCashService,
        TenantResolver $tenantResolver,
        MarketingProfile $marketingProfile
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        $this->logCustomerAction($request, $marketingProfile, 'candle_cash.send.json', (bool) ($context['ok'] ?? false));

        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        if (! $this->customerBelongsToEmbeddedContext($marketingProfile, $context, $tenantResolver)) {
            return $this->customerNotFoundResponse();
        }

        try {
            $data = $this->validatedSendCandleCashData($request);
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Send reward credit could not be saved.', $exception);
        }

        $result = $this->applySendCandleCash(
            profile: $marketingProfile,
            data: $data,
            sendService: $sendService,
            messagingService: $messagingService,
            candleCashService: $candleCashService
        );

        return response()->json([
            'ok' => true,
            'message' => $result['notice_message'],
            'notice_style' => $result['notice_style'],
            'data' => [
                'transaction_id' => $result['transaction_id'],
                'balance' => $result['balance'],
                'balance_display' => $result['balance_display'],
            ],
        ]);
    }

    protected function renderPage(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        string $view,
        string $subnavKey,
        string $defaultHeadline,
        string $defaultSubheadline,
        array $extraViewData,
        ?array $resolvedContext = null,
        ?int $resolvedTenantId = null,
        ?ShopifyEmbeddedPerformanceProbe $probe = null
    ): Response {
        $probe ??= $this->embeddedProbe($request);
        $context = $resolvedContext ?? $probe->time('context', fn (): array => $contextService->resolvePageContext($request));

        Log::info('shopify.embedded.manage.context', [
            'route' => $request->route()?->getName(),
            'ok' => (bool) ($context['ok'] ?? false),
            'status' => $context['status'] ?? null,
        ]);

        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? ($resolvedTenantId ?? $probe->time('tenant_resolve', fn (): ?int => app(TenantResolver::class)->resolveTenantIdForStoreContext($store)))
            : null;
        $probe->forTenant($tenantId);
        /** @var ShopifyEmbeddedShellPayloadBuilder $payloadBuilder */
        $payloadBuilder = app(ShopifyEmbeddedShellPayloadBuilder::class);
        $labels = $probe->time('shell_payload', fn (): array => $payloadBuilder->displayLabels($tenantId, $request));
        $rewardsLabel = trim((string) ($labels['rewards_label'] ?? $labels['rewards'] ?? 'Rewards'));
        if ($rewardsLabel === '') {
            $rewardsLabel = 'Rewards';
        }
        $rewardsBalanceLabel = trim((string) ($labels['rewards_balance_label'] ?? ($rewardsLabel.' balance')));
        if ($rewardsBalanceLabel === '') {
            $rewardsBalanceLabel = $rewardsLabel.' balance';
        }
        $rewardCreditLabel = trim((string) ($labels['reward_credit_label'] ?? 'reward credit'));
        if ($rewardCreditLabel === '') {
            $rewardCreditLabel = 'reward credit';
        }

        $tokenMap = [
            '{{rewards_label}}' => $rewardsLabel,
            '{{rewards_label_lc}}' => strtolower($rewardsLabel),
            '{{rewards_balance_label}}' => $rewardsBalanceLabel,
            '{{rewards_balance_label_lc}}' => strtolower($rewardsBalanceLabel),
            '{{reward_credit_label}}' => $rewardCreditLabel,
        ];
        $resolvedHeadline = strtr($this->headlineForStatus($status, $defaultHeadline), $tokenMap);
        $resolvedSubheadline = strtr($this->subheadlineForStatus($status, $defaultSubheadline), $tokenMap);

        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('customers', null, $tenantId));
        $pageSubnav = $probe->time('shell_payload', fn (): array => $this->customerSubnav($subnavKey, $tenantId));
        $merchantJourney = $authorized
            ? $probe->time('page_payload', fn (): array => app(TenantCommercialExperienceService::class)->merchantJourneyPayload($tenantId))
            : null;

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view($view, array_merge([
                'authorized' => $authorized,
                'status' => $status,
                'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
                'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
                'host' => $authorized ? (string) ($context['host'] ?? '') : ($context['host'] ?? null),
                'storeLabel' => $authorized
                    ? ucfirst((string) ($store['key'] ?? 'store')) . ' Store'
                    : 'Shopify Admin',
                'headline' => $resolvedHeadline,
                'subheadline' => $resolvedSubheadline,
                'appNavigation' => $appNavigation,
                'pageSubnav' => $pageSubnav,
                'pageActions' => [],
                'displayLabels' => $labels,
                'rewardsLabel' => $rewardsLabel,
                'rewardsBalanceLabel' => $rewardsBalanceLabel,
                'rewardCreditLabel' => $rewardCreditLabel,
                'merchantJourney' => $merchantJourney,
            ], $extraViewData)),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        ));

        return $probe->addContext([
            'authorized' => $authorized,
            'status' => $status,
        ])->finish($response);
    }

    protected function embeddedResponse(Response $response, int $status = 200): Response
    {
        $response->setStatusCode($status);
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;"
        );
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    protected function logCustomerAction(Request $request, MarketingProfile $profile, string $action, bool $contextOk): void
    {
        Log::info('shopify.embedded.customer_action', [
            'action' => $action,
            'profile_id' => $profile->id,
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
            'action_context_ok' => $contextOk,
            'payload' => $this->sanitizeCustomerActionPayload($request),
        ]);
    }

    protected function sanitizeCustomerActionPayload(Request $request): array
    {
        $payload = $request->except(['_token', '_method']);

        return collect($payload)
            ->map(fn ($value) => is_string($value)
                ? mb_strlen($value) > 220
                    ? mb_substr($value, 0, 220) . '…'
                    : trim($value)
                : $value)
            ->all();
    }

    /**
     * @return array{first_name:?string,last_name:?string,email:?string,phone:?string}
     */
    protected function validatedIdentityData(Request $request): array
    {
        /** @var array{first_name:?string,last_name:?string,email:?string,phone:?string} $data */
        $data = validator($request->all(), [
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ])->validate();

        return $data;
    }

    /**
     * @param array{first_name:?string,last_name:?string,email:?string,phone:?string} $data
     */
    protected function applyIdentityUpdate(
        MarketingProfile $marketingProfile,
        array $data,
        MarketingIdentityNormalizer $identityNormalizer
    ): void {
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));

        $marketingProfile->forceFill([
            'first_name' => trim((string) ($data['first_name'] ?? '')) ?: null,
            'last_name' => trim((string) ($data['last_name'] ?? '')) ?: null,
            'email' => $email !== '' ? $email : null,
            'normalized_email' => $email !== '' ? $identityNormalizer->normalizeEmail($email) : null,
            'phone' => $phone !== '' ? $phone : null,
            'normalized_phone' => $phone !== '' ? $identityNormalizer->normalizePhone($phone) : null,
        ])->save();
    }

    /**
     * @return array{direction:string,amount:int,reason:string}
     */
    protected function validatedAdjustmentData(Request $request): array
    {
        /** @var array{direction:string,amount:int,reason:string} $data */
        $data = validator($request->all(), [
            'direction' => ['required', 'in:add,subtract'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000'],
            'reason' => ['required', 'string', 'max:500'],
        ])->validate();

        return $data;
    }

    /**
     * @return array{channel:string,consented:bool,notes:?string}
     */
    protected function validatedConsentData(Request $request): array
    {
        /** @var array{channel:string,consented:bool,notes:?string} $data */
        $data = validator($request->all(), [
            'channel' => ['required', 'in:sms,email,both'],
            'consented' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return $data;
    }

    /**
     * @param  array{channel:string,consented:bool,notes:?string}  $data
     * @return array{notice_style:string,notice_message:string}
     */
    protected function applyConsentUpdate(
        MarketingProfile $marketingProfile,
        array $data,
        MarketingConsentService $consentService
    ): array {
        $consented = (bool) $data['consented'];
        $channel = (string) $data['channel'];
        $notes = trim((string) ($data['notes'] ?? '')) ?: null;

        $contextPayload = [
            'source_type' => 'shopify_embedded_admin',
            'source_id' => (string) (auth()->id() ?? 'embedded'),
            'details' => [
                'notes' => $notes,
            ],
        ];

        $changed = false;
        if ($channel === 'sms' || $channel === 'both') {
            $changed = $consentService->setSmsConsent($marketingProfile, $consented, $contextPayload) || $changed;
        }
        if ($channel === 'email' || $channel === 'both') {
            $changed = $consentService->setEmailConsent($marketingProfile, $consented, $contextPayload) || $changed;
        }

        return [
            'notice_style' => $changed ? 'success' : 'warning',
            'notice_message' => $changed
                ? 'Consent updated.'
                : 'Consent already set to that value.',
        ];
    }

    /**
     * @return array{channel:string,message:string,sender_key:?string}
     */
    protected function validatedMessageData(Request $request): array
    {
        /** @var array{channel:string,message:string,sender_key:?string} $data */
        $data = validator($request->all(), [
            'channel' => ['required', 'in:sms'],
            'message' => ['required', 'string', 'max:1000'],
            'sender_key' => ['nullable', 'string', 'max:80'],
        ])->validate();

        return $data;
    }

    /**
     * @param  array{channel:string,message:string,sender_key:?string}  $data
     * @param  array<string,mixed>  $context
     * @return array{ok:bool,message:string}
     */
    protected function applyMessageSend(
        MarketingProfile $marketingProfile,
        array $data,
        ShopifyEmbeddedCustomerMessagingService $messagingService,
        array $context = []
    ): array {
        $channel = (string) $data['channel'];
        $message = trim((string) $data['message']);
        $senderKey = trim((string) ($data['sender_key'] ?? '')) ?: null;

        $result = match ($channel) {
            'sms' => $messagingService->sendSms($marketingProfile, $message, auth()->id(), $senderKey),
            default => [
                'ok' => false,
                'message' => 'Message channel is not supported.',
            ],
        };

        if (! $result['ok']) {
            Log::warning('Shopify embedded customer message failed', [
                'profile_id' => $marketingProfile->id,
                'channel' => $channel,
                'failure_message' => $result['message'] ?? 'unknown',
                'context_status' => $context['status'] ?? 'unknown',
            ]);
        }

        return $result;
    }

    /**
     * @param array{direction:string,amount:int,reason:string} $data
     * @return array{
     *   balance:int,
     *   balance_display:string,
     *   transaction_id:int|null,
     *   notice_style:string,
     *   notice_message:string
     * }
     */
    protected function applyCandleCashAdjustment(
        MarketingProfile $profile,
        array $data,
        ShopifyEmbeddedCustomerCandleCashAdjustmentService $adjustmentService,
        ShopifyEmbeddedCustomerMessagingService $messagingService,
        CandleCashService $candleCashService
    ): array {
        $direction = (string) $data['direction'];
        $amount = (int) $data['amount'];
        $reason = trim((string) $data['reason']);

        $result = $adjustmentService->adjust(
            profile: $profile,
            direction: $direction,
            amount: $amount,
            reason: $reason,
            actorId: (string) (auth()->id() ?? 'embedded')
        );

        $balance = (int) ($result['balance'] ?? 0);
        $balanceDisplay = $candleCashService->formatRewardCurrency($candleCashService->amountFromPoints($balance));
        $noticeStyle = 'success';
        $noticeMessage = 'Balance adjusted. New balance: ' . $balanceDisplay . '.';

        Log::info('Shopify embedded Candle Cash adjustment applied', [
            'profile_id' => $profile->id,
            'direction' => $direction,
            'amount' => $amount,
            'reason' => $reason,
            'balance' => $balance,
            'transaction_id' => $result['transaction_id'] ?? null,
        ]);

        if ($direction === 'add' && $messagingService->smsSupported()) {
            $smsResult = $messagingService->sendCandleCashAdjustmentAwardedSms(
                $profile,
                $amount,
                auth()->id()
            );

            if (! $smsResult['ok']) {
                $noticeStyle = 'warning';
                $noticeMessage .= ' (Program message not sent: ' . $smsResult['message'] . ')';

                Log::warning('Shopify embedded Candle Cash adjustment reward notification failed', [
                    'profile_id' => $profile->id,
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'failure_message' => $smsResult['message'] ?? 'unknown',
                ]);
            } else {
                $noticeMessage .= ' Program message sent.';

                Log::info('Shopify embedded Candle Cash adjustment reward notification sent', [
                    'profile_id' => $profile->id,
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'amount' => $amount,
                ]);
            }
        }

        return [
            'balance' => $balance,
            'balance_display' => $balanceDisplay,
            'transaction_id' => isset($result['transaction_id']) ? (int) $result['transaction_id'] : null,
            'notice_style' => $noticeStyle,
            'notice_message' => $noticeMessage,
        ];
    }

    /**
     * @return array{
     *   amount:int,
     *   reason:string,
     *   message:?string,
     *   sender_key:?string,
     *   gift_intent:?string,
     *   gift_origin:?string,
     *   campaign_key:?string
     * }
     */
    protected function validatedSendCandleCashData(Request $request): array
    {
        $intentValues = implode(',', array_keys(self::giftIntentOptions()));
        $originValues = implode(',', array_keys(self::giftOriginOptions()));

        /** @var array{
         *   amount:int,
         *   reason:string,
         *   message:?string,
         *   sender_key:?string,
         *   gift_intent:?string,
         *   gift_origin:?string,
         *   campaign_key:?string
         * } $data
         */
        $data = validator($request->all(), [
            'amount' => ['required', 'integer', 'min:1', 'max:100000'],
            'reason' => ['required', 'string', 'max:500'],
            'message' => ['nullable', 'string', 'max:1000'],
            'sender_key' => ['nullable', 'string', 'max:80'],
            'gift_intent' => ['nullable', 'string', 'in:' . $intentValues],
            'gift_origin' => ['nullable', 'string', 'in:' . $originValues],
            'campaign_key' => ['nullable', 'string', 'max:100'],
        ])->validate();

        return $data;
    }

    /**
     * @param array{
     *   amount:int,
     *   reason:string,
     *   message:?string,
     *   sender_key:?string,
     *   gift_intent:?string,
     *   gift_origin:?string,
     *   campaign_key:?string
     * } $data
     * @return array{
     *   balance:int,
     *   balance_display:string,
     *   transaction_id:int|null,
     *   notice_style:string,
     *   notice_message:string
     * }
     */
    protected function applySendCandleCash(
        MarketingProfile $profile,
        array $data,
        ShopifyEmbeddedCustomerSendCandleCashService $sendService,
        ShopifyEmbeddedCustomerMessagingService $messagingService,
        CandleCashService $candleCashService
    ): array {
        $amount = (int) $data['amount'];
        $reason = trim((string) $data['reason']);
        $message = trim((string) ($data['message'] ?? ''));
        $senderKey = trim((string) ($data['sender_key'] ?? '')) ?: null;
        $giftIntent = self::normalizeNullableString($data['gift_intent'] ?? null);
        $giftOrigin = self::normalizeNullableString($data['gift_origin'] ?? null);
        $campaignKey = self::normalizeNullableString($data['campaign_key'] ?? null);

        $metadata = [
            'gift_intent' => $giftIntent,
            'gift_origin' => $giftOrigin,
            'campaign_key' => $campaignKey,
            'notified_via' => 'none',
            'notification_status' => 'skipped',
        ];

        $result = $sendService->send(
            profile: $profile,
            amount: $amount,
            reason: $reason,
            actorId: (string) (auth()->id() ?? 'embedded'),
            metadata: $metadata
        );

        Log::info('Shopify embedded Candle Cash sent', [
            'profile_id' => $profile->id,
            'amount' => $amount,
            'reason' => $reason,
            'actor_id' => auth()->id(),
            'balance' => round((float) ($result['balance'] ?? 0), 3),
            'transaction_id' => $result['transaction_id'] ?? null,
            'metadata' => $metadata,
        ]);

        $balance = round((float) ($result['balance'] ?? 0), 3);
        $balanceDisplay = $candleCashService->formatRewardCurrency($candleCashService->amountFromPoints($balance));
        $noticeMessage = 'Credit sent. New balance: ' . $balanceDisplay . '.';
        $noticeStyle = 'success';
        $transactionId = isset($result['transaction_id']) ? (int) $result['transaction_id'] : null;

        if ($message !== '') {
            $smsResult = $messagingService->sendSms($profile, $message, auth()->id(), $senderKey);
            $metadataUpdate = [
                'notified_via' => 'sms',
                'notification_status' => $smsResult['ok'] ? 'sent' : 'failed',
            ];
            if ($transactionId !== null) {
                CandleCashTransaction::query()
                    ->whereKey($transactionId)
                    ->update($metadataUpdate);
            }
            if (! $smsResult['ok']) {
                $noticeStyle = 'warning';
                $noticeMessage .= ' (Message not sent: ' . $smsResult['message'] . ')';
                Log::warning('Shopify embedded Candle Cash notification failed', [
                    'profile_id' => $profile->id,
                    'transaction_id' => $transactionId,
                    'failure_message' => $smsResult['message'] ?? 'unknown',
                ]);
            } else {
                Log::info('Shopify embedded Candle Cash notification sent', [
                    'profile_id' => $profile->id,
                    'transaction_id' => $transactionId,
                ]);
            }
        }

        return [
            'balance' => $balance,
            'balance_display' => $balanceDisplay,
            'transaction_id' => $transactionId,
            'notice_style' => $noticeStyle,
            'notice_message' => $noticeMessage,
        ];
    }

    protected function customerBelongsToEmbeddedContext(
        MarketingProfile $marketingProfile,
        array $context,
        TenantResolver $tenantResolver
    ): bool {
        $tenantId = $this->resolveEmbeddedTenantId($context, $tenantResolver);
        $query = MarketingProfile::query()->whereKey($marketingProfile->id);

        if ($tenantId === null) {
            return $query->whereNull('tenant_id')->exists();
        }

        return $query->where('tenant_id', $tenantId)->exists();
    }

    /**
     * @param  array<string,mixed>  $context
     */
    protected function resolveEmbeddedTenantId(array $context, TenantResolver $tenantResolver): ?int
    {
        return $tenantResolver->resolveTenantIdForStoreContext((array) ($context['store'] ?? []));
    }

    /**
     * @return array{
     *   id:int,
     *   display_name:string,
     *   email:?string,
     *   email_display:string,
     *   phone:?string,
     *   phone_display:string,
     *   updated_at_display:string
     * }
     */
    protected function customerIdentityPayload(MarketingProfile $marketingProfile): array
    {
        return [
            'id' => (int) $marketingProfile->id,
            'display_name' => $this->customerDisplayName($marketingProfile),
            'email' => $marketingProfile->email,
            'email_display' => $marketingProfile->email ?: 'Email not set',
            'phone' => $marketingProfile->phone,
            'phone_display' => $marketingProfile->phone ?: 'Phone not set',
            'updated_at_display' => optional($marketingProfile->updated_at)->format('Y-m-d H:i') ?: '—',
        ];
    }

    /**
     * @return array{
     *   email_label:string,
     *   sms_label:string,
     *   sms_message_eligibility:string
     * }
     */
    protected function customerConsentPayload(MarketingProfile $marketingProfile): array
    {
        $emailConsented = (bool) ($marketingProfile->accepts_email_marketing ?? false);
        $smsConsented = (bool) ($marketingProfile->accepts_sms_marketing ?? false);

        return [
            'email_label' => $emailConsented ? 'Consented' : 'Not consented',
            'sms_label' => $smsConsented ? 'Consented' : 'Not consented',
            'sms_message_eligibility' => $smsConsented ? 'Consented' : 'Consent needed',
        ];
    }

    protected function customerDisplayName(MarketingProfile $marketingProfile): string
    {
        $displayName = trim((string) ($marketingProfile->first_name . ' ' . $marketingProfile->last_name));

        if ($displayName !== '') {
            return $displayName;
        }

        return $marketingProfile->email ?: ($marketingProfile->phone ?: 'Customer #' . $marketingProfile->id);
    }

    protected function invalidApiContextResponse(array $context): JsonResponse
    {
        $status = (string) ($context['status'] ?? 'invalid_request');
        $messages = [
            'open_from_shopify' => 'Open the app from Shopify Admin to load this customer.',
            'missing_api_auth' => 'This embedded customer action requires a verified Shopify session token.',
            'missing_shop' => 'The Shopify shop context is missing from this request.',
            'unknown_shop' => 'This Shopify shop is not mapped to a Backstage store.',
            'invalid_hmac' => 'This Shopify request could not be verified.',
            'invalid_session_token' => 'This Shopify session token could not be verified.',
            'expired_session_token' => 'This Shopify session expired. Reload the app from Shopify Admin.',
        ];

        return response()->json([
            'ok' => false,
            'message' => $messages[$status] ?? 'This embedded Shopify request could not be verified.',
            'status' => $status,
        ], $status === 'open_from_shopify' ? 400 : 401);
    }

    protected function customerNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'Customer not found for this Shopify store.',
        ], 404);
    }

    protected function validationFailureResponse(string $message, ValidationException $exception): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => $message,
            'errors' => $exception->errors(),
        ], 422);
    }

    /**
     * @param array{
     *   paginator:mixed,
     *   filters:array<string,string|int>,
     *   sort_options:array<int,array{value:string,label:string}>,
     *   active_filter_count:int
     * } $grid
     * @param  array<string,string>  $displayLabels
     * @return array{results_html:string,summary_label:string,page_label:string}
     */
    protected function customersManagePayload(array $grid, array $displayLabels = []): array
    {
        $customers = $grid['paginator'];
        $filters = $grid['filters'];

        return [
            'results_html' => view('shopify.partials.customers-manage-results', [
                'customers' => $customers,
                'filters' => $filters,
                'sort' => (string) ($filters['sort'] ?? 'last_activity'),
                'direction' => (string) ($filters['direction'] ?? 'desc'),
                'displayLabels' => $displayLabels,
            ])->render(),
            'summary_label' => $this->customersSummaryLabel($customers),
            'page_label' => $this->customersPageLabel($customers),
        ];
    }

    protected function customersSummaryLabel(mixed $customers): string
    {
        $count = method_exists($customers, 'count') ? (int) $customers->count() : 0;
        $page = method_exists($customers, 'currentPage') ? (int) $customers->currentPage() : 1;

        return sprintf(
            '%s customer%s loaded · Page %s',
            number_format($count),
            $count === 1 ? '' : 's',
            number_format($page)
        );
    }

    protected function customersPageLabel(mixed $customers): string
    {
        if (! method_exists($customers, 'currentPage')) {
            return 'Page 1';
        }

        $page = (int) $customers->currentPage();
        $more = method_exists($customers, 'hasMorePages') && $customers->hasMorePages();

        return $more
            ? sprintf('Page %s · More results available', number_format($page))
            : sprintf('Page %s', number_format($page));
    }

    protected function withServerTiming(Response|JsonResponse $response, array $metrics): Response|JsonResponse
    {
        $values = collect($metrics)
            ->filter(fn ($value): bool => is_numeric($value))
            ->map(fn ($value, $name): string => sprintf('%s;dur=%s', $name, number_format((float) $value, 2, '.', '')))
            ->values()
            ->all();

        if ($values !== []) {
            $existing = trim((string) $response->headers->get('Server-Timing', ''));
            $combined = array_filter([$existing, implode(', ', $values)]);
            $response->headers->set('Server-Timing', implode(', ', $combined));
        }

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
    protected function customerSubnav(string $activeKey, ?int $tenantId = null): array
    {
        /** @var ShopifyEmbeddedShellPayloadBuilder $payloadBuilder */
        $payloadBuilder = app(ShopifyEmbeddedShellPayloadBuilder::class);

        return $payloadBuilder->customerSubnav($activeKey, $tenantId, request());
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
            'open_from_shopify' => 'This page is meant to load inside your Shopify admin so it can verify the store context.',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'Open the app again from Shopify Admin. If this keeps happening, the store app config needs attention.',
            default => $defaultSubheadline,
        };
    }

    private static function giftIntentOptions(): array
    {
        return self::GIFT_INTENT_OPTIONS;
    }

    private static function giftOriginOptions(): array
    {
        return self::GIFT_ORIGIN_OPTIONS;
    }

    private static function normalizeNullableString(?string $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }
}
