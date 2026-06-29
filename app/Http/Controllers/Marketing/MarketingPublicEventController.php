<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionShopifyCustomerForMarketingProfile;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingProfileScentQuizResult;
use App\Models\MarketingReviewSummary;
use App\Models\MessagingConversation;
use App\Models\MessagingConversationMessage;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Marketing\CandleCashAccessGate;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CandleCashShopifyDiscountService;
use App\Services\Marketing\CandleCashTaskService;
use App\Services\Marketing\GrowaveProjectionService;
use App\Services\Marketing\MarketingConsentIncentiveService;
use App\Services\Marketing\MarketingConsentService;
use App\Services\Marketing\MarketingProfileSyncService;
use App\Services\Marketing\MarketingStorefrontEventLogger;
use App\Services\Marketing\MarketingStorefrontIdentityService;
use App\Services\Marketing\MessagingContactChannelStateService;
use App\Services\Marketing\MessagingConversationService;
use App\Services\Marketing\ModernForestryScentQuizAnalyticsService;
use App\Services\Marketing\ModernForestrySocialShareRewardService;
use App\Services\Mobile\ModernForestryMobileProductCatalogService;
use App\Services\Mobile\ModernForestryMobileScentQuizService;
use App\Services\Shopify\ShopifyAppContentService;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantResolver;
use App\Support\Marketing\MarketingEventContextResolver;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MarketingPublicEventController extends Controller
{
    public function __construct(
        protected MarketingEventContextResolver $eventContextResolver,
        protected MarketingIdentityNormalizer $normalizer,
        protected MarketingStorefrontIdentityService $storefrontIdentityService,
        protected MarketingStorefrontEventLogger $eventLogger,
        protected GrowaveProjectionService $growaveProjectionService,
        protected CandleCashAccessGate $candleCashAccessGate,
        protected TenantResolver $tenantResolver,
        protected TenantDisplayLabelResolver $displayLabelResolver,
        protected MessagingConversationService $conversationService,
        protected MessagingContactChannelStateService $channelStateService
    ) {}

    public function showOptin(string $eventSlug, Request $request): View|RedirectResponse
    {
        $eventContext = $this->eventContextResolver->resolve($eventSlug);
        if ($eventContext && (string) $eventContext['slug'] !== Str::slug($eventSlug)) {
            return redirect()->route('marketing.public.events.optin', ['eventSlug' => $eventContext['slug']]);
        }
        $tenantContext = $this->resolveTenantContext($request, $this->tenantResolver);
        $displayLabels = $this->displayLabelsForTenantId($tenantContext['tenant_id']);

        $this->eventLogger->log('public_event_optin_view', [
            'status' => 'ok',
            'source_surface' => 'public_event',
            'endpoint' => '/events/'.($eventContext['slug'] ?? Str::slug($eventSlug)).'/optin',
            'event_instance_id' => (int) ($eventContext['id'] ?? 0) ?: null,
            'source_type' => 'event_public_optin',
            'source_id' => (string) ($eventContext['slug'] ?? Str::slug($eventSlug)),
            'resolution_status' => 'resolved',
        ]);

        return view('marketing/public/event-optin', [
            'eventContext' => $eventContext,
            'eventSlug' => $eventContext['slug'] ?? $eventSlug,
            'displayLabels' => $displayLabels,
        ]);
    }

    public function storeOptin(
        string $eventSlug,
        Request $request,
        MarketingProfileSyncService $profileSyncService,
        MarketingConsentService $consentService,
        MarketingConsentIncentiveService $incentiveService,
        CandleCashTaskService $taskService
    ): RedirectResponse {
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'consent_sms' => ['nullable', 'boolean'],
            'consent_email' => ['nullable', 'boolean'],
            'award_bonus' => ['nullable', 'boolean'],
        ]);

        $eventContext = $this->eventContextResolver->resolve($eventSlug);
        $tenantContext = $this->resolveTenantContext($request, $this->tenantResolver);
        $canonicalSlug = (string) ($eventContext['slug'] ?? Str::slug($eventSlug));
        $sourceId = $this->storefrontIdentityService->deterministicSourceId(
            prefix: 'event_public_optin:'.$canonicalSlug,
            email: (string) ($data['email'] ?? ''),
            phone: (string) ($data['phone'] ?? '')
        );

        $sync = $profileSyncService->syncExternalIdentity([
            'first_name' => trim((string) ($data['first_name'] ?? '')) ?: null,
            'last_name' => trim((string) ($data['last_name'] ?? '')) ?: null,
            'raw_email' => trim((string) ($data['email'] ?? '')) ?: null,
            'raw_phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'source_channels' => ['event', 'event_optin', 'event_public'],
            'source_links' => [[
                'source_type' => 'event_public_optin',
                'source_id' => $sourceId,
                'source_meta' => [
                    'event_slug' => $canonicalSlug,
                    'event_context' => $eventContext,
                    'shopify_store_key' => $tenantContext['store_key'],
                    'tenant_id' => $tenantContext['tenant_id'],
                ],
            ]],
            'primary_source' => [
                'source_type' => 'event_public_optin',
                'source_id' => $sourceId,
            ],
        ], [
            'review_context' => [
                'source_label' => 'event_public_optin',
                'source_id' => $sourceId,
                'event_slug' => $canonicalSlug,
                'tenant_id' => $tenantContext['tenant_id'],
            ],
            'tenant_id' => $tenantContext['tenant_id'],
        ]);

        $profile = null;
        if ((int) ($sync['profile_id'] ?? 0) > 0) {
            $profile = MarketingProfile::query()->find((int) $sync['profile_id']);
        }

        if (! $profile) {
            $this->eventLogger->log('public_event_optin_submit', [
                'status' => 'verification_required',
                'issue_type' => 'identity_review_required',
                'source_surface' => 'public_event',
                'endpoint' => '/events/'.$canonicalSlug.'/optin',
                'event_instance_id' => (int) ($eventContext['id'] ?? 0) ?: null,
                'source_type' => 'event_public_optin',
                'source_id' => $sourceId,
                'meta' => [
                    'email' => (string) ($data['email'] ?? ''),
                    'phone' => (string) ($data['phone'] ?? ''),
                ],
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->withErrors([
                    'identity' => 'This opt-in could not be auto-linked safely and was routed to identity review.',
                ]);
        }

        $this->queueShopifyCustomerProvisioning(
            profile: $profile,
            storeKey: $tenantContext['store_key'] ?? null,
            tenantId: $tenantContext['tenant_id'] ?? $profile->tenant_id,
            trigger: 'marketing_public_event_optin'
        );

        if ((bool) ($data['consent_email'] ?? false)) {
            $consentService->setEmailConsent($profile, true, [
                'source_type' => 'event_public_optin',
                'source_id' => $sourceId,
                'tenant_id' => $tenantContext['tenant_id'] ?: $profile->tenant_id,
                'details' => ['event_slug' => $canonicalSlug, 'flow' => 'direct_confirmed'],
            ]);

            $taskService->awardSystemTask($profile, 'email-signup', [
                'source_type' => 'event_public_optin',
                'source_id' => $sourceId.':email',
                'metadata' => [
                    'event_slug' => $canonicalSlug,
                ],
            ]);
        }

        $smsConsented = false;
        if ((bool) ($data['consent_sms'] ?? false)) {
            $smsConsented = $consentService->setSmsConsent($profile, true, [
                'source_type' => 'event_public_optin',
                'source_id' => $sourceId,
                'tenant_id' => $tenantContext['tenant_id'] ?: $profile->tenant_id,
                'details' => ['event_slug' => $canonicalSlug, 'flow' => 'direct_confirmed'],
            ]);
        }

        $bonus = 0;
        if ($smsConsented) {
            $awarded = $incentiveService->awardSmsConsentBonusOnce(
                profile: $profile,
                sourceId: $sourceId,
                description: 'Event opt-in SMS consent bonus'
            );
            if ($awarded['awarded']) {
                $bonus = (int) ($awarded['candle_cash'] ?? 0);
            }
        }

        $this->eventLogger->log('public_event_optin_submit', [
            'status' => 'ok',
            'source_surface' => 'public_event',
            'endpoint' => '/events/'.$canonicalSlug.'/optin',
            'profile' => $profile,
            'event_instance_id' => (int) ($eventContext['id'] ?? 0) ?: null,
            'source_type' => 'event_public_optin',
            'source_id' => $sourceId,
            'meta' => [
                'consent_sms' => $smsConsented,
                'consent_email' => (bool) ($data['consent_email'] ?? false),
                'bonus_awarded' => $bonus,
            ],
            'resolution_status' => 'resolved',
        ]);

        return redirect()
            ->route('marketing.public.consent-confirm', [
                'event' => $canonicalSlug,
                'profile' => $profile->id,
                'bonus' => $bonus,
            ])
            ->with('status', 'Thanks! Your event opt-in was recorded.');
    }

    public function showEventRewards(string $eventSlug, Request $request, CandleCashService $candleCashService): View|RedirectResponse
    {
        $eventContext = $this->eventContextResolver->resolve($eventSlug);
        if ($eventContext && (string) $eventContext['slug'] !== Str::slug($eventSlug)) {
            $storeKey = trim((string) $request->query('store_key', ''));

            return redirect()->route('marketing.public.events.rewards', [
                'eventSlug' => $eventContext['slug'],
                'email' => (string) $request->query('email', ''),
                'phone' => (string) $request->query('phone', ''),
                'store_key' => $storeKey !== '' ? $storeKey : null,
            ]);
        }
        $tenantContext = $this->resolveTenantContext($request, $this->tenantResolver);
        $displayLabels = $this->displayLabelsForTenantId($tenantContext['tenant_id']);
        $rewardsLabel = trim((string) ($displayLabels['rewards_label'] ?? $displayLabels['rewards'] ?? 'Rewards'));
        if ($rewardsLabel === '') {
            $rewardsLabel = 'Rewards';
        }
        $rewardCreditLabel = trim((string) ($displayLabels['reward_credit_label'] ?? 'reward credit'));
        if ($rewardCreditLabel === '') {
            $rewardCreditLabel = 'reward credit';
        }
        $resolution = $this->resolveProfileFromRequest($request, 'event_reward_lookup:'.($eventContext['slug'] ?? Str::slug($eventSlug)));
        $profile = $resolution['profile'];
        $lookupState = $resolution['state'];
        $tenantId = $this->resolvedTenantId($profile, $tenantContext);

        $this->eventLogger->log('public_reward_lookup', [
            'status' => $profile ? 'ok' : ($lookupState === 'verification_required' ? 'verification_required' : 'pending'),
            'issue_type' => $profile ? null : $lookupState,
            'source_surface' => 'public_event',
            'endpoint' => '/events/'.($eventContext['slug'] ?? Str::slug($eventSlug)).'/rewards',
            'profile' => $profile,
            'event_instance_id' => (int) ($eventContext['id'] ?? 0) ?: null,
            'source_type' => 'event_reward_lookup',
            'source_id' => (string) ($eventContext['slug'] ?? Str::slug($eventSlug)),
            'meta' => [
                'email' => (string) $request->query('email', ''),
                'phone' => (string) $request->query('phone', ''),
                'lookup_state' => $lookupState,
            ],
            'resolution_status' => $profile ? 'resolved' : 'open',
        ]);

        return view('marketing/public/event-rewards', [
            'eventContext' => $eventContext,
            'eventSlug' => $eventContext['slug'] ?? $eventSlug,
            'profile' => $profile,
            'lookupState' => $lookupState,
            'balance' => $profile ? $candleCashService->balancePayloadFromPoints($candleCashService->currentBalance($profile)) : null,
            'availableRewards' => $tenantId !== null
                ? array_values(array_filter([
                    $candleCashService->storefrontRewardPayload(
                        $candleCashService->storefrontReward($tenantId),
                        $profile ? $candleCashService->currentBalance($profile) : null,
                        $tenantId
                    ),
                ]))
                : [],
            'redemptions' => $profile
                ? $profile->candleCashRedemptions()->with('reward:id,name,reward_type,reward_value')->orderByDesc('id')->limit(10)->get()
                    ->map(fn ($row): array => [
                        'id' => (int) $row->id,
                        'name' => $row->reward && $candleCashService->isStorefrontReward($row->reward, $tenantId)
                            ? 'Redeem '.$candleCashService->fixedRedemptionFormatted($tenantId).' '.Str::title($rewardCreditLabel)
                            : (string) ($row->reward?->name ?: $rewardsLabel),
                        'status' => (string) ($row->status ?: 'issued'),
                        'candle_cash_amount' => $candleCashService->amountFromPoints($row->candle_cash_spent),
                        'candle_cash_amount_formatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints($row->candle_cash_spent)),
                        'redeemed_at' => optional($row->redeemed_at)->toDateTimeString(),
                        'redemption_code' => $row->redemption_code ? (string) $row->redemption_code : null,
                    ])->all()
                : [],
            'redemptionRules' => $tenantId !== null ? $candleCashService->redemptionRulesPayload($tenantId) : [],
            'displayLabels' => $displayLabels,
        ]);
    }

    public function rewardsLookup(Request $request, CandleCashService $candleCashService): View
    {
        $resolution = $this->resolveProfileFromRequest($request, 'reward_lookup');
        $profile = $resolution['profile'];
        $lookupState = $resolution['state'];
        $tenantContext = $this->resolveTenantContext($request, $this->tenantResolver);
        $tenantId = is_numeric($profile?->tenant_id) && (int) ($profile?->tenant_id ?? 0) > 0
            ? (int) $profile->tenant_id
            : $tenantContext['tenant_id'];
        $displayLabels = $this->publicRewardsLookupDisplayLabels($tenantId);

        [
            $balance,
            $availableRewards,
            $redemptions,
            $transactions,
            $latestGrowaveExternal,
            $reviewSummary,
            $nativeReviewSummary,
            $legacyReviewSummary,
            $reviewRewardStatus,
            $nativeReviewRewardStatus,
            $legacyReviewRewardStatus,
            $lastGrowaveSyncAt,
            $reviewDataSource,
        ] =
            $this->rewardsLookupData($profile, $candleCashService, $displayLabels, $tenantId);
        $redemptionAccess = $this->candleCashAccessGate->storefrontRedeemAccessPayload($profile);

        $this->eventLogger->log('public_reward_lookup', [
            'status' => $profile ? 'ok' : ($lookupState === 'verification_required' ? 'verification_required' : 'pending'),
            'issue_type' => $profile ? null : $lookupState,
            'source_surface' => 'public_event',
            'endpoint' => '/rewards/lookup',
            'profile' => $profile,
            'source_type' => 'reward_lookup',
            'source_id' => 'public_lookup',
            'meta' => [
                'email' => (string) $request->query('email', ''),
                'phone' => (string) $request->query('phone', ''),
                'lookup_state' => $lookupState,
            ],
            'resolution_status' => $profile ? 'resolved' : 'open',
        ]);

        return view('marketing/public/rewards-lookup', [
            'profile' => $profile,
            'lookupState' => $lookupState,
            'balance' => $balance,
            'availableRewards' => $availableRewards,
            'redemptions' => $redemptions,
            'transactions' => $transactions,
            'latestGrowaveExternal' => $latestGrowaveExternal,
            'reviewSummary' => $reviewSummary,
            'nativeReviewSummary' => $nativeReviewSummary,
            'legacyReviewSummary' => $legacyReviewSummary,
            'reviewRewardStatus' => $reviewRewardStatus,
            'nativeReviewRewardStatus' => $nativeReviewRewardStatus,
            'legacyReviewRewardStatus' => $legacyReviewRewardStatus,
            'lastGrowaveSyncAt' => $lastGrowaveSyncAt,
            'reviewDataSource' => $reviewDataSource,
            'redeemResult' => session('redeem_result'),
            'redemptionRules' => $tenantId !== null ? $candleCashService->redemptionRulesPayload($tenantId) : [],
            'redemptionAccess' => $redemptionAccess,
            'displayLabels' => $displayLabels,
        ]);
    }

    public function redeemRewardsLookup(
        Request $request,
        CandleCashService $candleCashService,
        CandleCashShopifyDiscountService $discountSyncService
    ): RedirectResponse {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'reward_id' => ['required', 'integer', 'exists:candle_cash_rewards,id'],
        ]);

        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $tenantContext = $this->resolveTenantContext($request, $this->tenantResolver);
        $query = ['email' => $email, 'phone' => $phone];
        if (($tenantContext['store_key'] ?? null) !== null) {
            $query['store_key'] = $tenantContext['store_key'];
        }

        $resolution = $this->resolveProfileFromRequest($request, 'reward_lookup_redeem', true);
        $profile = $resolution['profile'];
        $lookupState = $resolution['state'];
        $tenantId = $this->resolvedTenantId($profile, $tenantContext);

        if (! $profile) {
            $this->eventLogger->log('public_reward_redeem', [
                'status' => 'error',
                'issue_type' => 'identity_'.$lookupState,
                'source_surface' => 'public_event',
                'endpoint' => '/rewards/lookup/redeem',
                'source_type' => 'reward_lookup_redeem',
                'source_id' => 'public_lookup',
                'meta' => [
                    'lookup_state' => $lookupState,
                ],
                'resolution_status' => 'open',
            ]);

            return redirect()
                ->route('marketing.public.rewards-lookup', $query)
                ->with('redeem_result', [
                    'ok' => false,
                    'state' => $lookupState,
                    'message' => 'Could not verify your customer profile. Confirm your email and phone and try again.',
                ]);
        }

        /** @var CandleCashReward $requestedReward */
        $requestedReward = CandleCashReward::query()->findOrFail((int) $data['reward_id']);
        $reward = $tenantId !== null ? $candleCashService->storefrontReward($tenantId) : null;
        if (! $reward || (int) $requestedReward->id !== (int) $reward->id) {
            return redirect()
                ->route('marketing.public.rewards-lookup', $query)
                ->with('redeem_result', [
                    'ok' => false,
                    'state' => 'reward_unavailable',
                    'message' => 'That reward redemption is not available right now.',
                ]);
        }

        $redemptionAccess = $this->candleCashAccessGate->storefrontRedeemAccessPayload($profile);
        if (! (bool) ($redemptionAccess['redeem_enabled'] ?? false)) {
            $this->eventLogger->log('public_reward_redeem', [
                'status' => 'error',
                'issue_type' => 'redemption_access_denied',
                'source_surface' => 'public_event',
                'endpoint' => '/rewards/lookup/redeem',
                'profile' => $profile,
                'source_type' => 'reward_lookup_redeem',
                'source_id' => 'public_lookup',
                'resolution_status' => 'resolved',
            ]);

            return redirect()
                ->route('marketing.public.rewards-lookup', $query)
                ->with('redeem_result', [
                    'ok' => false,
                    'state' => 'redemption_unavailable',
                    'message' => (string) ($redemptionAccess['message'] ?? 'Reward redemption is not available right now.'),
                    'balance' => $candleCashService->balancePayloadFromPoints($candleCashService->currentBalance($profile)),
                    'cta_label' => $redemptionAccess['cta_label'] ?? 'Check reward status',
                ]);
        }

        $result = $candleCashService->requestStorefrontRedemption(
            profile: $profile,
            reward: $reward,
            platform: 'public_lookup',
            reuseActiveCode: true,
            tenantId: $tenantId
        );

        $state = strtolower(trim((string) ($result['state'] ?? 'try_again_later')));
        $ok = (bool) ($result['ok'] ?? false);
        $message = $this->redemptionMessageForState($state);
        $eventStatus = $ok ? 'ok' : 'error';
        $eventIssue = $ok ? null : (string) ($result['error'] ?? $state);
        $discountSyncStatus = $ok ? 'pending' : 'not_attempted';
        $applyUrl = null;

        if ($ok && (int) ($result['redemption_id'] ?? 0) > 0) {
            $redemption = CandleCashRedemption::query()->find((int) $result['redemption_id']);

            if ($redemption) {
                $storeContext = $this->preferredStoreContextForProfile($profile);
                $redemption = $this->syncRedemptionStoreContext($redemption, $storeContext);

                try {
                    $sync = $discountSyncService->ensureDiscountForRedemption(
                        $redemption,
                        $this->normalizeStoreKey($storeContext['store_key'] ?? null)
                    );
                    $discountSyncStatus = 'synced';
                    $applyUrl = $this->candleCashApplyUrlForStore(
                        $this->normalizeStoreKey($sync['store_key'] ?? ($storeContext['store_key'] ?? null)),
                        (string) ($result['code'] ?? '')
                    );
                } catch (\Throwable $e) {
                    $discountSyncStatus = 'sync_failed';
                    $restore = $candleCashService->cancelIssuedRedemptionAndRestoreBalance(
                        $redemption,
                        'Canceled automatically because Shopify could not prepare the reward discount yet.'
                    );
                    $result['balance'] = round((float) ($restore['balance'] ?? $candleCashService->currentBalance($profile)), 3);
                    $ok = false;
                    $state = 'discount_not_ready';
                    $message = 'Your reward balance is safe. We could not prepare the Shopify discount yet.';
                    $eventStatus = 'error';
                    $eventIssue = 'shopify_discount_sync_failed';
                }
            }
        }

        $displayLabels = $this->displayLabelsForTenantId($tenantId);
        $rewardCreditLabel = trim((string) ($displayLabels['reward_credit_label'] ?? 'reward credit'));
        if ($rewardCreditLabel === '') {
            $rewardCreditLabel = 'reward credit';
        }

        $this->eventLogger->log('public_reward_redeem', [
            'status' => $eventStatus,
            'issue_type' => $eventIssue,
            'source_surface' => 'public_event',
            'endpoint' => '/rewards/lookup/redeem',
            'profile' => $profile,
            'source_type' => 'reward_lookup_redeem',
            'source_id' => (string) $reward->id,
            'meta' => [
                'state' => $state,
                'reward_id' => (int) $reward->id,
                'balance' => round((float) ($result['balance'] ?? 0), 3),
                'discount_sync_status' => $discountSyncStatus,
            ],
            'resolution_status' => $ok ? 'resolved' : 'open',
        ]);

        return redirect()
            ->route('marketing.public.rewards-lookup', $query)
            ->with('redeem_result', [
                'ok' => $ok,
                'state' => $state,
                'message' => $message,
                'balance' => $candleCashService->balancePayloadFromPoints($result['balance'] ?? 0),
                'reward_name' => 'Redeem '.$candleCashService->fixedRedemptionFormatted($tenantId).' '.Str::title($rewardCreditLabel),
                'redemption_code' => $ok ? (string) ($result['code'] ?? '') : null,
                'redemption_id' => $ok ? (int) ($result['redemption_id'] ?? 0) : null,
                'discount_sync_status' => $discountSyncStatus,
                'apply_url' => $ok ? $applyUrl : null,
            ]);
    }

    public function showConsentConfirm(Request $request, CandleCashService $candleCashService): View
    {
        $eventSlug = trim((string) $request->query('event', ''));
        $profileId = (int) $request->query('profile', 0);
        $eventContext = $eventSlug !== '' ? $this->eventContextResolver->resolve($eventSlug) : null;
        $bonusPoints = max(0, (int) $request->query('bonus', 0));
        $profile = $profileId > 0 ? MarketingProfile::query()->find($profileId) : null;
        $tenantContext = $this->resolveTenantContext($request, $this->tenantResolver);
        $tenantId = is_numeric($profile?->tenant_id) && (int) ($profile?->tenant_id ?? 0) > 0
            ? (int) $profile->tenant_id
            : $tenantContext['tenant_id'];
        $displayLabels = $this->displayLabelsForTenantId($tenantId);

        $this->eventLogger->log('public_consent_confirm_view', [
            'status' => 'ok',
            'source_surface' => 'public_event',
            'endpoint' => '/marketing/consent/confirm',
            'event_instance_id' => (int) ($eventContext['id'] ?? 0) ?: null,
            'marketing_profile_id' => $profileId > 0 ? $profileId : null,
            'source_type' => 'public_consent_confirm',
            'source_id' => $eventSlug !== '' ? $eventSlug : 'generic',
            'resolution_status' => 'resolved',
        ]);

        return view('marketing/public/consent-confirm', [
            'eventContext' => $eventContext,
            'eventSlug' => $eventContext['slug'] ?? $eventSlug,
            'profile' => $profile,
            'bonus' => $bonusPoints,
            'bonusAmount' => $candleCashService->amountFromPoints($bonusPoints),
            'bonusFormatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints($bonusPoints)),
            'displayLabels' => $displayLabels,
        ]);
    }

    public function customerDashboard(
        Request $request,
        CandleCashService $candleCashService,
        ShopifyAppContentService $appContentService,
        ModernForestryMobileScentQuizService $scentQuizService,
        ModernForestryScentQuizAnalyticsService $scentQuizAnalytics
    ): View|RedirectResponse {
        $tenantContext = $this->resolveTenantContext($request, $this->tenantResolver);
        if (! is_numeric($tenantContext['tenant_id'] ?? null) || (int) ($tenantContext['tenant_id'] ?? 0) !== 1) {
            abort(404);
        }

        $resolution = $this->resolveCustomerDashboardProfileFromRequest($request);
        $profile = $resolution['profile'];
        $lookupState = $resolution['state'];
        $tenantId = $this->resolvedTenantId($profile, $tenantContext) ?? 1;
        $displayLabels = $this->displayLabelsForTenantId($tenantId);
        $content = $appContentService->forTenant(1);

        [
            $balance,
            $availableRewards,
            $redemptions,
            $transactions,
            $latestGrowaveExternal,
            $reviewSummary,
            $nativeReviewSummary,
            $legacyReviewSummary,
            $reviewRewardStatus,
            $nativeReviewRewardStatus,
            $legacyReviewRewardStatus,
            $lastGrowaveSyncAt,
            $reviewDataSource,
        ] = $this->rewardsLookupData($profile, $candleCashService, $displayLabels, $tenantId);

        $orders = $profile ? $this->customerDashboardOrders($profile, $tenantId) : collect();
        $profileStoreContext = $profile
            ? $this->preferredStoreContextForProfile($profile)
            : ['store_key' => $tenantContext['store_key'] ?? null, 'tenant_id' => $tenantContext['tenant_id'] ?? null];
        $messages = $profile
            ? $this->customerDashboardMessages($profile, $tenantId, $profileStoreContext['store_key'] ?? null)
            : null;
        $scentQuiz = $profile ? $scentQuizService->definition($profile) : null;

        if ($profile && $request->boolean('scent_quiz')) {
            $this->eventLogger->log('public_customer_scent_quiz_view', [
                'status' => 'ok',
                'profile' => $profile,
                'source_surface' => 'shopify_app_proxy',
                'endpoint' => '/shopify/marketing/account',
                'source_type' => 'shopify_customer_dashboard_scent_quiz',
                'source_id' => 'profile:'.$profile->id,
                'meta' => [
                    'store_key' => $profileStoreContext['store_key'] ?? null,
                    'tenant_id' => $tenantId,
                ],
                'resolution_status' => 'resolved',
            ]);
        }

        $this->eventLogger->log('public_customer_dashboard_view', [
            'status' => $profile ? 'ok' : $lookupState,
            'issue_type' => $profile ? null : $lookupState,
            'source_surface' => 'shopify_app_proxy',
            'endpoint' => '/shopify/marketing/account',
            'profile' => $profile,
            'source_type' => 'shopify_customer_dashboard',
            'source_id' => $profile ? 'profile:'.$profile->id : null,
            'meta' => [
                'lookup_state' => $lookupState,
                'order_count' => $orders->count(),
            ],
            'resolution_status' => $profile ? 'resolved' : 'open',
        ]);

        return view('marketing/public/customer-dashboard', [
            'content' => $content,
            'contentPublished' => is_array($content['published'] ?? null) ? $content['published'] : null,
            'contentDefaults' => is_array($content['defaults'] ?? null) ? $content['defaults'] : [],
            'profile' => $profile,
            'lookupState' => $lookupState,
            'balance' => $balance,
            'availableRewards' => $availableRewards,
            'redemptions' => $redemptions,
            'transactions' => $transactions,
            'latestGrowaveExternal' => $latestGrowaveExternal,
            'reviewSummary' => $reviewSummary,
            'nativeReviewSummary' => $nativeReviewSummary,
            'legacyReviewSummary' => $legacyReviewSummary,
            'reviewRewardStatus' => $reviewRewardStatus,
            'nativeReviewRewardStatus' => $nativeReviewRewardStatus,
            'legacyReviewRewardStatus' => $legacyReviewRewardStatus,
            'lastGrowaveSyncAt' => $lastGrowaveSyncAt,
            'reviewDataSource' => $reviewDataSource,
            'orders' => $orders,
            'messages' => $messages,
            'displayLabels' => $displayLabels,
            'redemptionAccess' => $this->candleCashAccessGate->storefrontRedeemAccessPayload($profile),
            'profileStoreContext' => $profileStoreContext,
            'supportLink' => $this->supportLinkForDashboard($content),
            'rewardsLabel' => trim((string) ($displayLabels['rewards_label'] ?? $displayLabels['rewards'] ?? 'Rewards')) ?: 'Rewards',
            'messageActionUrl' => $this->customerDashboardMessageActionUrl($request),
            'messageNotice' => $request->attributes->get('message_notice'),
            'scentQuiz' => $scentQuiz,
            'scentQuizActionUrl' => $this->customerDashboardScentQuizActionUrl($request),
            'scentQuizNotice' => $request->attributes->get('scent_quiz_notice'),
            'socialShareConfig' => $profile ? app(ModernForestrySocialShareRewardService::class)->config($profile) : null,
            'socialShareStartedUrl' => $this->customerDashboardSocialShareStartedUrl($request),
            'socialShareClaimUrl' => $this->customerDashboardSocialShareClaimUrl($request),
            'socialShareNotice' => $request->attributes->get('social_share_notice'),
            'scentQuizAttributionPayload' => $request->attributes->get('scent_quiz_attribution_payload')
                ?? ($request->attributes->get('scent_quiz_completed') && $profile
                    ? $scentQuizAnalytics->attributionPayload($profile, 'Scent quiz complete')
                    : null),
        ]);
    }

    public function sendCustomerMessage(
        Request $request,
        CandleCashService $candleCashService,
        ShopifyAppContentService $appContentService,
        ModernForestryMobileScentQuizService $scentQuizService,
        ModernForestryScentQuizAnalyticsService $scentQuizAnalytics
    ): View {
        $resolution = $this->resolveCustomerDashboardProfileFromRequest($request);
        $profile = $resolution['profile'];
        $lookupState = (string) ($resolution['state'] ?? 'customer_login_required');
        if (! $profile instanceof MarketingProfile) {
            return $this->customerDashboard($request, $candleCashService, $appContentService, $scentQuizService, $scentQuizAnalytics);
        }

        $tenantId = $this->resolvedTenantId($profile, $this->resolveTenantContext($request, $this->tenantResolver)) ?? 1;
        $profileStoreContext = $this->preferredStoreContextForProfile($profile);
        $messages = $this->customerDashboardMessages($profile, $tenantId, $profileStoreContext['store_key'] ?? null);
        $smsStatus = strtolower(trim((string) ($messages['sms_status'] ?? 'unknown')));
        $canCompose = (bool) ($messages['can_compose'] ?? false);
        $body = trim((string) $request->input('message_body', ''));

        if ($body === '') {
            $request->attributes->set('message_notice', 'Please write a message before sending.');

            return $this->customerDashboard($request, $candleCashService, $appContentService, $scentQuizService, $scentQuizAnalytics);
        }

        if (! $canCompose || in_array($smsStatus, ['unsubscribed', 'suppressed'], true)) {
            $request->attributes->set(
                'message_notice',
                (string) ($messages['support_prompt'] ?? 'Messages are not available for this account right now. Use support instead.')
            );

            return $this->customerDashboard($request, $candleCashService, $appContentService, $scentQuizService, $scentQuizAnalytics);
        }

        $conversation = $this->conversationService->findOrCreateSmsConversation(
            tenantId: $tenantId,
            storeKey: $profileStoreContext['store_key'] ?? null,
            profile: $profile,
            phone: (string) ($profile->phone ?? $profile->normalized_phone ?? ''),
            context: [
                'source_type' => 'shopify_account_message',
                'source_context' => [
                    'surface' => 'shopify_account',
                    'source' => 'customer_dashboard',
                ],
            ]
        );

        $conversation->forceFill([
            'status' => 'open',
        ])->save();

        $this->conversationService->appendMessage($conversation, [
            'marketing_profile_id' => $profile->id,
            'channel' => 'sms',
            'direction' => 'inbound',
            'provider' => 'shopify',
            'body' => $body,
            'normalized_body' => $body,
            'from_identity' => $profile->normalized_phone ?: $profile->phone,
            'received_at' => now(),
            'message_type' => 'normal',
            'raw_payload' => [
                'source' => 'customer_dashboard',
                'surface' => 'shopify_account',
            ],
            'metadata' => [
                'source_label' => 'customer_dashboard_message',
            ],
        ]);

        $request->attributes->set('message_notice', 'Message sent. We will continue the conversation here and in the Shopify inbox.');

        return $this->customerDashboard($request, $candleCashService, $appContentService, $scentQuizService, $scentQuizAnalytics);
    }

    public function saveCustomerScentQuizResult(
        Request $request,
        CandleCashService $candleCashService,
        ShopifyAppContentService $appContentService,
        ModernForestryMobileScentQuizService $scentQuizService,
        ModernForestryScentQuizAnalyticsService $scentQuizAnalytics
    ): View {
        $resolution = $this->resolveCustomerDashboardProfileFromRequest($request);
        $profile = $resolution['profile'];
        if (! $profile instanceof MarketingProfile) {
            $request->attributes->set('scent_quiz_notice', 'Sign in to your account before saving a scent quiz result.');

            return $this->customerDashboard($request, $candleCashService, $appContentService, $scentQuizService, $scentQuizAnalytics);
        }

        $validated = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'string', 'max:80'],
            'answers.*.option_id' => ['required', 'string', 'max:80'],
        ]);

        try {
            $result = $scentQuizService->saveResult($profile, (array) $validated['answers']);
        } catch (\InvalidArgumentException $exception) {
            $request->attributes->set('scent_quiz_notice', $exception->getMessage());

            return $this->customerDashboard($request, $candleCashService, $appContentService, $scentQuizService, $scentQuizAnalytics);
        }

        $storeContext = $this->preferredStoreContextForProfile($profile);
        $this->eventLogger->log('public_customer_scent_quiz_completed', [
            'status' => 'ok',
            'profile' => $profile,
            'source_surface' => 'shopify_app_proxy',
            'endpoint' => '/shopify/marketing/scent-quiz/results',
            'source_type' => 'shopify_customer_dashboard_scent_quiz',
            'source_id' => 'profile:'.$profile->id,
            'meta' => [
                'store_key' => $storeContext['store_key'] ?? null,
                'tenant_id' => $storeContext['tenant_id'] ?? $profile->tenant_id,
                'quiz_version' => $result['version'] ?? ModernForestryMobileScentQuizService::QUIZ_VERSION,
                'headline' => $result['headline'] ?? null,
                'dominant_traits' => $result['dominantTraits'] ?? [],
            ],
            'resolution_status' => 'resolved',
        ]);

        $request->attributes->set('scent_quiz_notice', 'Your scent profile is saved and now follows your account.');
        $request->attributes->set('scent_quiz_completed', true);
        $request->attributes->set(
            'scent_quiz_attribution_payload',
            $scentQuizAnalytics->attributionPayload($profile, 'Scent quiz result')
        );

        return $this->customerDashboard($request, $candleCashService, $appContentService, $scentQuizService, $scentQuizAnalytics);
    }

    public function socialShareStarted(
        Request $request,
        ModernForestrySocialShareRewardService $socialShare
    ): JsonResponse {
        $profile = $this->resolveCustomerDashboardProfileFromRequest($request)['profile'];
        if (! $profile instanceof MarketingProfile) {
            return $this->socialShareJsonError('Sign in before sharing for Candle Cash.', 401);
        }

        $validated = $request->validate([
            'platform' => ['required', 'string', 'max:32'],
            'target' => ['required', 'array'],
            'target.type' => ['required', 'string', 'max:64'],
            'target.id' => ['nullable', 'string', 'max:160'],
            'target.handle' => ['nullable', 'string', 'max:160'],
            'target.title' => ['nullable', 'string', 'max:190'],
            'target.body' => ['nullable', 'string', 'max:500'],
            'target.imageUrl' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $payload = $socialShare->started(
                $profile,
                (string) $validated['platform'],
                (array) $validated['target'],
                [
                    'surface' => 'shopify_account',
                    'endpoint' => '/shopify/marketing/social-share/started',
                ]
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->socialShareJsonError($exception->getMessage(), 422);
        }

        return response()->json(['data' => $payload]);
    }

    public function socialShareClaim(
        Request $request,
        ModernForestrySocialShareRewardService $socialShare
    ): JsonResponse {
        $profile = $this->resolveCustomerDashboardProfileFromRequest($request)['profile'];
        if (! $profile instanceof MarketingProfile) {
            return $this->socialShareJsonError('Sign in before claiming this reward.', 401);
        }

        $validated = $request->validate([
            'platform' => ['required', 'string', 'max:32'],
            'target' => ['required', 'array'],
            'target.type' => ['required', 'string', 'max:64'],
            'target.id' => ['nullable', 'string', 'max:160'],
            'target.handle' => ['nullable', 'string', 'max:160'],
            'target.title' => ['nullable', 'string', 'max:190'],
            'target.body' => ['nullable', 'string', 'max:500'],
            'target.imageUrl' => ['nullable', 'string', 'max:1000'],
            'proofUrl' => ['nullable', 'string', 'max:1000'],
            'proofText' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $payload = $socialShare->claim(
                $profile,
                (string) $validated['platform'],
                (array) $validated['target'],
                [
                    'proof_url' => $validated['proofUrl'] ?? null,
                    'proof_text' => $validated['proofText'] ?? null,
                ],
                [
                    'surface' => 'shopify_account',
                    'endpoint' => '/shopify/marketing/social-share/claim',
                ]
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->socialShareJsonError($exception->getMessage(), 422);
        }

        return response()->json(['data' => $payload]);
    }

    public function showScentPersonalityShare(string $token): View
    {
        $result = MarketingProfileScentQuizResult::query()
            ->where('public_share_token', $token)
            ->firstOrFail();

        return view('marketing/public/scent-personality-share', [
            'result' => $result,
            'axes' => $this->normalizedScentShareAxes($result),
            'dominantTraits' => is_array($result->dominant_traits) ? $result->dominant_traits : [],
            'quizUrl' => rtrim((string) config('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com'), '/')
                .'/apps/forestry/account?scent_quiz=1',
        ]);
    }

    public function showScentPersonalityShareImage(string $token)
    {
        $result = MarketingProfileScentQuizResult::query()
            ->where('public_share_token', $token)
            ->firstOrFail();

        $image = $this->renderScentPersonalityShareImage($result);

        return response($image, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function showProductShare(string $handle, ModernForestryMobileProductCatalogService $catalog): View|RedirectResponse
    {
        $product = $catalog->productDetail($handle);
        if (! is_array($product)) {
            return redirect()->away(
                rtrim((string) config('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com'), '/')
                .'/products/'.ltrim($handle, '/')
            );
        }

        $title = trim((string) ($product['title'] ?? 'Modern Forestry candle')) ?: 'Modern Forestry candle';
        $description = trim((string) ($product['mobileSummary'] ?? $product['description'] ?? 'A hand-poured candle from Modern Forestry.'))
            ?: 'A hand-poured candle from Modern Forestry.';
        $images = array_values((array) ($product['images'] ?? []));
        $imageUrl = trim((string) (data_get($images, '0.url') ?? ''));
        $productUrl = trim((string) ($product['url'] ?? ''));

        return view('marketing/public/product-share', [
            'product' => $product,
            'headline' => $title.' | Modern Forestry',
            'title' => $title,
            'description' => $description,
            'shareImageUrl' => $imageUrl !== '' ? $imageUrl : asset('brand/forestry-backstage-intro-tree.png'),
            'productUrl' => $productUrl !== ''
                ? $productUrl
                : rtrim((string) config('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com'), '/')
                    .'/products/'.ltrim((string) ($product['handle'] ?? $handle), '/'),
        ]);
    }

    protected function renderScentPersonalityShareImage(MarketingProfileScentQuizResult $result): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            abort(500, 'GD is required to render scent share images.');
        }

        $width = 1200;
        $height = 630;
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        imageantialias($image, true);

        $colors = [
            'cream' => imagecolorallocate($image, 248, 243, 236),
            'paper' => imagecolorallocate($image, 255, 251, 245),
            'mist' => imagecolorallocate($image, 236, 231, 222),
            'forest' => imagecolorallocate($image, 36, 79, 56),
            'forestDeep' => imagecolorallocate($image, 17, 43, 30),
            'sage' => imagecolorallocate($image, 122, 149, 125),
            'ink' => imagecolorallocate($image, 24, 29, 26),
            'muted' => imagecolorallocate($image, 101, 96, 87),
            'line' => imagecolorallocate($image, 220, 213, 202),
            'emerald' => imagecolorallocate($image, 52, 120, 79),
            'emeraldFill' => imagecolorallocatealpha($image, 52, 120, 79, 88),
            'goldGlow' => imagecolorallocatealpha($image, 208, 169, 82, 92),
            'white' => imagecolorallocate($image, 255, 255, 255),
        ];

        imagefilledrectangle($image, 0, 0, $width, $height, $colors['cream']);
        imagefilledellipse($image, 1050, 90, 420, 260, $colors['goldGlow']);
        imagefilledellipse($image, 122, 70, 220, 160, imagecolorallocatealpha($image, 52, 120, 79, 102));

        imagefilledrectangle($image, 52, 48, $width - 52, $height - 48, $colors['paper']);
        imagerectangle($image, 52, 48, $width - 52, $height - 48, $colors['mist']);

        imagefilledrectangle($image, 52, 48, 640, 210, $colors['forest']);

        $boldFont = $this->scentShareFontPath(true);
        $regularFont = $this->scentShareFontPath(false);
        $headline = trim((string) ($result->headline ?: 'My Modern Forestry scent personality')) ?: 'My Modern Forestry scent personality';
        $title = trim((string) ($result->personality_title ?: 'Scent personality')) ?: 'Scent personality';
        $body = trim((string) ($result->personality_body ?: 'I took the Modern Forestry candle personality quiz and found the scent profile that fits me best.'))
            ?: 'I took the Modern Forestry candle personality quiz and found the scent profile that fits me best.';
        $traits = array_values(array_filter((array) $result->dominant_traits));
        $axes = $this->normalizedScentShareAxes($result);

        $this->drawWrappedScentShareText($image, $boldFont, 15, 0, 92, 98, strtoupper('Modern Forestry scent personality'), $colors['white'], 520, 1, 20);
        $this->drawWrappedScentShareText($image, $boldFont, 38, 0, 92, 152, $headline, $colors['white'], 480, 2, 48);
        $this->drawWrappedScentShareText($image, $regularFont, 18, 0, 92, 246, 'Take the candle personality quiz, see your scent map, and share it with friends.', $colors['sage'], 470, 2, 24);

        $this->drawWrappedScentShareText($image, $boldFont, 30, 0, 92, 334, $title, $colors['ink'], 420, 2, 38);
        $this->drawWrappedScentShareText($image, $regularFont, 24, 0, 92, 382, $body, $colors['muted'], 440, 4, 32);

        $traitX = 92;
        $traitY = 516;
        $maxTraitX = 640 - 92;
        foreach (array_slice($traits, 0, 4) as $trait) {
            $chipText = Str::headline((string) $trait);
            $textWidth = $this->scentShareTextWidth($boldFont, 18, $chipText);
            $chipWidth = max(118, $textWidth + 42);
            if ($traitX + $chipWidth > $maxTraitX) {
                $traitX = 92;
                $traitY += 54;
            }

            imagefilledrectangle($image, $traitX, $traitY, $traitX + $chipWidth, $traitY + 38, $colors['forest']);
            $this->drawWrappedScentShareText($image, $boldFont, 18, 0, $traitX + 18, $traitY + 26, $chipText, $colors['white'], $chipWidth - 24, 1, 22);
            $traitX += $chipWidth + 12;
        }

        imagefilledrectangle($image, 690, 84, 1096, 546, imagecolorallocatealpha($image, 255, 255, 255, 24));
        imagerectangle($image, 690, 84, 1096, 546, $colors['mist']);
        $this->drawRadarChart($image, 893, 316, 165, $axes, $boldFont, $regularFont, $colors);

        $treePath = public_path('brand/forestry-backstage-intro-tree.png');
        if (is_file($treePath)) {
            $logo = @imagecreatefrompng($treePath);
            if ($logo !== false) {
                imagealphablending($logo, true);
                imagesavealpha($logo, true);
                imagecopyresampled($image, $logo, 1000, 470, 0, 0, 64, 64, imagesx($logo), imagesy($logo));
                imagedestroy($logo);
            }
        }

        $this->drawWrappedScentShareText($image, $boldFont, 18, 0, 760, 586, 'Take your quiz at theforestrystudio.com', $colors['forestDeep'], 270, 2, 24);

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $binary;
    }

    /**
     * @return array<int,array{key:string,label:string,score:int}>
     */
    protected function normalizedScentShareAxes(MarketingProfileScentQuizResult $result): array
    {
        $definitions = [
            'floral' => 'Floral',
            'woodsy' => 'Woodsy',
            'smoky' => 'Smoky',
            'sweet' => 'Sweet',
            'masculine' => 'Masculine',
            'earthy' => 'Earthy',
            'clean' => 'Clean',
            'citrus' => 'Citrus',
        ];

        $source = [];
        foreach ((array) $result->axis_scores as $axis) {
            $key = Str::slug((string) data_get($axis, 'key', data_get($axis, 'label', '')));
            if ($key === '') {
                continue;
            }

            $source[$key] = max(0, min(100, (int) data_get($axis, 'score', 0)));
        }

        $normalized = [];
        foreach ($definitions as $key => $label) {
            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'score' => (int) ($source[$key] ?? 0),
            ];
        }

        return $normalized;
    }

    protected function drawRadarChart($image, int $centerX, int $centerY, int $radius, array $axes, ?string $boldFont, ?string $regularFont, array $colors): void
    {
        $count = count($axes);
        if ($count === 0) {
            return;
        }

        $stepAngle = (2 * M_PI) / $count;
        for ($ring = 1; $ring <= 4; $ring++) {
            $ringRadius = (int) round(($radius / 4) * $ring);
            $points = [];
            for ($index = 0; $index < $count; $index++) {
                $angle = -M_PI_2 + ($stepAngle * $index);
                $points[] = (int) round($centerX + cos($angle) * $ringRadius);
                $points[] = (int) round($centerY + sin($angle) * $ringRadius);
            }
            imagepolygon($image, $points, $count, $colors['line']);
        }

        $shapePoints = [];
        foreach ($axes as $index => $axis) {
            $angle = -M_PI_2 + ($stepAngle * $index);
            $axisX = (int) round($centerX + cos($angle) * $radius);
            $axisY = (int) round($centerY + sin($angle) * $radius);
            imageline($image, $centerX, $centerY, $axisX, $axisY, $colors['line']);

            $scoreRadius = (float) data_get($axis, 'score', 0) / 100 * $radius;
            $pointX = (int) round($centerX + cos($angle) * $scoreRadius);
            $pointY = (int) round($centerY + sin($angle) * $scoreRadius);
            $shapePoints[] = $pointX;
            $shapePoints[] = $pointY;
        }

        imagefilledpolygon($image, $shapePoints, $count, $colors['emeraldFill']);
        imagepolygon($image, $shapePoints, $count, $colors['emerald']);

        foreach ($axes as $index => $axis) {
            $angle = -M_PI_2 + ($stepAngle * $index);
            $labelRadius = $radius + 38;
            $labelX = (int) round($centerX + cos($angle) * $labelRadius);
            $labelY = (int) round($centerY + sin($angle) * $labelRadius);
            $text = strtoupper((string) data_get($axis, 'label', 'SCENT'));
            $textWidth = $this->scentShareTextWidth($boldFont, 15, $text);

            $drawX = $labelX - (int) round($textWidth / 2);
            if (cos($angle) > 0.35) {
                $drawX -= 10;
            } elseif (cos($angle) < -0.35) {
                $drawX += 10;
            }

            $this->drawWrappedScentShareText($image, $boldFont, 15, 0, $drawX, $labelY + 6, $text, $colors['muted'], 150, 1, 18);
        }
    }

    protected function scentShareFontPath(bool $bold): ?string
    {
        $candidates = $bold
            ? [
                resource_path('fonts/DejaVuSans-Bold.ttf'),
                public_path('fonts/DejaVuSans-Bold.ttf'),
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
                '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            ]
            : [
                resource_path('fonts/DejaVuSans.ttf'),
                public_path('fonts/DejaVuSans.ttf'),
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
                '/System/Library/Fonts/Supplemental/Arial.ttf',
            ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function scentShareTextWidth(?string $font, int $size, string $text): int
    {
        if ($font && function_exists('imagettfbbox')) {
            $box = imagettfbbox($size, 0, $font, $text);
            if (is_array($box)) {
                return max(0, (int) abs(($box[2] ?? 0) - ($box[0] ?? 0)));
            }
        }

        return imagefontwidth(5) * strlen($text);
    }

    protected function drawWrappedScentShareText($image, ?string $font, int $size, int $angle, int $x, int $y, string $text, int $color, int $maxWidth, int $maxLines, int $lineHeight): int
    {
        $lines = $this->wrapScentShareText($font, $size, $text, $maxWidth, $maxLines);
        $baseline = $y;

        foreach ($lines as $line) {
            if ($font && function_exists('imagettftext')) {
                imagettftext($image, $size, $angle, $x, $baseline, $color, $font, $line);
            } else {
                imagestring($image, 5, $x, max(0, $baseline - 18), $line, $color);
            }

            $baseline += $lineHeight;
        }

        return $baseline;
    }

    /**
     * @return array<int,string>
     */
    protected function wrapScentShareText(?string $font, int $size, string $text, int $maxWidth, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if ($words === []) {
            return [''];
        }

        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = trim($current.' '.$word);
            if ($current !== '' && $this->scentShareTextWidth($font, $size, $candidate) > $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }

            if (count($lines) === $maxLines) {
                break;
            }
        }

        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
        }

        if (count($lines) === $maxLines && count($words) > 0) {
            $remaining = trim(implode(' ', array_slice($words, 0)));
            if ($remaining !== '' && trim(implode(' ', $lines)) !== $remaining) {
                $lastIndex = $maxLines - 1;
                $trimmed = rtrim((string) ($lines[$lastIndex] ?? ''), " .");
                while ($trimmed !== '' && $this->scentShareTextWidth($font, $size, $trimmed.'...') > $maxWidth) {
                    $trimmedLength = function_exists('mb_strlen') ? mb_strlen($trimmed) : strlen($trimmed);
                    $trimmed = function_exists('mb_substr')
                        ? mb_substr($trimmed, 0, max(0, $trimmedLength - 1))
                        : substr($trimmed, 0, max(0, $trimmedLength - 1));
                }
                $lines[$lastIndex] = $trimmed.'...';
            }
        }

        return $lines;
    }

    /**
     * @return array<string,string>
     */
    protected function displayLabelsForTenantId(?int $tenantId): array
    {
        $resolved = $this->displayLabelResolver->resolve($tenantId);

        return is_array($resolved['labels'] ?? null)
            ? (array) $resolved['labels']
            : [];
    }

    /**
     * Public rewards lookup still defaults to Candle Cash branding when no
     * tenant/store context is available, preserving the current storefront
     * contract until the broader label rollout ships in a later release.
     *
     * @return array<string,string>
     */
    protected function publicRewardsLookupDisplayLabels(?int $tenantId): array
    {
        $labels = $this->displayLabelsForTenantId($tenantId);

        if ($tenantId !== null) {
            return $labels;
        }

        $rewardsLabel = trim((string) ($labels['rewards_label'] ?? $labels['rewards'] ?? ''));
        if ($rewardsLabel !== '' && strcasecmp($rewardsLabel, 'Rewards') !== 0) {
            return $labels;
        }

        return array_replace($labels, [
            'rewards_label' => 'Candle Cash',
            'rewards' => 'Candle Cash',
            'rewards_balance_label' => 'Candle Cash balance',
            'rewards_program_label' => 'Candle Cash program',
            'rewards_redemption_label' => 'Candle Cash redemption',
            'reward_credit_label' => 'Candle Cash credit',
        ]);
    }

    /**
     * @return array{profile:?MarketingProfile,state:string}
     */
    protected function resolveCustomerDashboardProfileFromRequest(Request $request): array
    {
        $tenantContext = $this->resolveTenantContext($request, $this->tenantResolver);
        if (! is_numeric($tenantContext['tenant_id'] ?? null) || (int) ($tenantContext['tenant_id'] ?? 0) <= 0) {
            return ['profile' => null, 'state' => 'missing_tenant_context'];
        }

        $shopifyCustomerId = trim((string) ($request->query('logged_in_customer_id', '')));
        if ($shopifyCustomerId === '') {
            $shopifyCustomerId = trim((string) ($request->query('shopify_customer_id', '')));
        }
        if ($shopifyCustomerId === '') {
            $shopifyCustomerId = trim((string) ($request->query('customer_id', '')));
        }

        if ($shopifyCustomerId !== '') {
            $profile = $this->profileForShopifyCustomerId(
                (int) $tenantContext['tenant_id'],
                $shopifyCustomerId,
                is_string($tenantContext['store_key'] ?? null) ? (string) $tenantContext['store_key'] : null
            );
            if ($profile) {
                return ['profile' => $profile, 'state' => 'linked_customer'];
            }
        }

        return [
            'profile' => null,
            'state' => $shopifyCustomerId !== '' ? 'unknown_customer' : 'customer_login_required',
        ];
    }

    protected function profileForShopifyCustomerId(int $tenantId, string $shopifyCustomerId, ?string $storeKey = null): ?MarketingProfile
    {
        $normalized = trim($shopifyCustomerId);
        if ($normalized === '') {
            return null;
        }

        $digits = preg_match('/(\d+)(?!.*\d)/', $normalized, $matches) === 1
            ? (string) $matches[1]
            : null;

        $possibleSourceIds = array_values(array_unique(array_filter([
            $normalized,
            $digits,
            $storeKey !== null && $digits !== null ? $storeKey.':'.$digits : null,
            $storeKey !== null ? $storeKey.':'.$normalized : null,
        ])));

        $profileId = MarketingProfileLink::query()
            ->where('tenant_id', $tenantId)
            ->where('source_type', 'shopify_customer')
            ->where(function ($query) use ($possibleSourceIds): void {
                foreach ($possibleSourceIds as $value) {
                    $query->orWhere('source_id', $value)
                        ->orWhere('source_id', 'retail:'.$value)
                        ->orWhere('source_id', 'shopify:'.$value);
                }
            })
            ->orderByDesc('id')
            ->value('marketing_profile_id');

        if (is_numeric($profileId) && (int) $profileId > 0) {
            return MarketingProfile::query()
                ->where('tenant_id', $tenantId)
                ->find((int) $profileId);
        }

        $externalProfile = CustomerExternalProfile::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($possibleSourceIds): void {
                foreach ($possibleSourceIds as $value) {
                    $query->orWhere('external_customer_id', $value)
                        ->orWhere('external_customer_gid', $value);
                }
            })
            ->latest('id')
            ->first();

        if ($externalProfile instanceof CustomerExternalProfile) {
            return $externalProfile->marketingProfile()
                ->where('tenant_id', $tenantId)
                ->first();
        }

        return null;
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function customerDashboardOrders(MarketingProfile $profile, ?int $tenantId = null): Collection
    {
        $resolvedTenantId = is_numeric($profile->tenant_id) && (int) $profile->tenant_id > 0
            ? (int) $profile->tenant_id
            : $tenantId;
        if ($resolvedTenantId === null) {
            return collect();
        }

        $orders = collect();
        $linkedOrderIds = $profile->links()
            ->where('source_type', 'order')
            ->pluck('source_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        if ($linkedOrderIds->isNotEmpty()) {
            $orders = $orders->concat(
                Order::query()
                    ->where('tenant_id', $resolvedTenantId)
                    ->whereIn('id', $linkedOrderIds->all())
                    ->with(['lines'])
                    ->orderByDesc('ordered_at')
                    ->orderByDesc('id')
                    ->get()
            );
        }

        $shopifyCustomerIds = $profile->links()
            ->where('source_type', 'shopify_customer')
            ->pluck('source_id')
            ->map(function ($value): ?string {
                $normalized = trim((string) $value);
                if ($normalized === '') {
                    return null;
                }
                if (preg_match('/:(\d+)(?:$|[^0-9])/', $normalized, $matches) === 1) {
                    return (string) $matches[1];
                }
                if (preg_match('/(\d+)(?!.*\d)/', $normalized, $matches) === 1) {
                    return (string) $matches[1];
                }

                return null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($shopifyCustomerIds->isNotEmpty()) {
            $orders = $orders->concat(
                Order::query()
                    ->where('tenant_id', $resolvedTenantId)
                    ->whereIn('shopify_customer_id', $shopifyCustomerIds->all())
                    ->with(['lines'])
                    ->orderByDesc('ordered_at')
                    ->orderByDesc('id')
                    ->get()
            );
        }

        return $orders
            ->unique('id')
            ->sortByDesc(fn (Order $order): int => optional($order->ordered_at)->timestamp ?? ((int) $order->id))
            ->map(function (Order $order): array {
                $lines = $order->lines->map(function (OrderLine $line): array {
                    $title = trim((string) ($line->raw_title ?? '')) ?: trim((string) ($line->raw_variant ?? '')) ?: 'Item';
                    $handle = Str::slug($title);

                    return [
                        'id' => (int) $line->id,
                        'title' => $title,
                        'quantity' => max(1, (int) ($line->quantity ?: $line->ordered_qty ?: 1)),
                        'handle' => $handle !== '' ? $handle : null,
                        'image_url' => $line->image_url ? (string) $line->image_url : null,
                        'share_target_id' => 'order-line:'.$line->id,
                        'share_target_type' => 'purchased_product',
                        'shopify_product_id' => $line->shopify_product_id ? (string) $line->shopify_product_id : null,
                        'shopify_variant_id' => $line->shopify_variant_id ? (string) $line->shopify_variant_id : null,
                    ];
                })->values();

                $storeKey = trim((string) ($order->shopify_store_key ?: $order->shopify_store ?: ''));
                $shopDomain = $this->shopDomainForStoreKey($storeKey);

                return [
                    'id' => (int) $order->id,
                    'order_number' => (string) ($order->order_number ?: $order->order_label ?: ('#'.$order->id)),
                    'title' => (string) ($order->display_name ?: $order->order_number ?: $order->order_label ?: ('Order #'.$order->id)),
                    'ordered_at' => optional($order->ordered_at)->toIso8601String(),
                    'status' => (string) ($order->status ?: 'open'),
                    'currency_code' => (string) ($order->currency_code ?: 'USD'),
                    'total_price_formatted' => '$'.number_format((float) ($order->total_price ?? 0), 2),
                    'line_count' => $lines->count(),
                    'line_preview' => $lines->take(3)->pluck('title')->implode(' · '),
                    'reorder_url' => $this->reorderUrlForOrder($shopDomain, $lines),
                    'lines' => $lines->all(),
                ];
            })
            ->values();
    }

    /**
     * @return array{
     *   conversation_id:?int,
     *   sms_status:string,
     *   can_compose:bool,
     *   phone_display:string,
     *   support_prompt:string,
     *   messages:array<int,array<string,mixed>>
     * }
     */
    protected function customerDashboardMessages(MarketingProfile $profile, int $tenantId, ?string $storeKey = null): array
    {
        $phone = trim((string) ($profile->normalized_phone ?: $profile->phone));
        $smsStatus = $this->channelStateService->resolveSmsStatus($tenantId, $profile, $phone !== '' ? $phone : null);
        $hasPhone = $phone !== '';
        $canCompose = $hasPhone
            && (bool) $profile->accepts_sms_marketing
            && ! in_array($smsStatus, ['unsubscribed', 'suppressed'], true);
        $supportPrompt = $canCompose
            ? 'Send a message here and it will stay threaded with the Shopify inbox.'
            : 'Messages are not available for this account right now. Use support instead.';

        $conversation = null;
        if ($hasPhone) {
            $conversation = MessagingConversation::query()
                ->forTenantId($tenantId)
                ->where('channel', 'sms')
                ->where('marketing_profile_id', (int) $profile->id)
                ->when(
                    $storeKey !== null,
                    fn ($query) => $query->where('store_key', $storeKey),
                    fn ($query) => $query->whereNull('store_key')
                )
                ->with(['messages.creator'])
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->first();
        }

        $messages = [];
        if ($conversation instanceof MessagingConversation) {
            $messages = $conversation->messages()
                ->orderByRaw('COALESCE(received_at, sent_at, created_at) asc')
                ->get()
                ->map(fn (MessagingConversationMessage $message): array => [
                    'id' => (int) $message->id,
                    'direction' => (string) $message->direction,
                    'body' => (string) $message->body,
                    'message_type' => (string) $message->message_type,
                    'from_identity' => $message->from_identity ? (string) $message->from_identity : null,
                    'to_identity' => $message->to_identity ? (string) $message->to_identity : null,
                    'created_at' => optional($message->received_at ?? $message->sent_at ?? $message->created_at)->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return [
            'conversation_id' => $conversation ? (int) $conversation->id : null,
            'sms_status' => $smsStatus,
            'can_compose' => $canCompose,
            'phone_display' => $hasPhone ? $phone : 'No phone on file',
            'support_prompt' => $supportPrompt,
            'messages' => $messages,
        ];
    }

    protected function customerDashboardMessageActionUrl(Request $request): string
    {
        $routeName = $request->is('shopify/marketing/v1/*')
            ? 'marketing.shopify.v1.message'
            : 'marketing.shopify.message';

        return route($routeName, $request->query(), false);
    }

    protected function customerDashboardScentQuizActionUrl(Request $request): string
    {
        $routeName = $request->is('shopify/marketing/v1/*')
            ? 'marketing.shopify.v1.scent-quiz.submit'
            : 'marketing.shopify.scent-quiz.submit';

        return route($routeName, $request->query(), false);
    }

    protected function customerDashboardSocialShareStartedUrl(Request $request): string
    {
        $routeName = $request->is('shopify/marketing/v1/*')
            ? 'marketing.shopify.v1.social-share.started'
            : 'marketing.shopify.social-share.started';

        return route($routeName, $request->query(), false);
    }

    protected function customerDashboardSocialShareClaimUrl(Request $request): string
    {
        $routeName = $request->is('shopify/marketing/v1/*')
            ? 'marketing.shopify.v1.social-share.claim'
            : 'marketing.shopify.social-share.claim';

        return route($routeName, $request->query(), false);
    }

    protected function socialShareJsonError(string $message, int $status): JsonResponse
    {
        return response()->json([
            'data' => null,
            'error' => [
                'code' => 'social_share_unavailable',
                'message' => $message,
            ],
        ], $status);
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $lines
     */
    protected function reorderUrlForOrder(?string $shopDomain, Collection $lines): ?string
    {
        $lineItems = $lines
            ->filter(fn (array $line): bool => ! empty($line['shopify_variant_id']))
            ->map(fn (array $line): string => rawurlencode((string) $line['shopify_variant_id']).':'.max(1, (int) ($line['quantity'] ?? 1)))
            ->values();

        if ($shopDomain === null || $shopDomain === '' || $lineItems->isEmpty()) {
            return null;
        }

        return 'https://'.$shopDomain.'/cart/'.$lineItems->implode(',');
    }

    protected function shopDomainForStoreKey(?string $storeKey): ?string
    {
        $normalizedStoreKey = strtolower(trim((string) $storeKey));
        if ($normalizedStoreKey === '') {
            return null;
        }

        $store = ShopifyStores::find($normalizedStoreKey);
        $shopDomain = trim((string) ($store['shop'] ?? ''));

        return $shopDomain !== '' ? $shopDomain : null;
    }

    /**
     * @param  array<string,mixed>  $content
     */
    protected function supportLinkForDashboard(array $content): ?string
    {
        $published = is_array($content['published'] ?? null)
            ? (array) $content['published']
            : (is_array($content['effective'] ?? null) ? (array) $content['effective'] : []);

        $supportUrl = trim((string) ($published['support_url'] ?? ''));
        if ($supportUrl !== '' && filter_var($supportUrl, FILTER_VALIDATE_URL)) {
            return $supportUrl;
        }

        $supportEmail = trim((string) ($published['support_email'] ?? ''));
        if ($supportEmail !== '' && filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:'.$supportEmail;
        }

        return null;
    }

    /**
     * @return array{profile:?MarketingProfile,state:string}
     */
    protected function resolveProfileFromRequest(Request $request, string $scope, bool $allowBody = false): array
    {
        $tenantContext = $this->resolveTenantContext($request, $this->tenantResolver);
        if (! is_numeric($tenantContext['tenant_id'] ?? null) || (int) ($tenantContext['tenant_id'] ?? 0) <= 0) {
            return ['profile' => null, 'state' => 'missing_tenant_context'];
        }

        $rawEmail = trim((string) ($allowBody ? $request->input('email', $request->query('email', '')) : $request->query('email', '')));
        $rawPhone = trim((string) ($allowBody ? $request->input('phone', $request->query('phone', '')) : $request->query('phone', '')));
        $email = $this->normalizer->normalizeEmail($rawEmail);
        $phone = $this->normalizer->normalizePhone($rawPhone);

        // Public lookup requires both fields to avoid easy profile enumeration.
        if (! $email || ! $phone) {
            return ['profile' => null, 'state' => 'verification_required'];
        }

        $sourceId = $this->storefrontIdentityService->deterministicSourceId(
            prefix: $scope,
            email: $rawEmail,
            phone: $rawPhone,
            extra: [
                (string) ($tenantContext['store_key'] ?? ''),
                (string) ($tenantContext['tenant_id'] ?? ''),
            ]
        );

        $resolved = $this->storefrontIdentityService->resolve([
            'email' => $rawEmail,
            'phone' => $rawPhone,
        ], [
            'source_type' => $scope,
            'source_id' => $sourceId,
            'source_label' => $scope,
            'source_channels' => ['public_event'],
            'tenant_id' => $tenantContext['tenant_id'],
            'source_meta' => [
                'lookup' => true,
                'shopify_store_key' => $tenantContext['store_key'],
                'tenant_id' => $tenantContext['tenant_id'],
            ],
            'allow_create' => false,
        ]);

        return [
            'profile' => $resolved['profile'],
            'state' => $resolved['profile'] ? 'linked_customer' : ($resolved['status'] === 'review_required' ? 'needs_verification' : 'unknown_customer'),
        ];
    }

    /**
     * @param  array{store_key:?string,tenant_id:?int}  $tenantContext
     */
    protected function resolvedTenantId(?MarketingProfile $profile, array $tenantContext): ?int
    {
        if ($profile && is_numeric($profile->tenant_id) && (int) $profile->tenant_id > 0) {
            return (int) $profile->tenant_id;
        }

        return is_numeric($tenantContext['tenant_id'] ?? null) && (int) ($tenantContext['tenant_id'] ?? 0) > 0
            ? (int) $tenantContext['tenant_id']
            : null;
    }

    /**
     * @return array{store_key:?string,tenant_id:?int}
     */
    protected function resolveTenantContext(Request $request, TenantResolver $tenantResolver): array
    {
        $storeKey = strtolower(trim((string) ($request->input('store_key', $request->query('store_key', '')))));
        if ($storeKey === '') {
            $shop = trim((string) ($request->input('shop', $request->query('shop', ''))));
            $resolvedStore = $shop !== '' ? ShopifyStores::findByShopDomain($shop) : null;
            $storeKey = strtolower(trim((string) ($resolvedStore['key'] ?? '')));
        }

        if ($storeKey === '') {
            return [
                'store_key' => null,
                'tenant_id' => null,
            ];
        }

        return [
            'store_key' => $storeKey,
            'tenant_id' => $tenantResolver->resolveTenantIdForStoreKey($storeKey),
        ];
    }

    /**
     * @return array{store_key:?string,tenant_id:?int}
     */
    protected function preferredStoreContextForProfile(MarketingProfile $profile): array
    {
        $storeKey = $this->preferredStoreKeyForProfile($profile);

        return [
            'store_key' => $storeKey,
            'tenant_id' => $storeKey ? $this->tenantResolver->resolveTenantIdForStoreKey($storeKey) : null,
        ];
    }

    protected function preferredStoreKeyForProfile(MarketingProfile $profile): ?string
    {
        $linkedStoreKeys = $profile->links()
            ->where('source_type', 'shopify_customer')
            ->pluck('source_id')
            ->map(function ($sourceId): ?string {
                $value = trim((string) $sourceId);
                if (preg_match('/^(retail|wholesale):/i', $value, $matches) === 1) {
                    return strtolower((string) $matches[1]);
                }

                return null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($linkedStoreKeys->contains('retail')) {
            return 'retail';
        }

        if ($linkedStoreKeys->isNotEmpty()) {
            return (string) $linkedStoreKeys->first();
        }

        $externalStoreKeys = $profile->externalProfiles()
            ->pluck('store_key')
            ->map(fn ($storeKey): ?string => $this->normalizeStoreKey($storeKey))
            ->filter()
            ->unique()
            ->values();

        if ($externalStoreKeys->contains('retail')) {
            return 'retail';
        }

        return $externalStoreKeys->isNotEmpty() ? (string) $externalStoreKeys->first() : null;
    }

    /**
     * @param  array{store_key:?string,tenant_id:?int}  $storeContext
     */
    protected function syncRedemptionStoreContext(CandleCashRedemption $redemption, array $storeContext): CandleCashRedemption
    {
        $storeKey = $this->normalizeStoreKey($storeContext['store_key'] ?? null);
        $tenantId = is_numeric($storeContext['tenant_id'] ?? null) && (int) ($storeContext['tenant_id'] ?? 0) > 0
            ? (int) $storeContext['tenant_id']
            : null;

        if ($storeKey === null && $tenantId === null) {
            return $redemption;
        }

        $context = is_array($redemption->redemption_context ?? null) ? $redemption->redemption_context : [];
        $nextContext = array_filter([
            ...$context,
            'shopify_store_key' => $storeKey ?? ($context['shopify_store_key'] ?? null),
            'tenant_id' => $tenantId ?? ($context['tenant_id'] ?? null),
        ], static fn ($value): bool => $value !== null && $value !== '');

        if ($nextContext === $context) {
            return $redemption;
        }

        $redemption->forceFill(['redemption_context' => $nextContext])->save();

        return $redemption->fresh() ?? $redemption;
    }

    protected function normalizeStoreKey(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    protected function candleCashApplyUrlForStore(?string $storeKey, string $rewardCode): ?string
    {
        $rewardCode = trim($rewardCode);
        if ($storeKey === null || $rewardCode === '') {
            return null;
        }

        $store = ShopifyStores::find($storeKey);
        $shopDomain = trim((string) ($store['shop'] ?? ''));
        if ($shopDomain === '') {
            return null;
        }

        $redirect = '/cart?forestry_reward_code='.rawurlencode($rewardCode).'&forestry_reward_kind=candle_cash';

        return 'https://'.$shopDomain.'/discount/'.rawurlencode($rewardCode).'?redirect='.rawurlencode($redirect);
    }

    protected function queueShopifyCustomerProvisioning(
        MarketingProfile $profile,
        ?string $storeKey,
        mixed $tenantId,
        string $trigger
    ): void {
        $normalizedStoreKey = strtolower(trim((string) $storeKey));
        $resolvedTenantId = is_numeric($tenantId)
            ? (int) $tenantId
            : (is_numeric($profile->tenant_id) ? (int) $profile->tenant_id : 0);

        if ($normalizedStoreKey === '' || $resolvedTenantId <= 0) {
            return;
        }

        try {
            ProvisionShopifyCustomerForMarketingProfile::dispatch(
                marketingProfileId: (int) $profile->id,
                storeKey: $normalizedStoreKey,
                tenantId: $resolvedTenantId,
                trigger: $trigger
            )->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('shopify customer provisioning dispatch failed', [
                'marketing_profile_id' => (int) $profile->id,
                'store_key' => $normalizedStoreKey,
                'trigger' => $trigger,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{
     *   0:?array<string,mixed>,
     *   1:array<int,array<string,mixed>>,
     *   2:array<int,array<string,mixed>>,
     *   3:Collection<int,array<string,mixed>>,
     *   4:?CustomerExternalProfile,
     *   5:array{review_count:int,average_rating:?float,last_reviewed_at:?string},
     *   6:array{review_count:int,average_rating:?float,last_reviewed_at:?string},
     *   7:?MarketingReviewSummary,
     *   8:array{count:int,last_rewarded_at:?string,source:string},
     *   9:array{count:int,last_rewarded_at:?string,source:string},
     *   10:array{count:int,last_rewarded_at:?string,source:string},
     *   11:?string,
     *   12:string
     * }
     */
    protected function rewardsLookupData(?MarketingProfile $profile, CandleCashService $candleCashService, array $displayLabels = [], ?int $tenantId = null): array
    {
        $rewardsLabel = trim((string) ($displayLabels['rewards_label'] ?? $displayLabels['rewards'] ?? 'Rewards'));
        if ($rewardsLabel === '') {
            $rewardsLabel = 'Rewards';
        }
        $rewardCreditLabel = trim((string) ($displayLabels['reward_credit_label'] ?? 'reward credit'));
        if ($rewardCreditLabel === '') {
            $rewardCreditLabel = 'reward credit';
        }

        $resolvedTenantId = $tenantId;
        if ($resolvedTenantId === null && $profile && is_numeric($profile->tenant_id) && (int) $profile->tenant_id > 0) {
            $resolvedTenantId = (int) $profile->tenant_id;
        }

        $availableRewards = $resolvedTenantId !== null
            ? array_values(array_filter([
                $candleCashService->storefrontRewardPayload(
                    $candleCashService->storefrontReward($resolvedTenantId),
                    null,
                    $resolvedTenantId
                ),
            ]))
            : [];

        if (! $profile) {
            return [
                null,
                $availableRewards,
                collect(),
                collect(),
                null,
                ['review_count' => 0, 'average_rating' => null, 'last_reviewed_at' => null],
                ['review_count' => 0, 'average_rating' => null, 'last_reviewed_at' => null],
                null,
                ['count' => 0, 'last_rewarded_at' => null, 'source' => 'none'],
                ['count' => 0, 'last_rewarded_at' => null, 'source' => 'none'],
                ['count' => 0, 'last_rewarded_at' => null, 'source' => 'none'],
                null,
                'none',
            ];
        }

        $balancePoints = $candleCashService->currentBalance($profile);
        $balance = $candleCashService->balancePayloadFromPoints($balancePoints);
        $redemptionAccess = $this->candleCashAccessGate->storefrontRedeemAccessPayload($profile);
        $revealRedemptionCodes = (bool) ($redemptionAccess['redeem_enabled'] ?? false);
        $availableRewards = $resolvedTenantId !== null
            ? array_values(array_filter([
                $candleCashService->storefrontRewardPayload(
                    $candleCashService->storefrontReward($resolvedTenantId),
                    $balancePoints,
                    $resolvedTenantId
                ),
            ]))
            : [];
        $redemptions = $profile->candleCashRedemptions()
            ->with('reward:id,name,reward_type,reward_value')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => $row->reward && $candleCashService->isStorefrontReward($row->reward, $resolvedTenantId)
                    ? 'Redeem '.$candleCashService->fixedRedemptionFormatted($resolvedTenantId).' '.Str::title($rewardCreditLabel)
                    : (string) ($row->reward?->name ?: $rewardsLabel),
                'status' => (string) ($row->status ?: 'issued'),
                'redemption_code' => $revealRedemptionCodes && $row->redemption_code ? (string) $row->redemption_code : null,
                'issued_at' => optional($row->issued_at)->toDateTimeString(),
                'redeemed_at' => optional($row->redeemed_at)->toDateTimeString(),
                'candle_cash_amount' => $candleCashService->amountFromPoints($row->candle_cash_spent),
                'candle_cash_amount_formatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints($row->candle_cash_spent)),
            ])->all();

        $transactionRows = $profile->candleCashTransactions()
            ->orderByDesc('id')
            ->limit(40)
            ->get(['id', 'type', 'candle_cash_delta', 'source', 'source_id', 'description', 'created_at']);

        $transactions = $transactionRows->map(function (CandleCashTransaction $transaction) use ($candleCashService): array {
            return [
                'id' => (int) $transaction->id,
                'category' => $this->transactionCategoryLabel($transaction),
                'candle_cash_amount' => $candleCashService->amountFromPoints($transaction->candle_cash_delta),
                'candle_cash_amount_formatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints(abs((float) $transaction->candle_cash_delta))),
                'signed_candle_cash_amount_formatted' => $candleCashService->candleCashAmountLabelFromPoints($transaction->candle_cash_delta, true),
                'description' => trim((string) ($transaction->description ?? '')) ?: null,
                'source' => (string) $transaction->source,
                'occurred_at' => optional($transaction->created_at)->toDateTimeString(),
            ];
        })->values();

        $latestGrowaveExternal = $profile->externalProfiles()
            ->where('provider', 'shopify')
            ->where('integration', 'growave')
            ->get();
        $latestGrowaveExternal = $this->growaveProjectionService->preferredExternal($latestGrowaveExternal);

        $latestReviewSummary = $this->growaveProjectionService->preferredReviewSummary(
            $profile->reviewSummaries()
                ->where('provider', 'growave')
                ->where('integration', 'growave')
                ->get(),
            $latestGrowaveExternal
        );

        $nativeApprovedReviews = $profile->reviewHistory()
            ->where('provider', 'backstage')
            ->where('integration', 'native')
            ->where('status', 'approved')
            ->where('is_published', true);

        $nativeReviewCount = (int) (clone $nativeApprovedReviews)->count();
        $nativeAverageRating = $nativeReviewCount > 0
            ? round((float) ((clone $nativeApprovedReviews)->avg('rating') ?? 0), 2)
            : null;
        $latestNativeReview = (clone $nativeApprovedReviews)
            ->orderByDesc('approved_at')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->first(['approved_at', 'reviewed_at', 'created_at']);
        $nativeLastReviewedAt = optional(
            $latestNativeReview?->approved_at ?: $latestNativeReview?->reviewed_at ?: $latestNativeReview?->created_at
        )->toDateTimeString();

        $nativeReviewSummary = [
            'review_count' => $nativeReviewCount,
            'average_rating' => $nativeAverageRating,
            'last_reviewed_at' => $nativeLastReviewedAt,
        ];

        $reviewRewardRows = $transactionRows
            ->filter(fn (CandleCashTransaction $row): bool => (string) $row->source === 'growave_activity'
                && $row->candle_cash_delta > 0
                && str_contains(strtolower((string) ($row->description ?? '')), 'review'))
            ->values();

        $legacyReviewRewardStatus = [
            'count' => $reviewRewardRows->count(),
            'last_rewarded_at' => optional($reviewRewardRows->first()?->created_at)->toDateTimeString(),
            'source' => 'legacy_growave',
        ];

        $nativeReviewRewardCompletions = CandleCashTaskCompletion::query()
            ->where('marketing_profile_id', $profile->id)
            ->whereIn('status', ['awarded', 'approved'])
            ->whereHas('task', fn ($builder) => $builder->where('handle', 'product-review'))
            ->orderByDesc('awarded_at')
            ->orderByDesc('id');
        $latestNativeReviewReward = (clone $nativeReviewRewardCompletions)->first(['awarded_at', 'reviewed_at', 'created_at']);

        $nativeReviewRewardStatus = [
            'count' => (int) (clone $nativeReviewRewardCompletions)->count(),
            'last_rewarded_at' => optional(
                $latestNativeReviewReward?->awarded_at ?: $latestNativeReviewReward?->reviewed_at ?: $latestNativeReviewReward?->created_at
            )->toDateTimeString(),
            'source' => 'native_task_completion',
        ];

        $lastGrowaveSyncAt = collect([
            optional($latestGrowaveExternal?->synced_at)->toDateTimeString(),
            optional($latestReviewSummary?->source_synced_at)->toDateTimeString(),
        ])->filter()->max();

        $legacyReviewSummary = [
            'review_count' => (int) ($latestReviewSummary?->review_count ?? 0),
            'average_rating' => $latestReviewSummary?->average_rating !== null
                ? round((float) $latestReviewSummary->average_rating, 2)
                : null,
            'last_reviewed_at' => optional($latestReviewSummary?->source_synced_at)->toDateTimeString(),
        ];

        $hasNativeReviewSignals = $nativeReviewSummary['review_count'] > 0 || $nativeReviewRewardStatus['count'] > 0;
        $hasLegacyReviewSignals = $legacyReviewSummary['review_count'] > 0 || $legacyReviewRewardStatus['count'] > 0;

        $reviewSummary = $hasNativeReviewSignals ? $nativeReviewSummary : $legacyReviewSummary;
        $reviewRewardStatus = $hasNativeReviewSignals ? $nativeReviewRewardStatus : $legacyReviewRewardStatus;
        $reviewDataSource = $hasNativeReviewSignals
            ? 'native'
            : ($hasLegacyReviewSignals ? 'legacy_growave' : 'none');

        return [
            $balance,
            $availableRewards,
            $redemptions,
            $transactions,
            $latestGrowaveExternal,
            $reviewSummary,
            $nativeReviewSummary,
            $latestReviewSummary,
            $reviewRewardStatus,
            $nativeReviewRewardStatus,
            $legacyReviewRewardStatus,
            $lastGrowaveSyncAt,
            $reviewDataSource,
        ];
    }

    protected function transactionCategoryLabel(CandleCashTransaction $transaction): string
    {
        $type = strtolower(trim((string) $transaction->type));
        $description = strtolower(trim((string) ($transaction->description ?? '')));

        if ((string) $transaction->source === 'growave_activity') {
            if (str_contains($description, '(redeem)') || $transaction->candle_cash_delta < 0) {
                return 'Redeemed';
            }
            if (str_contains($description, '(expired)')) {
                return 'Expired';
            }
            if (str_contains($description, 'referr')) {
                return 'Referral Reward';
            }
            if (str_contains($description, 'review')) {
                return 'Review Reward';
            }
            if (str_contains($description, '(manual)') || str_contains($description, 'manual')) {
                return 'Manual Adjustment';
            }
        }

        return match ($type) {
            'earn' => 'Earned',
            'redeem' => 'Redeemed',
            'expire' => 'Expired',
            'adjust' => 'Adjustment',
            default => Str::title(str_replace('_', ' ', $type)),
        };
    }

    protected function redemptionMessageForState(string $state): string
    {
        return match ($state) {
            'code_issued' => 'Reward credit redeemed successfully. Your $10 reward code is ready to use.',
            'already_has_active_code' => 'You already have a $10 reward credit waiting for you.',
            'insufficient_candle_cash' => 'You need a little more reward balance before the $10 redemption is ready.',
            'missing_tenant_context' => 'This rewards lookup needs a valid store context before it can continue.',
            'reward_unavailable' => 'This reward is currently unavailable.',
            'redemption_blocked' => 'Redemption is temporarily blocked. Please try again later.',
            default => 'Could not process redemption right now. Please try again shortly.',
        };
    }
}
