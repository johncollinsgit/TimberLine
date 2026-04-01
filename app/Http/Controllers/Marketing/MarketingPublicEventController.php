<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashRedemption;
use App\Jobs\ProvisionShopifyCustomerForMarketingProfile;
use App\Models\CandleCashReward;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingReviewSummary;
use App\Services\Marketing\CandleCashAccessGate;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\GrowaveProjectionService;
use App\Services\Marketing\MarketingConsentIncentiveService;
use App\Services\Marketing\MarketingConsentService;
use App\Services\Marketing\CandleCashTaskService;
use App\Services\Marketing\CandleCashShopifyDiscountService;
use App\Services\Marketing\MarketingProfileSyncService;
use App\Services\Marketing\MarketingStorefrontEventLogger;
use App\Services\Marketing\MarketingStorefrontIdentityService;
use App\Support\Marketing\MarketingEventContextResolver;
use App\Support\Marketing\MarketingIdentityNormalizer;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
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
        protected TenantDisplayLabelResolver $displayLabelResolver
    ) {
    }

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
            'endpoint' => '/events/' . ($eventContext['slug'] ?? Str::slug($eventSlug)) . '/optin',
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
            prefix: 'event_public_optin:' . $canonicalSlug,
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
                'endpoint' => '/events/' . $canonicalSlug . '/optin',
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
                'source_id' => $sourceId . ':email',
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
            'endpoint' => '/events/' . $canonicalSlug . '/optin',
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
        $resolution = $this->resolveProfileFromRequest($request, 'event_reward_lookup:' . ($eventContext['slug'] ?? Str::slug($eventSlug)));
        $profile = $resolution['profile'];
        $lookupState = $resolution['state'];
        $tenantId = $this->resolvedTenantId($profile, $tenantContext);

        $this->eventLogger->log('public_reward_lookup', [
            'status' => $profile ? 'ok' : ($lookupState === 'verification_required' ? 'verification_required' : 'pending'),
            'issue_type' => $profile ? null : $lookupState,
            'source_surface' => 'public_event',
            'endpoint' => '/events/' . ($eventContext['slug'] ?? Str::slug($eventSlug)) . '/rewards',
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
                            ? 'Redeem ' . $candleCashService->fixedRedemptionFormatted($tenantId) . ' ' . Str::title($rewardCreditLabel)
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
    ): RedirectResponse
    {
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
                'issue_type' => 'identity_' . $lookupState,
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
                'issue_type' => 'coming_soon',
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
                    'state' => 'coming_soon',
                    'message' => 'Reward redemption is coming soon for this account.',
                    'balance' => $candleCashService->balancePayloadFromPoints($candleCashService->currentBalance($profile)),
                    'cta_label' => $redemptionAccess['cta_label'] ?? 'COMING SOON!',
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
                'reward_name' => 'Redeem ' . $candleCashService->fixedRedemptionFormatted($tenantId) . ' ' . Str::title($rewardCreditLabel),
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
     * @param array{store_key:?string,tenant_id:?int} $tenantContext
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
     * @param array{store_key:?string,tenant_id:?int} $storeContext
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

        $redirect = '/cart?forestry_reward_code=' . rawurlencode($rewardCode) . '&forestry_reward_kind=candle_cash';

        return 'https://' . $shopDomain . '/discount/' . rawurlencode($rewardCode) . '?redirect=' . rawurlencode($redirect);
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
                    ? 'Redeem ' . $candleCashService->fixedRedemptionFormatted($resolvedTenantId) . ' ' . Str::title($rewardCreditLabel)
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
