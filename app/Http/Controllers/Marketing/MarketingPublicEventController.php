<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CandleCashReward;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingReviewSummary;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\GrowaveProjectionService;
use App\Services\Marketing\MarketingConsentIncentiveService;
use App\Services\Marketing\MarketingConsentService;
use App\Services\Marketing\CandleCashTaskService;
use App\Services\Marketing\MarketingProfileSyncService;
use App\Services\Marketing\MarketingStorefrontEventLogger;
use App\Services\Marketing\MarketingStorefrontIdentityService;
use App\Support\Marketing\MarketingEventContextResolver;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MarketingPublicEventController extends Controller
{
    public function __construct(
        protected MarketingEventContextResolver $eventContextResolver,
        protected MarketingIdentityNormalizer $normalizer,
        protected MarketingStorefrontIdentityService $storefrontIdentityService,
        protected MarketingStorefrontEventLogger $eventLogger,
        protected GrowaveProjectionService $growaveProjectionService
    ) {
    }

    public function showOptin(string $eventSlug): View|RedirectResponse
    {
        $eventContext = $this->eventContextResolver->resolve($eventSlug);
        if ($eventContext && (string) $eventContext['slug'] !== Str::slug($eventSlug)) {
            return redirect()->route('marketing.public.events.optin', ['eventSlug' => $eventContext['slug']]);
        }

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
            ],
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

        if ((bool) ($data['consent_email'] ?? false)) {
            $consentService->setEmailConsent($profile, true, [
                'source_type' => 'event_public_optin',
                'source_id' => $sourceId,
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
                $bonus = (int) $awarded['points'];
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
            return redirect()->route('marketing.public.events.rewards', [
                'eventSlug' => $eventContext['slug'],
                'email' => (string) $request->query('email', ''),
                'phone' => (string) $request->query('phone', ''),
            ]);
        }
        $resolution = $this->resolveProfileFromRequest($request, 'event_reward_lookup:' . ($eventContext['slug'] ?? Str::slug($eventSlug)));
        $profile = $resolution['profile'];
        $lookupState = $resolution['state'];

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
            'availableRewards' => array_values(array_filter([
                $candleCashService->storefrontRewardPayload(
                    $candleCashService->storefrontReward(),
                    $profile ? $candleCashService->currentBalance($profile) : null
                ),
            ])),
            'redemptions' => $profile
                ? $profile->candleCashRedemptions()->with('reward:id,name,reward_type,reward_value')->orderByDesc('id')->limit(10)->get()
                    ->map(fn ($row): array => [
                        'id' => (int) $row->id,
                        'name' => $row->reward && $candleCashService->isStorefrontReward($row->reward)
                            ? 'Redeem ' . $candleCashService->fixedRedemptionFormatted() . ' Candle Cash'
                            : (string) ($row->reward?->name ?: 'Candle Cash'),
                        'status' => (string) ($row->status ?: 'issued'),
                        'candle_cash_amount' => $candleCashService->amountFromPoints((int) $row->points_spent),
                        'candle_cash_amount_formatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints((int) $row->points_spent)),
                        'redeemed_at' => optional($row->redeemed_at)->toDateTimeString(),
                        'redemption_code' => $row->redemption_code ? (string) $row->redemption_code : null,
                    ])->all()
                : [],
            'redemptionRules' => $candleCashService->redemptionRulesPayload(),
        ]);
    }

    public function rewardsLookup(Request $request, CandleCashService $candleCashService): View
    {
        $resolution = $this->resolveProfileFromRequest($request, 'reward_lookup');
        $profile = $resolution['profile'];
        $lookupState = $resolution['state'];

        [$balance, $availableRewards, $redemptions, $transactions, $latestGrowaveExternal, $latestReviewSummary, $reviewRewardStatus, $lastGrowaveSyncAt] =
            $this->rewardsLookupData($profile, $candleCashService);

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
            'latestReviewSummary' => $latestReviewSummary,
            'reviewRewardStatus' => $reviewRewardStatus,
            'lastGrowaveSyncAt' => $lastGrowaveSyncAt,
            'redeemResult' => session('redeem_result'),
            'redemptionRules' => $candleCashService->redemptionRulesPayload(),
        ]);
    }

    public function redeemRewardsLookup(Request $request, CandleCashService $candleCashService): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'reward_id' => ['required', 'integer', 'exists:candle_cash_rewards,id'],
        ]);

        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $query = ['email' => $email, 'phone' => $phone];

        $resolution = $this->resolveProfileFromRequest($request, 'reward_lookup_redeem', true);
        $profile = $resolution['profile'];
        $lookupState = $resolution['state'];

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
        $reward = $candleCashService->storefrontReward();
        if (! $reward || (int) $requestedReward->id !== (int) $reward->id) {
            return redirect()
                ->route('marketing.public.rewards-lookup', $query)
                ->with('redeem_result', [
                    'ok' => false,
                    'state' => 'reward_unavailable',
                    'message' => 'That Candle Cash redemption is not available right now.',
                ]);
        }

        $result = $candleCashService->requestStorefrontRedemption(
            profile: $profile,
            reward: $reward,
            platform: 'public_lookup',
            reuseActiveCode: true
        );

        $state = strtolower(trim((string) ($result['state'] ?? 'try_again_later')));
        $ok = (bool) ($result['ok'] ?? false);
        $message = $this->redemptionMessageForState($state);
        $eventStatus = $ok ? 'ok' : 'error';
        $eventIssue = $ok ? null : (string) ($result['error'] ?? $state);

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
                'balance' => (int) ($result['balance'] ?? 0),
            ],
            'resolution_status' => $ok ? 'resolved' : 'open',
        ]);

        return redirect()
            ->route('marketing.public.rewards-lookup', $query)
            ->with('redeem_result', [
                'ok' => $ok,
                'state' => $state,
                'message' => $message,
                'balance' => $candleCashService->balancePayloadFromPoints((int) ($result['balance'] ?? 0)),
                'reward_name' => 'Redeem ' . $candleCashService->fixedRedemptionFormatted() . ' Candle Cash',
                'redemption_code' => $ok ? (string) ($result['code'] ?? '') : null,
                'redemption_id' => $ok ? (int) ($result['redemption_id'] ?? 0) : null,
            ]);
    }

    public function showConsentConfirm(Request $request, CandleCashService $candleCashService): View
    {
        $eventSlug = trim((string) $request->query('event', ''));
        $profileId = (int) $request->query('profile', 0);
        $eventContext = $eventSlug !== '' ? $this->eventContextResolver->resolve($eventSlug) : null;
        $bonusPoints = max(0, (int) $request->query('bonus', 0));

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
            'profile' => $profileId > 0 ? MarketingProfile::query()->find($profileId) : null,
            'bonus' => $bonusPoints,
            'bonusAmount' => $candleCashService->amountFromPoints($bonusPoints),
            'bonusFormatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints($bonusPoints)),
        ]);
    }

    /**
     * @return array{profile:?MarketingProfile,state:string}
     */
    protected function resolveProfileFromRequest(Request $request, string $scope, bool $allowBody = false): array
    {
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
            phone: $rawPhone
        );

        $resolved = $this->storefrontIdentityService->resolve([
            'email' => $rawEmail,
            'phone' => $rawPhone,
        ], [
            'source_type' => $scope,
            'source_id' => $sourceId,
            'source_label' => $scope,
            'source_channels' => ['public_event'],
            'source_meta' => [
                'lookup' => true,
            ],
            'allow_create' => false,
        ]);

        return [
            'profile' => $resolved['profile'],
            'state' => $resolved['profile'] ? 'linked_customer' : ($resolved['status'] === 'review_required' ? 'needs_verification' : 'unknown_customer'),
        ];
    }

    /**
     * @return array{
     *   0:?array<string,mixed>,
     *   1:array<int,array<string,mixed>>,
     *   2:array<int,array<string,mixed>>,
     *   3:Collection<int,array<string,mixed>>,
     *   4:?CustomerExternalProfile,
     *   5:?MarketingReviewSummary,
     *   6:array{count:int,last_rewarded_at:?string},
     *   7:?string
     * }
     */
    protected function rewardsLookupData(?MarketingProfile $profile, CandleCashService $candleCashService): array
    {
        $availableRewards = array_values(array_filter([
            $candleCashService->storefrontRewardPayload($candleCashService->storefrontReward()),
        ]));

        if (! $profile) {
            return [
                null,
                $availableRewards,
                collect(),
                collect(),
                null,
                null,
                ['count' => 0, 'last_rewarded_at' => null],
                null,
            ];
        }

        $balancePoints = $candleCashService->currentBalance($profile);
        $balance = $candleCashService->balancePayloadFromPoints($balancePoints);
        $availableRewards = array_values(array_filter([
            $candleCashService->storefrontRewardPayload($candleCashService->storefrontReward(), $balancePoints),
        ]));
        $redemptions = $profile->candleCashRedemptions()
            ->with('reward:id,name,reward_type,reward_value')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => $row->reward && $candleCashService->isStorefrontReward($row->reward)
                    ? 'Redeem ' . $candleCashService->fixedRedemptionFormatted() . ' Candle Cash'
                    : (string) ($row->reward?->name ?: 'Candle Cash'),
                'status' => (string) ($row->status ?: 'issued'),
                'redemption_code' => $row->redemption_code ? (string) $row->redemption_code : null,
                'issued_at' => optional($row->issued_at)->toDateTimeString(),
                'redeemed_at' => optional($row->redeemed_at)->toDateTimeString(),
                'candle_cash_amount' => $candleCashService->amountFromPoints((int) $row->points_spent),
                'candle_cash_amount_formatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints((int) $row->points_spent)),
            ])->all();

        $transactionRows = $profile->candleCashTransactions()
            ->orderByDesc('id')
            ->limit(40)
            ->get(['id', 'type', 'points', 'source', 'source_id', 'description', 'created_at']);

        $transactions = $transactionRows->map(function (CandleCashTransaction $transaction) use ($candleCashService): array {
            return [
                'id' => (int) $transaction->id,
                'category' => $this->transactionCategoryLabel($transaction),
                'raw_points' => (int) $transaction->points,
                'candle_cash_amount' => $candleCashService->amountFromPoints((int) $transaction->points),
                'candle_cash_amount_formatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints((int) abs((int) $transaction->points))),
                'signed_candle_cash_amount_formatted' => $candleCashService->candleCashAmountLabelFromPoints((int) $transaction->points, true),
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

        $reviewRewardRows = $transactionRows
            ->filter(fn (CandleCashTransaction $row): bool => (string) $row->source === 'growave_activity'
                && $row->points > 0
                && str_contains(strtolower((string) ($row->description ?? '')), 'review'))
            ->values();

        $reviewRewardStatus = [
            'count' => $reviewRewardRows->count(),
            'last_rewarded_at' => optional($reviewRewardRows->first()?->created_at)->toDateTimeString(),
        ];

        $lastGrowaveSyncAt = collect([
            optional($latestGrowaveExternal?->synced_at)->toDateTimeString(),
            optional($latestReviewSummary?->source_synced_at)->toDateTimeString(),
        ])->filter()->max();

        return [
            $balance,
            $availableRewards,
            $redemptions,
            $transactions,
            $latestGrowaveExternal,
            $latestReviewSummary,
            $reviewRewardStatus,
            $lastGrowaveSyncAt,
        ];
    }

    protected function transactionCategoryLabel(CandleCashTransaction $transaction): string
    {
        $type = strtolower(trim((string) $transaction->type));
        $description = strtolower(trim((string) ($transaction->description ?? '')));

        if ((string) $transaction->source === 'growave_activity') {
            if (str_contains($description, '(redeem)') || $transaction->points < 0) {
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
            'code_issued' => 'Candle Cash redeemed successfully. Your $10 reward code is ready to use.',
            'already_has_active_code' => 'You already have a $10 Candle Cash reward waiting for you.',
            'insufficient_points' => 'You need a little more Candle Cash before the $10 redemption is ready.',
            'reward_unavailable' => 'This reward is currently unavailable.',
            'redemption_blocked' => 'Redemption is temporarily blocked. Please try again later.',
            default => 'Could not process redemption right now. Please try again shortly.',
        };
    }
}
