<?php

namespace App\Http\Controllers;

use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Services\Marketing\CandleCashService;
use App\Support\Diagnostics\ShopifyEmbeddedCsrfDiagnostics;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedCustomerActionUrlGenerator;
use App\Services\Shopify\ShopifyEmbeddedCustomerCandleCashAdjustmentService;
use App\Services\Shopify\ShopifyEmbeddedCustomerDetailService;
use App\Services\Shopify\ShopifyEmbeddedCustomerMessagingService;
use App\Services\Shopify\ShopifyEmbeddedCustomerSendCandleCashService;
use App\Services\Shopify\ShopifyEmbeddedCustomersGridService;
use App\Services\Tenancy\TenantResolver;
use App\Support\Shopify\ShopifyEmbeddedContextQuery;
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
    ): Response {
        $grid = $gridService->resolve($request);

        Log::info('shopify.embedded.manage.render', [
            'route' => $request->route()?->getName(),
            'query' => $request->query->all(),
        ]);

        return $this->renderPage(
            request: $request,
            contextService: $contextService,
            view: 'shopify.customers-manage',
            subnavKey: 'manage',
            defaultHeadline: 'Customers',
            defaultSubheadline: 'Manage Candle Cash customer records, statuses, and operational workflows from a single workspace.',
            extraViewData: [
                'customers' => $grid['paginator'],
                'gridFilters' => $grid['filters'],
                'gridSortOptions' => $grid['sort_options'],
                'activeFilterCount' => $grid['active_filter_count'],
            ]
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
            defaultHeadline: 'Customers Activity',
            defaultSubheadline: 'Track customer-facing Candle Cash and profile events with clear operational visibility.',
            extraViewData: []
        );
    }

    public function questions(
        Request $request,
        ShopifyEmbeddedAppContext $contextService
    ): Response {
        return $this->renderPage(
            request: $request,
            contextService: $contextService,
            view: 'shopify.customers-questions',
            subnavKey: 'questions',
            defaultHeadline: 'Customer Questions',
            defaultSubheadline: 'Reference support guidance and operational answers tied to customer rewards behavior.',
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
        $context = $contextService->resolvePageContext($request);
        $authorized = (bool) ($context['ok'] ?? false);

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

        $detail = $authorized ? $detailService->build($marketingProfile) : [
            'summary' => [],
            'statuses' => [],
            'activity' => [],
            'external_profiles' => collect(),
            'consent' => [],
            'messaging' => [],
        ];

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
        ]);

        return $this->renderPage(
            request: $request,
            contextService: $contextService,
            view: 'shopify.customers-detail',
            subnavKey: 'manage',
            defaultHeadline: 'Customer Detail',
            defaultSubheadline: 'A dedicated customer workspace for identity, Candle Cash, and lifecycle status.',
            extraViewData: [
                'marketingProfile' => $marketingProfile,
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
            ],
            resolvedContext: $context,
        );
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
            return $this->validationFailureResponse('Candle Cash adjustment could not be saved.', $exception);
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

    protected function redirectToEmbeddedRoute(
        Request $request,
        string $routeName,
        array $routeParameters = []
    ): Response|RedirectResponse {
        $context = $this->embeddedContextQuery($request);

        if ($context === []) {
            return $this->embeddedContextMissingResponse();
        }

        $canonicalRoute = route('shopify.app.' . $routeName, $routeParameters, false);
        $separator = str_contains($canonicalRoute, '?') ? '&' : '?';
        $target = $canonicalRoute . $separator . http_build_query($context, '', '&', PHP_QUERY_RFC3986);

        Log::info('shopify.embedded.legacy.redirect', [
            'route' => $routeName,
            'profile_id' => $routeParameters['marketingProfile'] ?? null,
            'target' => $target,
        ]);

        return redirect()->to($target);
    }

    protected function embeddedContextQuery(Request $request): array
    {
        return ShopifyEmbeddedContextQuery::fromRequest($request);
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
            return $this->validationFailureResponse('Send Candle Cash could not be saved.', $exception);
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
        ?array $resolvedContext = null
    ): Response {
        $context = $resolvedContext ?? $contextService->resolvePageContext($request);

        Log::info('shopify.embedded.manage.context', [
            'route' => $request->route()?->getName(),
            'ok' => (bool) ($context['ok'] ?? false),
            'status' => $context['status'] ?? null,
        ]);

        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);

        return $this->embeddedResponse(
            response()->view($view, array_merge([
                'authorized' => $authorized,
                'status' => $status,
                'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
                'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
                'host' => $authorized ? (string) ($context['host'] ?? '') : ($context['host'] ?? null),
                'storeLabel' => $authorized
                    ? ucfirst((string) ($store['key'] ?? 'store')) . ' Store'
                    : 'Shopify Admin',
                'headline' => $this->headlineForStatus($status, $defaultHeadline),
                'subheadline' => $this->subheadlineForStatus($status, $defaultSubheadline),
                'appNavigation' => $this->embeddedAppNavigation('customers'),
                'pageSubnav' => $this->customerSubnav($subnavKey),
                'pageActions' => [],
            ], $extraViewData)),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        );
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
        $noticeMessage = 'Candle Cash adjusted. New balance: ' . $balanceDisplay . '.';

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
                $noticeMessage .= ' (Reward message not sent: ' . $smsResult['message'] . ')';

                Log::warning('Shopify embedded Candle Cash adjustment reward notification failed', [
                    'profile_id' => $profile->id,
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'failure_message' => $smsResult['message'] ?? 'unknown',
                ]);
            } else {
                $noticeMessage .= ' Reward message sent.';

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
        $noticeMessage = 'Candle Cash sent. New balance: ' . $balanceDisplay . '.';
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
        $tenantId = $tenantResolver->resolveTenantIdForStoreContext((array) ($context['store'] ?? []));
        if ($tenantId === null) {
            return true;
        }

        return (int) ($marketingProfile->tenant_id ?? 0) === $tenantId;
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
     * @return array<int,array{key:string,label:string,href:string,active:bool}>
     */
    protected function customerSubnav(string $activeKey): array
    {
        $items = [
            ['key' => 'manage', 'label' => 'Manage customers', 'href' => route('shopify.app.customers.manage', [], false)],
            ['key' => 'activity', 'label' => 'Activity', 'href' => route('shopify.app.customers.activity', [], false)],
            ['key' => 'questions', 'label' => 'Questions', 'href' => route('shopify.app.customers.questions', [], false)],
        ];

        return array_map(
            fn (array $item): array => array_merge($item, ['active' => $item['key'] === $activeKey]),
            $items
        );
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
