<?php

namespace App\Http\Controllers;

use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Support\Diagnostics\ShopifyEmbeddedCsrfDiagnostics;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedCustomerActionUrlGenerator;
use App\Services\Shopify\ShopifyEmbeddedCustomerCandleCashAdjustmentService;
use App\Services\Shopify\ShopifyEmbeddedCustomerDetailService;
use App\Services\Shopify\ShopifyEmbeddedCustomerMessagingService;
use App\Services\Shopify\ShopifyEmbeddedCustomerSendCandleCashService;
use App\Services\Shopify\ShopifyEmbeddedCustomersGridService;
use App\Support\Shopify\ShopifyEmbeddedContextQuery;
use App\Services\Marketing\MarketingConsentService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
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
        MarketingProfile $marketingProfile
    ): Response {
        $context = $contextService->resolvePageContext($request);
        $authorized = (bool) ($context['ok'] ?? false);

        $displayName = 'Customer detail unavailable';
        if ($authorized) {
            $displayName = trim((string) ($marketingProfile->first_name . ' ' . $marketingProfile->last_name));
            if ($displayName === '') {
                $displayName = $marketingProfile->email ?: ($marketingProfile->phone ?: 'Customer #' . $marketingProfile->id);
            }
        }

        $detail = $authorized ? $detailService->build($marketingProfile) : [
            'summary' => [],
            'statuses' => [],
            'activity' => [],
            'external_profiles' => collect(),
            'consent' => [],
            'messaging' => [],
        ];

        $formActions = $authorized ? [
            'update' => $actionUrlGenerator->url('customers.update', ['marketingProfile' => $marketingProfile->id], $request),
            'candle_cash_adjust' => $actionUrlGenerator->url('customers.candle-cash.adjust', ['marketingProfile' => $marketingProfile->id], $request),
            'candle_cash_send' => $actionUrlGenerator->url('customers.candle-cash.send', ['marketingProfile' => $marketingProfile->id], $request),
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
            ],
            resolvedContext: $context,
        );
}

    public function update(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomerActionUrlGenerator $actionUrlGenerator,
        MarketingIdentityNormalizer $identityNormalizer,
        MarketingProfile $marketingProfile
    ): RedirectResponse {
        $context = $contextService->resolvePageContext($request);
        $this->logCustomerPostPreflight($request, $marketingProfile, 'identity.update', $context);
        $this->requireValidCsrf($request, 'identity.update', $marketingProfile, $context);
        $this->logCustomerAction($request, $marketingProfile, 'identity.update', (bool) ($context['ok'] ?? false));
        if (! ($context['ok'] ?? false)) {
            Log::warning('Shopify embedded customer identity update blocked', [
                'profile_id' => $marketingProfile->id,
                'route' => $request->route()?->getName(),
                'status' => $context['status'] ?? 'unknown',
            ]);
            return $this->redirectToCustomerDetail($request, $actionUrlGenerator, $marketingProfile)
                ->with('customer_detail_notice', [
                'style' => 'warning',
                'message' => 'Customer update failed: store context could not be verified.',
            ]);
        }

        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

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

        return $this->redirectToCustomerDetail($request, $actionUrlGenerator, $marketingProfile)
            ->with('customer_detail_notice', [
                'style' => 'success',
                'message' => 'Customer identity updated.',
            ]);
    }

    public function updateConsent(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomerActionUrlGenerator $actionUrlGenerator,
        MarketingConsentService $consentService,
        MarketingProfile $marketingProfile
    ): RedirectResponse {
        $context = $contextService->resolvePageContext($request);
        $this->logCustomerPostPreflight($request, $marketingProfile, 'consent.update', $context);
        $this->requireValidCsrf($request, 'consent.update', $marketingProfile, $context);
        $this->logCustomerAction($request, $marketingProfile, 'consent.update', (bool) ($context['ok'] ?? false));
        if (! ($context['ok'] ?? false)) {
            Log::warning('Shopify embedded customer consent update blocked', [
                'profile_id' => $marketingProfile->id,
                'route' => $request->route()?->getName(),
                'status' => $context['status'] ?? 'unknown',
            ]);
            return $this->redirectToCustomerDetail($request, $actionUrlGenerator, $marketingProfile)
                ->with('customer_detail_notice', [
                    'style' => 'warning',
                    'message' => 'Consent update failed: store context could not be verified.',
                ]);
        }

        $data = $request->validate([
            'channel' => ['required', 'in:sms,email,both'],
            'consented' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

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

        return $this->redirectToCustomerDetail($request, $actionUrlGenerator, $marketingProfile)
            ->with('customer_detail_notice', [
                'style' => $changed ? 'success' : 'warning',
                'message' => $changed
                    ? 'Consent updated.'
                    : 'Consent already set to that value.',
            ]);
    }

    public function adjustCandleCash(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomerActionUrlGenerator $actionUrlGenerator,
        ShopifyEmbeddedCustomerCandleCashAdjustmentService $adjustmentService,
        ShopifyEmbeddedCustomerMessagingService $messagingService,
        MarketingProfile $marketingProfile
    ): RedirectResponse {
        Log::info('shopify.embedded.candle_cash.adjust.entry', [
            'route' => $request->route()?->getName(),
            'profile_id' => $marketingProfile->id,
            'query' => $request->query->all(),
        ]);

        $context = $contextService->resolvePageContext($request);
        $this->logCustomerPostPreflight($request, $marketingProfile, 'candle_cash.adjust', $context);
        $this->requireValidCsrf($request, 'candle_cash.adjust', $marketingProfile, $context);
        $this->logCustomerAction($request, $marketingProfile, 'candle_cash.adjust', (bool) ($context['ok'] ?? false));
        if (! ($context['ok'] ?? false)) {
            Log::warning('Shopify embedded Candle Cash adjustment blocked', [
                'profile_id' => $marketingProfile->id,
                'route' => $request->route()?->getName(),
                'status' => $context['status'] ?? 'unknown',
            ]);
            return $this->redirectToCustomerDetail($request, $actionUrlGenerator, $marketingProfile)
                ->with('customer_detail_notice', [
                    'style' => 'warning',
                    'message' => 'Candle Cash adjustment failed: store context could not be verified.',
                ]);
        }

        $data = $request->validate([
            'direction' => ['required', 'in:add,subtract'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $direction = (string) $data['direction'];
        $amount = (int) $data['amount'];
        $reason = trim((string) $data['reason']);

        $result = $adjustmentService->adjust(
            profile: $marketingProfile,
            direction: $direction,
            amount: $amount,
            reason: $reason,
            actorId: (string) (auth()->id() ?? 'embedded')
        );

        $balance = (int) ($result['balance'] ?? 0);
        $noticeStyle = 'success';
        $noticeMessage = 'Candle Cash adjusted. New balance: '
            . app(\App\Services\Marketing\CandleCashService::class)->formatRewardCurrency(
                app(\App\Services\Marketing\CandleCashService::class)->amountFromPoints($balance)
            )
            . '.';

        Log::info('Shopify embedded Candle Cash adjustment applied', [
            'profile_id' => $marketingProfile->id,
            'direction' => $direction,
            'amount' => $amount,
            'reason' => $reason,
            'balance' => $balance,
            'transaction_id' => $result['transaction_id'] ?? null,
        ]);

        if ($direction === 'add' && $messagingService->smsSupported()) {
            $smsResult = $messagingService->sendCandleCashAdjustmentAwardedSms(
                $marketingProfile,
                $amount,
                auth()->id()
            );

            if (! $smsResult['ok']) {
                $noticeStyle = 'warning';
                $noticeMessage .= ' (Reward message not sent: ' . $smsResult['message'] . ')';

                Log::warning('Shopify embedded Candle Cash adjustment reward notification failed', [
                    'profile_id' => $marketingProfile->id,
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'failure_message' => $smsResult['message'] ?? 'unknown',
                ]);
            } else {
                $noticeMessage .= ' Reward message sent.';

                Log::info('Shopify embedded Candle Cash adjustment reward notification sent', [
                    'profile_id' => $marketingProfile->id,
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'amount' => $amount,
                ]);
            }
        }

        return $this->redirectToCustomerDetail($request, $actionUrlGenerator, $marketingProfile)
            ->with('customer_detail_notice', [
                'style' => $noticeStyle,
                'message' => $noticeMessage,
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

    public function sendMessage(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomerActionUrlGenerator $actionUrlGenerator,
        ShopifyEmbeddedCustomerMessagingService $messagingService,
        MarketingProfile $marketingProfile
    ): RedirectResponse {
        $context = $contextService->resolvePageContext($request);
        $this->logCustomerPostPreflight($request, $marketingProfile, 'message.send', $context);
        $this->requireValidCsrf($request, 'message.send', $marketingProfile, $context);
        $this->logCustomerAction($request, $marketingProfile, 'message.send', (bool) ($context['ok'] ?? false));
        if (! ($context['ok'] ?? false)) {
            Log::warning('Shopify embedded customer message blocked', [
                'profile_id' => $marketingProfile->id,
                'route' => $request->route()?->getName(),
                'status' => $context['status'] ?? 'unknown',
            ]);
            return $this->redirectToCustomerDetail($request, $actionUrlGenerator, $marketingProfile)
                ->with('customer_detail_notice', [
                    'style' => 'warning',
                    'message' => 'Message send failed: store context could not be verified.',
                ]);
        }

        $data = $request->validate([
            'channel' => ['required', 'in:sms'],
            'message' => ['required', 'string', 'max:1000'],
            'sender_key' => ['nullable', 'string', 'max:80'],
        ]);

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

        return $this->redirectToCustomerDetail($request, $actionUrlGenerator, $marketingProfile)
            ->with('customer_detail_notice', [
                'style' => $result['ok'] ? 'success' : 'warning',
                'message' => $result['message'],
            ]);
    }

    public function sendCandleCash(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomerActionUrlGenerator $actionUrlGenerator,
        ShopifyEmbeddedCustomerSendCandleCashService $sendService,
        ShopifyEmbeddedCustomerMessagingService $messagingService,
        MarketingProfile $marketingProfile
    ): RedirectResponse {
        $context = $contextService->resolvePageContext($request);
        $this->logCustomerPostPreflight($request, $marketingProfile, 'candle_cash.send', $context);
        $this->requireValidCsrf($request, 'candle_cash.send', $marketingProfile, $context);
        $this->logCustomerAction($request, $marketingProfile, 'candle_cash.send', (bool) ($context['ok'] ?? false));
        if (! ($context['ok'] ?? false)) {
            Log::warning('Shopify embedded Candle Cash send blocked', [
                'profile_id' => $marketingProfile->id,
                'route' => $request->route()?->getName(),
                'status' => $context['status'] ?? 'unknown',
            ]);
            return $this->redirectToCustomerDetail($request, $actionUrlGenerator, $marketingProfile)
                ->with('customer_detail_notice', [
                    'style' => 'warning',
                    'message' => 'Send Candle Cash failed: store context could not be verified.',
                ]);
        }

        $intentValues = implode(',', array_keys(self::giftIntentOptions()));
        $originValues = implode(',', array_keys(self::giftOriginOptions()));

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000'],
            'reason' => ['required', 'string', 'max:500'],
            'message' => ['nullable', 'string', 'max:1000'],
            'sender_key' => ['nullable', 'string', 'max:80'],
            'gift_intent' => ['nullable', 'string', 'in:' . $intentValues],
            'gift_origin' => ['nullable', 'string', 'in:' . $originValues],
            'campaign_key' => ['nullable', 'string', 'max:100'],
        ]);

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
            profile: $marketingProfile,
            amount: $amount,
            reason: $reason,
            actorId: (string) (auth()->id() ?? 'embedded'),
            metadata: $metadata
        );

        Log::info('Shopify embedded Candle Cash sent', [
            'profile_id' => $marketingProfile->id,
            'amount' => $amount,
            'reason' => $reason,
            'actor_id' => auth()->id(),
            'balance' => (int) ($result['balance'] ?? 0),
            'transaction_id' => $result['transaction_id'] ?? null,
            'metadata' => $metadata,
        ]);

        $noticeMessage = 'Candle Cash sent. New balance: ' . number_format((int) ($result['balance'] ?? 0));
        $noticeStyle = 'success';
        $transactionId = $result['transaction_id'] ?? null;

        if ($message !== '') {
            $smsResult = $messagingService->sendSms($marketingProfile, $message, auth()->id(), $senderKey);
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
                    'profile_id' => $marketingProfile->id,
                    'transaction_id' => $transactionId,
                    'failure_message' => $smsResult['message'] ?? 'unknown',
                ]);
            }
            else {
                Log::info('Shopify embedded Candle Cash notification sent', [
                    'profile_id' => $marketingProfile->id,
                    'transaction_id' => $transactionId,
                ]);
            }
        }

        return $this->redirectToCustomerDetail($request, $actionUrlGenerator, $marketingProfile)
            ->with('customer_detail_notice', [
                'style' => $noticeStyle,
                'message' => $noticeMessage,
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

    protected function logCustomerPostPreflight(
        Request $request,
        MarketingProfile $profile,
        string $action,
        array $context
    ): void {
        Log::info('shopify.embedded.customer_post.preflight', ShopifyEmbeddedCsrfDiagnostics::forRequest($request, [
            'action' => $action,
            'context_ok' => (bool) ($context['ok'] ?? false),
            'context_status' => $context['status'] ?? 'unknown',
            'context_mode' => (bool) ($context['ok'] ?? false) ? 'verified' : 'fallback',
            'profile_id' => $profile->id,
        ]));
    }

    protected function redirectToCustomerDetail(
        Request $request,
        ShopifyEmbeddedCustomerActionUrlGenerator $actionUrlGenerator,
        MarketingProfile $marketingProfile
    ): RedirectResponse {
        $target = $actionUrlGenerator->url('customers.detail', ['marketingProfile' => $marketingProfile->id], $request);

        Log::info('shopify.embedded.customer_detail.redirect', [
            'profile_id' => $marketingProfile->id,
            'target' => $target,
            'route' => $request->route()?->getName(),
        ]);

        return redirect()->to($target);
    }

    protected function requireValidCsrf(
        Request $request,
        string $action,
        MarketingProfile $profile,
        array $context
    ): void
    {
        $token = (string) $request->input('_token', '');
        $sessionToken = (string) $request->session()->token();

        if ($token === '' || ! hash_equals($sessionToken, $token)) {
            Log::warning('shopify.embedded.customer_post.csrf_mismatch', ShopifyEmbeddedCsrfDiagnostics::forRequest($request, [
                'action' => $action,
                'context_ok' => (bool) ($context['ok'] ?? false),
                'context_status' => $context['status'] ?? 'unknown',
                'context_mode' => (bool) ($context['ok'] ?? false) ? 'verified' : 'fallback',
                'profile_id' => $profile->id,
                'mismatch_source' => 'controller',
            ]));

            abort(419, 'CSRF token mismatch.');
        }
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
