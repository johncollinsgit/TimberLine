<?php

namespace App\Http\Controllers;

use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedCustomerCandleCashAdjustmentService;
use App\Services\Shopify\ShopifyEmbeddedCustomerDetailService;
use App\Services\Shopify\ShopifyEmbeddedCustomerMessagingService;
use App\Services\Shopify\ShopifyEmbeddedCustomerSendCandleCashService;
use App\Services\Shopify\ShopifyEmbeddedCustomersGridService;
use App\Services\Marketing\MarketingConsentService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

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
        MarketingProfile $marketingProfile
    ): Response {
        $displayName = trim((string) ($marketingProfile->first_name . ' ' . $marketingProfile->last_name));
        if ($displayName === '') {
            $displayName = $marketingProfile->email ?: ($marketingProfile->phone ?: 'Customer #' . $marketingProfile->id);
        }

        $detail = $detailService->build($marketingProfile);

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
            'pageActions' => [
                [
                    'label' => 'Back to Customers',
                    'href' => route('shopify.embedded.customers.manage', [], false),
                ],
                [
                    'label' => 'Open in Backstage',
                    'href' => route('marketing.customers.show', $marketingProfile),
                ],
            ],
            'giftIntentOptions' => self::giftIntentOptions(),
            'giftOriginOptions' => self::giftOriginOptions(),
        ]
    );
}

    public function update(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        MarketingIdentityNormalizer $identityNormalizer,
        MarketingProfile $marketingProfile
    ): RedirectResponse {
        $context = $contextService->resolvePageContext($request);
        if (! ($context['ok'] ?? false)) {
            return redirect()
                ->back()
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

        return redirect()
            ->back()
            ->with('customer_detail_notice', [
                'style' => 'success',
                'message' => 'Customer identity updated.',
            ]);
    }

    public function updateConsent(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        MarketingConsentService $consentService,
        MarketingProfile $marketingProfile
    ): RedirectResponse {
        $context = $contextService->resolvePageContext($request);
        if (! ($context['ok'] ?? false)) {
            return redirect()
                ->back()
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

        return redirect()
            ->back()
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
        ShopifyEmbeddedCustomerCandleCashAdjustmentService $adjustmentService,
        MarketingProfile $marketingProfile
    ): RedirectResponse {
        $context = $contextService->resolvePageContext($request);
        if (! ($context['ok'] ?? false)) {
            return redirect()
                ->back()
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

        return redirect()
            ->back()
            ->with('customer_detail_notice', [
                'style' => 'success',
                'message' => 'Candle Cash adjusted. New balance: ' . number_format($balance) . ' pts.',
            ]);
    }

    public function sendMessage(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomerMessagingService $messagingService,
        MarketingProfile $marketingProfile
    ): RedirectResponse {
        $context = $contextService->resolvePageContext($request);
        if (! ($context['ok'] ?? false)) {
            return redirect()
                ->back()
                ->with('customer_detail_notice', [
                    'style' => 'warning',
                    'message' => 'Message send failed: store context could not be verified.',
                ]);
        }

        $data = $request->validate([
            'channel' => ['required', 'in:sms'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $channel = (string) $data['channel'];
        $message = trim((string) $data['message']);

        $result = match ($channel) {
            'sms' => $messagingService->sendSms($marketingProfile, $message, auth()->id()),
            default => [
                'ok' => false,
                'message' => 'Message channel is not supported.',
            ],
        };

        return redirect()
            ->back()
            ->with('customer_detail_notice', [
                'style' => $result['ok'] ? 'success' : 'warning',
                'message' => $result['message'],
            ]);
    }

    public function sendCandleCash(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedCustomerSendCandleCashService $sendService,
        ShopifyEmbeddedCustomerMessagingService $messagingService,
        MarketingProfile $marketingProfile
    ): RedirectResponse {
        $context = $contextService->resolvePageContext($request);
        if (! ($context['ok'] ?? false)) {
            return redirect()
                ->back()
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
            'gift_intent' => ['nullable', 'string', 'in:' . $intentValues],
            'gift_origin' => ['nullable', 'string', 'in:' . $originValues],
            'campaign_key' => ['nullable', 'string', 'max:100'],
        ]);

        $amount = (int) $data['amount'];
        $reason = trim((string) $data['reason']);
        $message = trim((string) ($data['message'] ?? ''));
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

        $noticeMessage = 'Candle Cash sent. New balance: ' . number_format((int) ($result['balance'] ?? 0));
        $noticeStyle = 'success';
        $transactionId = $result['transaction_id'] ?? null;

        if ($message !== '') {
            $smsResult = $messagingService->sendSms($marketingProfile, $message, auth()->id());
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
            }
        }

        return redirect()
            ->back()
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
        array $extraViewData
    ): Response {
        $context = $contextService->resolvePageContext($request);

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

    /**
     * @return array<int,array{key:string,label:string,href:string,active:bool}>
     */
    protected function customerSubnav(string $activeKey): array
    {
        $items = [
            ['key' => 'manage', 'label' => 'Manage customers', 'href' => route('shopify.embedded.customers.manage', [], false)],
            ['key' => 'activity', 'label' => 'Activity', 'href' => route('shopify.embedded.customers.activity', [], false)],
            ['key' => 'questions', 'label' => 'Questions', 'href' => route('shopify.embedded.customers.questions', [], false)],
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
