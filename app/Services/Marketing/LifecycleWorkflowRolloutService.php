<?php

namespace App\Services\Marketing;

use App\Models\MarketingAutomationEvent;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingStorefrontEvent;
use App\Models\MarketingWishlistOutreachQueue;
use App\Models\Order;
use App\Models\OrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LifecycleWorkflowRolloutService
{
    public const WORKFLOW_WELCOME = 'welcome';

    public const WORKFLOW_WINBACK = 'winback';

    public const WORKFLOW_POST_PURCHASE = 'post_purchase_cross_sell';

    public const WORKFLOW_WISHLIST = 'wishlist_triggered_offer';

    public const WORKFLOW_CART_ABANDONMENT = 'cart_abandonment';

    public const WORKFLOW_CHECKOUT_ABANDONMENT = 'checkout_abandonment';

    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $definitions = [
        self::WORKFLOW_WELCOME => [
            'label' => 'Welcome',
            'priority' => 1,
            'channel' => 'email',
            'objective' => 'welcome',
            'status_target' => 'can_ship_now',
            'launch_mode' => 'manual_first',
            'cooldown_days' => 14,
            'lookback_days' => 7,
            'recent_purchase_suppression_days' => 14,
            'success_metric' => 'Welcome-attributed first purchase rate within 14 days.',
            'campaign' => [
                'name' => 'Lifecycle · Welcome (Email)',
                'slug' => 'lifecycle-welcome-email',
                'description' => 'New subscriber/new-customer welcome sequence queued for manual approval.',
                'subject' => 'Welcome to Modern Forestry',
                'message_text' => 'Hi {{first_name}}, welcome to Modern Forestry. Shop our best sellers and bundle favorites while they are in stock: {{shop_url}}',
            ],
        ],
        self::WORKFLOW_WINBACK => [
            'label' => 'Winback',
            'priority' => 2,
            'channel' => 'email',
            'objective' => 'winback',
            'status_target' => 'can_ship_now',
            'launch_mode' => 'manual_first',
            'cooldown_days' => 30,
            'stale_days' => 75,
            'minimum_order_count' => 2,
            'success_metric' => 'Reactivated purchasers within 14 days of winback send.',
            'campaign' => [
                'name' => 'Lifecycle · Winback (Email)',
                'slug' => 'lifecycle-winback-email',
                'description' => 'Lapsed repeat buyers queued for manual winback approvals.',
                'subject' => 'We saved something you will love',
                'message_text' => 'Hi {{first_name}}, it has been a while. We just restocked top bundle combinations and customer favorites here: {{shop_url}}',
            ],
        ],
        self::WORKFLOW_POST_PURCHASE => [
            'label' => 'Post-purchase Cross-sell',
            'priority' => 3,
            'channel' => 'email',
            'objective' => 'post_purchase_cross_sell',
            'status_target' => 'can_ship_now',
            'launch_mode' => 'manual_first',
            'cooldown_days' => 21,
            'minimum_days_after_order' => 2,
            'maximum_days_after_order' => 14,
            'success_metric' => 'Second-purchase conversion within 21 days of cross-sell send.',
            'campaign' => [
                'name' => 'Lifecycle · Post Purchase Cross-sell (Email)',
                'slug' => 'lifecycle-post-purchase-email',
                'description' => 'First-time buyers queued for bundle-oriented second-purchase nudges.',
                'subject' => 'Pair your last order with this next',
                'message_text' => 'Hi {{first_name}}, thanks for your recent order. Customers who bought this also pair it with bundle-ready favorites: {{shop_url}}',
            ],
        ],
        self::WORKFLOW_WISHLIST => [
            'label' => 'Wishlist-triggered Offer',
            'priority' => 4,
            'status_target' => 'can_ship_now',
            'launch_mode' => 'manual_first',
            'success_metric' => 'Wishlist outreach redemption rate and wishlist-to-order conversion.',
        ],
        self::WORKFLOW_CART_ABANDONMENT => [
            'label' => 'Cart Abandonment',
            'priority' => 5,
            'status_target' => 'needs_small_build',
            'launch_mode' => 'manual_first',
            'success_metric' => 'Recovered carts within 24h and 72h windows.',
        ],
        self::WORKFLOW_CHECKOUT_ABANDONMENT => [
            'label' => 'Checkout Abandonment',
            'priority' => 6,
            'status_target' => 'needs_small_build',
            'launch_mode' => 'manual_first',
            'success_metric' => 'Recovered checkout starts within 24h and 72h windows.',
        ],
    ];

    public function __construct(
        protected MarketingSmsEligibilityService $smsEligibilityService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function audit(int $tenantId, ?string $storeKey = null): array
    {
        $now = now()->toImmutable();
        $orderStats = $this->onlineOrderStatsByProfile($tenantId, $storeKey);

        $welcomeCandidates = $this->welcomeCandidates($tenantId, $orderStats, $now);
        $winbackCandidates = $this->winbackCandidates($tenantId, $orderStats, $now);
        $postPurchaseCandidates = $this->postPurchaseCandidates($tenantId, $orderStats, $now);

        $wishlistSummary = $this->wishlistSummary($tenantId);
        $abandonmentReadiness = $this->abandonmentReadiness($tenantId, $storeKey, $now);

        $workflows = [
            self::WORKFLOW_WELCOME => $this->workflowAuditPayload(
                key: self::WORKFLOW_WELCOME,
                eligibleNow: $welcomeCandidates->count(),
                blockers: [],
                dependencies: ['marketing_profiles', 'marketing_consent_events', 'marketing_campaigns'],
                trigger: 'Consent confirmed in last 7 days or first online order in last 7 days.',
                suppression: 'Suppress if consent-only entrant purchased in the last 14 days; cooldown 14 days.',
                qa: [
                    'Run workflow prepare and confirm recipients queue in campaign approvals.',
                    'Verify no profile with order <14 days is queued by consent-only trigger.',
                    'Approve/send a smoke subset and verify conversions appear in campaign detail.',
                ],
                manualOrAutomated: 'manual_first'
            ),
            self::WORKFLOW_WINBACK => $this->workflowAuditPayload(
                key: self::WORKFLOW_WINBACK,
                eligibleNow: $winbackCandidates->count(),
                blockers: [],
                dependencies: ['marketing_profiles', 'marketing_profile_links', 'orders', 'marketing_campaigns'],
                trigger: 'Repeat online buyers with no order for 75+ days.',
                suppression: 'Exclude <2 orders and suppress profiles staged in the last 30 days.',
                qa: [
                    'Verify queued audience has >=2 historical online orders.',
                    'Verify no queued profile has last order within 75 days.',
                    'Check manual approval queue before any send execution.',
                ],
                manualOrAutomated: 'manual_first'
            ),
            self::WORKFLOW_POST_PURCHASE => $this->workflowAuditPayload(
                key: self::WORKFLOW_POST_PURCHASE,
                eligibleNow: $postPurchaseCandidates->count(),
                blockers: [],
                dependencies: ['marketing_profiles', 'marketing_profile_links', 'orders', 'order_lines', 'marketing_campaigns'],
                trigger: 'First-time online buyer, 2-14 days after initial purchase.',
                suppression: 'Stop when second order occurs; suppress if staged within 21 days.',
                qa: [
                    'Validate queued audience has exactly one online order.',
                    'Validate last order age is between 2 and 14 days.',
                    'Validate segment notes include inferred product family when available.',
                ],
                manualOrAutomated: 'manual_first'
            ),
            self::WORKFLOW_WISHLIST => $this->workflowAuditPayload(
                key: self::WORKFLOW_WISHLIST,
                eligibleNow: (int) ($wishlistSummary['prepared_queue'] ?? 0),
                blockers: [],
                dependencies: ['marketing_profile_wishlist_items', 'marketing_wishlist_outreach_queue', 'twilio'],
                trigger: 'Saved-item intent from native wishlist rows.',
                suppression: 'Manual queue + send status controls; suppression after send/redeem is already tracked per queue row.',
                qa: [
                    'Prepare an outreach offer from wishlist page and verify queue row status.',
                    'Send one SMS and confirm Twilio delivery id and queue status sent.',
                    'Confirm redeemed rows are not re-sent without explicit operator action.',
                ],
                manualOrAutomated: 'manual_first'
            ),
            self::WORKFLOW_CART_ABANDONMENT => $this->workflowAuditPayload(
                key: self::WORKFLOW_CART_ABANDONMENT,
                eligibleNow: 0,
                blockers: $this->cartAbandonmentBlockers($abandonmentReadiness),
                dependencies: ['marketing_storefront_events(add_to_cart + cart_token + profile)', 'purchase linkage continuity'],
                trigger: 'add_to_cart without checkout_started/purchase continuity inside holdout window.',
                suppression: 'Suppress immediately after purchase or checkout completion.',
                qa: [
                    'Verify cart_token coverage >75% for add_to_cart events.',
                    'Verify profile linkage coverage >50% for add_to_cart events.',
                    'Verify no recovery send to a token that already purchased.',
                ],
                manualOrAutomated: 'manual_first',
                fallbackStatus: $this->abandonmentStatus($abandonmentReadiness, 'cart')
            ),
            self::WORKFLOW_CHECKOUT_ABANDONMENT => $this->workflowAuditPayload(
                key: self::WORKFLOW_CHECKOUT_ABANDONMENT,
                eligibleNow: 0,
                blockers: $this->checkoutAbandonmentBlockers($abandonmentReadiness),
                dependencies: ['marketing_storefront_events(checkout_started + checkout_token)', 'purchase events with checkout linkage'],
                trigger: 'checkout_started without purchase in recovery window.',
                suppression: 'Suppress after purchase link confidence > threshold or explicit checkout completion.',
                qa: [
                    'Verify checkout_token coverage >80% for checkout_started events.',
                    'Verify purchase events carry checkout token continuity.',
                    'Verify no recovery send to checkout tokens already linked to purchase.',
                ],
                manualOrAutomated: 'manual_first',
                fallbackStatus: $this->abandonmentStatus($abandonmentReadiness, 'checkout')
            ),
        ];

        return [
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'evaluated_at' => $now->toIso8601String(),
            'launch_order' => [
                self::WORKFLOW_WELCOME,
                self::WORKFLOW_WINBACK,
                self::WORKFLOW_POST_PURCHASE,
                self::WORKFLOW_WISHLIST,
                self::WORKFLOW_CART_ABANDONMENT,
                self::WORKFLOW_CHECKOUT_ABANDONMENT,
            ],
            'workflows' => $workflows,
            'supporting_metrics' => [
                'wishlist' => $wishlistSummary,
                'abandonment_readiness' => $abandonmentReadiness,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function stageWorkflow(string $workflowKey, int $tenantId, ?string $storeKey = null, array $options = []): array
    {
        $workflowKey = strtolower(trim($workflowKey));
        if (! in_array($workflowKey, [self::WORKFLOW_WELCOME, self::WORKFLOW_WINBACK, self::WORKFLOW_POST_PURCHASE], true)) {
            return [
                'status' => 'unsupported_workflow',
                'workflow' => $workflowKey,
                'message' => 'Only welcome, winback, and post-purchase cross-sell are stageable in this phase.',
            ];
        }

        $definition = $this->definitions[$workflowKey];
        $now = now()->toImmutable();
        $limit = max(1, min(1000, (int) ($options['limit'] ?? 400)));
        $actorId = $this->positiveInt($options['actor_id'] ?? null);

        $orderStats = $this->onlineOrderStatsByProfile($tenantId, $storeKey);
        $candidates = match ($workflowKey) {
            self::WORKFLOW_WELCOME => $this->welcomeCandidates($tenantId, $orderStats, $now),
            self::WORKFLOW_WINBACK => $this->winbackCandidates($tenantId, $orderStats, $now),
            self::WORKFLOW_POST_PURCHASE => $this->postPurchaseCandidates($tenantId, $orderStats, $now),
            default => collect(),
        };

        if ($candidates->isEmpty()) {
            return [
                'status' => 'no_candidates',
                'workflow' => $workflowKey,
                'campaign_id' => null,
                'queued_for_approval' => 0,
                'skipped' => 0,
                'suppressed' => 0,
                'cooldown_suppressed' => 0,
                'eligible_candidates' => 0,
            ];
        }

        $candidateRows = $candidates
            ->sortByDesc(fn (array $row): int => ($row['trigger_at'] instanceof CarbonImmutable ? $row['trigger_at']->timestamp : 0))
            ->take($limit)
            ->values();

        $profileIds = $candidateRows
            ->pluck('profile_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        if ($profileIds->isEmpty()) {
            return [
                'status' => 'no_candidates',
                'workflow' => $workflowKey,
                'campaign_id' => null,
                'queued_for_approval' => 0,
                'skipped' => 0,
                'suppressed' => 0,
                'cooldown_suppressed' => 0,
                'eligible_candidates' => 0,
            ];
        }

        $profiles = MarketingProfile::query()
            ->forTenantId($tenantId)
            ->whereIn('id', $profileIds->all())
            ->get([
                'id',
                'tenant_id',
                'first_name',
                'last_name',
                'email',
                'normalized_email',
                'phone',
                'normalized_phone',
                'accepts_email_marketing',
                'accepts_sms_marketing',
            ])
            ->keyBy('id');

        if ($profiles->isEmpty()) {
            return [
                'status' => 'no_candidates',
                'workflow' => $workflowKey,
                'campaign_id' => null,
                'queued_for_approval' => 0,
                'skipped' => 0,
                'suppressed' => 0,
                'cooldown_suppressed' => 0,
                'eligible_candidates' => 0,
            ];
        }

        $latestWorkflowEvents = $this->latestWorkflowEventByProfile(
            tenantId: $tenantId,
            triggerKey: $workflowKey,
            channel: (string) ($definition['channel'] ?? 'email'),
            profileIds: $profiles->keys()->map(fn ($id): int => (int) $id)->values()
        );

        $campaign = $this->ensureWorkflowCampaign($workflowKey, $tenantId, $storeKey, $actorId);
        $variant = $this->ensureWorkflowVariant($campaign, $workflowKey);

        $existingRecipients = MarketingCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('marketing_profile_id', $profiles->keys()->all())
            ->get(['id', 'marketing_profile_id', 'status'])
            ->keyBy('marketing_profile_id');

        $smsEligibility = ((string) ($definition['channel'] ?? 'email')) === 'sms'
            ? $this->smsEligibilityService->evaluateProfiles($profiles->values(), $tenantId)
            : collect();

        $summary = [
            'processed' => 0,
            'queued_for_approval' => 0,
            'skipped' => 0,
            'suppressed' => 0,
            'cooldown_suppressed' => 0,
            'existing_active' => 0,
        ];

        foreach ($candidateRows as $row) {
            $profileId = (int) ($row['profile_id'] ?? 0);
            if ($profileId <= 0) {
                continue;
            }

            /** @var MarketingProfile|null $profile */
            $profile = $profiles->get($profileId);
            if (! $profile) {
                continue;
            }

            $summary['processed']++;

            $cooldownDays = max(1, (int) ($definition['cooldown_days'] ?? 14));
            $latestEvent = $latestWorkflowEvents[$profileId] ?? null;
            if ($latestEvent instanceof MarketingAutomationEvent
                && $latestEvent->occurred_at
                && $latestEvent->occurred_at->greaterThanOrEqualTo($now->subDays($cooldownDays))) {
                $summary['suppressed']++;
                $summary['cooldown_suppressed']++;
                $this->recordWorkflowEvent(
                    tenantId: $tenantId,
                    profileId: $profileId,
                    workflowKey: $workflowKey,
                    channel: (string) ($definition['channel'] ?? 'email'),
                    status: 'suppressed',
                    storeKey: $storeKey,
                    reason: 'cooldown_active',
                    context: ['cooldown_days' => $cooldownDays],
                    occurredAt: $now
                );

                continue;
            }

            $suppressionReason = $this->workflowSuppressionReason($workflowKey, $row, $orderStats, $now);
            if ($suppressionReason !== null) {
                $summary['suppressed']++;
                $this->recordWorkflowEvent(
                    tenantId: $tenantId,
                    profileId: $profileId,
                    workflowKey: $workflowKey,
                    channel: (string) ($definition['channel'] ?? 'email'),
                    status: 'suppressed',
                    storeKey: $storeKey,
                    reason: $suppressionReason,
                    context: (array) ($row['context'] ?? []),
                    occurredAt: $now
                );

                continue;
            }

            $existing = $existingRecipients->get($profileId);
            if ($existing instanceof MarketingCampaignRecipient
                && in_array((string) $existing->status, ['queued_for_approval', 'approved', 'sending', 'sent', 'delivered', 'converted'], true)) {
                $summary['existing_active']++;
                continue;
            }

            $eligibility = $this->channelEligibility(
                profile: $profile,
                channel: (string) ($definition['channel'] ?? 'email'),
                smsEligibility: (array) ($smsEligibility->get($profileId) ?? [])
            );

            $recipientStatus = (bool) ($eligibility['eligible'] ?? false) ? 'queued_for_approval' : 'skipped';
            MarketingCampaignRecipient::query()->updateOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'marketing_profile_id' => $profileId,
                ],
                [
                    'segment_snapshot' => [
                        'workflow_key' => $workflowKey,
                        'triggered_at' => ($row['trigger_at'] instanceof CarbonImmutable)
                            ? $row['trigger_at']->toIso8601String()
                            : $now->toIso8601String(),
                        'trigger_reasons' => (array) ($row['reason_codes'] ?? []),
                        'workflow_context' => (array) ($row['context'] ?? []),
                    ],
                    'recommendation_snapshot' => [
                        'workflow' => $workflowKey,
                        'priority' => (int) ($definition['priority'] ?? 0),
                        'eligibility' => $eligibility,
                    ],
                    'variant_id' => $variant?->id,
                    'channel' => (string) ($definition['channel'] ?? 'email'),
                    'status' => $recipientStatus,
                    'reason_codes' => array_values(array_unique(array_filter([
                        'workflow_' . $workflowKey,
                        ...((array) ($row['reason_codes'] ?? [])),
                        ...((array) ($eligibility['reason_codes'] ?? [])),
                    ]))),
                    'last_status_note' => $recipientStatus === 'skipped'
                        ? ((string) ($eligibility['blocking_reason'] ?? 'channel_ineligible'))
                        : null,
                ]
            );

            if ($recipientStatus === 'queued_for_approval') {
                $summary['queued_for_approval']++;
                $this->recordWorkflowEvent(
                    tenantId: $tenantId,
                    profileId: $profileId,
                    workflowKey: $workflowKey,
                    channel: (string) ($definition['channel'] ?? 'email'),
                    status: 'campaign_queued',
                    storeKey: $storeKey,
                    reason: 'queued_for_approval',
                    context: [
                        'campaign_id' => (int) $campaign->id,
                        'campaign_recipient_status' => 'queued_for_approval',
                        ...((array) ($row['context'] ?? [])),
                    ],
                    occurredAt: $now,
                    processedAt: $now
                );
            } else {
                $summary['skipped']++;
                $this->recordWorkflowEvent(
                    tenantId: $tenantId,
                    profileId: $profileId,
                    workflowKey: $workflowKey,
                    channel: (string) ($definition['channel'] ?? 'email'),
                    status: 'skipped',
                    storeKey: $storeKey,
                    reason: (string) ($eligibility['blocking_reason'] ?? 'channel_ineligible'),
                    context: [
                        'reason_codes' => (array) ($eligibility['reason_codes'] ?? []),
                    ],
                    occurredAt: $now,
                    processedAt: $now
                );
            }
        }

        $campaign->forceFill([
            'status' => 'ready_for_review',
            'updated_by' => $actorId,
            'updated_at' => now(),
        ])->save();

        return [
            'status' => 'ok',
            'workflow' => $workflowKey,
            'campaign_id' => (int) $campaign->id,
            'campaign_slug' => (string) $campaign->slug,
            'queued_for_approval' => (int) $summary['queued_for_approval'],
            'skipped' => (int) $summary['skipped'],
            'suppressed' => (int) $summary['suppressed'],
            'cooldown_suppressed' => (int) $summary['cooldown_suppressed'],
            'existing_active' => (int) $summary['existing_active'],
            'processed' => (int) $summary['processed'],
            'eligible_candidates' => (int) $candidateRows->count(),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $orderStats
     * @return Collection<int,array<string,mixed>>
     */
    protected function welcomeCandidates(int $tenantId, array $orderStats, CarbonImmutable $now): Collection
    {
        $definition = $this->definitions[self::WORKFLOW_WELCOME];
        $lookbackDays = max(1, (int) ($definition['lookback_days'] ?? 7));
        $cutoff = $now->subDays($lookbackDays);

        $consentEvents = MarketingConsentEvent::query()
            ->forTenantId($tenantId)
            ->whereIn('event_type', ['opted_in', 'confirmed'])
            ->whereIn('channel', ['email', 'sms'])
            ->where('occurred_at', '>=', $cutoff)
            ->orderByDesc('occurred_at')
            ->get(['marketing_profile_id', 'channel', 'event_type', 'occurred_at'])
            ->groupBy('marketing_profile_id')
            ->map(function (Collection $rows): array {
                $latest = $rows->sortByDesc(fn (MarketingConsentEvent $event): int => $event->occurred_at?->timestamp ?? 0)->first();

                return [
                    'has_recent_consent' => $latest instanceof MarketingConsentEvent,
                    'latest_consent_at' => $latest?->occurred_at,
                    'latest_consent_channel' => $latest?->channel,
                    'latest_consent_type' => $latest?->event_type,
                ];
            });

        $candidates = collect();
        $profileIds = collect(array_keys($orderStats))
            ->merge($consentEvents->keys())
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        foreach ($profileIds as $profileId) {
            $order = (array) ($orderStats[$profileId] ?? []);
            $consent = (array) ($consentEvents->get($profileId) ?? []);

            $firstOrderAt = $order['first_order_at'] ?? null;
            $lastOrderAt = $order['last_order_at'] ?? null;
            $firstOrderRecent = $firstOrderAt instanceof CarbonImmutable && $firstOrderAt->greaterThanOrEqualTo($cutoff);
            $hasRecentConsent = (bool) ($consent['has_recent_consent'] ?? false);

            if (! $firstOrderRecent && ! $hasRecentConsent) {
                continue;
            }

            $latestConsentAt = $consent['latest_consent_at'] ?? null;
            $triggerAt = $latestConsentAt instanceof CarbonImmutable
                ? $latestConsentAt
                : null;
            if ($firstOrderRecent && ($triggerAt === null || $firstOrderAt->greaterThan($triggerAt))) {
                $triggerAt = $firstOrderAt;
            }

            $reasonCodes = [];
            if ($hasRecentConsent) {
                $reasonCodes[] = 'recent_consent';
            }
            if ($firstOrderRecent) {
                $reasonCodes[] = 'first_order_recent';
            }

            $candidates->push([
                'profile_id' => $profileId,
                'trigger_at' => $triggerAt ?? $now,
                'reason_codes' => $reasonCodes,
                'context' => [
                    'recent_consent' => $hasRecentConsent,
                    'latest_consent_channel' => $consent['latest_consent_channel'] ?? null,
                    'latest_consent_type' => $consent['latest_consent_type'] ?? null,
                    'first_order_at' => $firstOrderAt?->toIso8601String(),
                    'last_order_at' => $lastOrderAt?->toIso8601String(),
                    'order_count' => (int) ($order['order_count'] ?? 0),
                ],
            ]);
        }

        return $candidates
            ->sortByDesc(fn (array $row): int => ($row['trigger_at'] instanceof CarbonImmutable ? $row['trigger_at']->timestamp : 0))
            ->values();
    }

    /**
     * @param array<int,array<string,mixed>> $orderStats
     * @return Collection<int,array<string,mixed>>
     */
    protected function winbackCandidates(int $tenantId, array $orderStats, CarbonImmutable $now): Collection
    {
        $definition = $this->definitions[self::WORKFLOW_WINBACK];
        $staleDays = max(1, (int) ($definition['stale_days'] ?? 75));
        $minimumOrders = max(1, (int) ($definition['minimum_order_count'] ?? 2));
        $cutoff = $now->subDays($staleDays);

        return collect($orderStats)
            ->map(function (array $stats, int $profileId) use ($minimumOrders, $cutoff, $staleDays): ?array {
                $orderCount = (int) ($stats['order_count'] ?? 0);
                $lastOrderAt = $stats['last_order_at'] ?? null;
                if ($orderCount < $minimumOrders || ! $lastOrderAt instanceof CarbonImmutable) {
                    return null;
                }

                if ($lastOrderAt->greaterThan($cutoff)) {
                    return null;
                }

                return [
                    'profile_id' => $profileId,
                    'trigger_at' => $lastOrderAt,
                    'reason_codes' => ['repeat_customer_lapsed_' . $cutoff->diffInDays($lastOrderAt) . 'd'],
                    'context' => [
                        'order_count' => $orderCount,
                        'first_order_at' => ($stats['first_order_at'] ?? null)?->toIso8601String(),
                        'last_order_at' => $lastOrderAt->toIso8601String(),
                        'stale_days_threshold' => $staleDays,
                    ],
                ];
            })
            ->filter(fn (?array $row): bool => is_array($row))
            ->sortBy(fn (array $row): int => ($row['trigger_at'] instanceof CarbonImmutable ? $row['trigger_at']->timestamp : PHP_INT_MAX))
            ->values();
    }

    /**
     * @param array<int,array<string,mixed>> $orderStats
     * @return Collection<int,array<string,mixed>>
     */
    protected function postPurchaseCandidates(int $tenantId, array $orderStats, CarbonImmutable $now): Collection
    {
        $definition = $this->definitions[self::WORKFLOW_POST_PURCHASE];
        $minimumDays = max(1, (int) ($definition['minimum_days_after_order'] ?? 2));
        $maximumDays = max($minimumDays, (int) ($definition['maximum_days_after_order'] ?? 14));

        $candidateOrderIds = collect($orderStats)
            ->filter(function (array $stats) use ($now, $minimumDays, $maximumDays): bool {
                $orderCount = (int) ($stats['order_count'] ?? 0);
                $lastOrderAt = $stats['last_order_at'] ?? null;
                if ($orderCount !== 1 || ! $lastOrderAt instanceof CarbonImmutable) {
                    return false;
                }

                $ageDays = $lastOrderAt->diffInDays($now);

                return $ageDays >= $minimumDays && $ageDays <= $maximumDays;
            })
            ->pluck('last_order_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        $familyByOrderId = $this->productFamilyByOrderId($candidateOrderIds);

        return collect($orderStats)
            ->map(function (array $stats, int $profileId) use ($now, $minimumDays, $maximumDays, $familyByOrderId): ?array {
                $orderCount = (int) ($stats['order_count'] ?? 0);
                $lastOrderAt = $stats['last_order_at'] ?? null;
                $lastOrderId = (int) ($stats['last_order_id'] ?? 0);
                if ($orderCount !== 1 || ! $lastOrderAt instanceof CarbonImmutable || $lastOrderId <= 0) {
                    return null;
                }

                $ageDays = $lastOrderAt->diffInDays($now);
                if ($ageDays < $minimumDays || $ageDays > $maximumDays) {
                    return null;
                }

                $family = (string) ($familyByOrderId[$lastOrderId] ?? 'unknown');

                return [
                    'profile_id' => $profileId,
                    'trigger_at' => $lastOrderAt,
                    'reason_codes' => ['first_purchase_cross_sell', 'family_' . $family],
                    'context' => [
                        'order_count' => $orderCount,
                        'last_order_id' => $lastOrderId,
                        'last_order_at' => $lastOrderAt->toIso8601String(),
                        'days_since_order' => $ageDays,
                        'product_family' => $family,
                    ],
                ];
            })
            ->filter(fn (?array $row): bool => is_array($row))
            ->sortByDesc(fn (array $row): int => ($row['trigger_at'] instanceof CarbonImmutable ? $row['trigger_at']->timestamp : 0))
            ->values();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function onlineOrderStatsByProfile(int $tenantId, ?string $storeKey = null): array
    {
        if (! Schema::hasTable('marketing_profile_links') || ! Schema::hasTable('orders')) {
            return [];
        }

        $links = MarketingProfileLink::query()
            ->forTenantId($tenantId)
            ->where('source_type', 'order')
            ->get(['marketing_profile_id', 'source_id']);

        if ($links->isEmpty()) {
            return [];
        }

        $orderIds = $links
            ->pluck('source_id')
            ->map(fn ($value): ?int => $this->numericStringToInt($value))
            ->filter(fn (?int $value): bool => $value !== null && $value > 0)
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            return [];
        }

        $orders = Order::query()
            ->whereIn('id', $orderIds->all())
            ->get([
                'id',
                'tenant_id',
                'source',
                'order_type',
                'shopify_store',
                'shopify_store_key',
                'shopify_order_id',
                'ordered_at',
                'total_price',
            ])
            ->keyBy('id');

        $stats = [];
        foreach ($links as $link) {
            $profileId = (int) ($link->marketing_profile_id ?? 0);
            $orderId = $this->numericStringToInt($link->source_id);
            if ($profileId <= 0 || $orderId === null || $orderId <= 0) {
                continue;
            }

            /** @var Order|null $order */
            $order = $orders->get($orderId);
            if (! $order || ! $this->isOnlineOrder($order, $tenantId, $storeKey)) {
                continue;
            }

            $orderedAt = $this->asCarbonImmutable($order->ordered_at) ?? $this->asCarbonImmutable($order->created_at);
            if (! $orderedAt instanceof CarbonImmutable) {
                continue;
            }

            if (! isset($stats[$profileId])) {
                $stats[$profileId] = [
                    'order_count' => 0,
                    'first_order_at' => $orderedAt,
                    'last_order_at' => $orderedAt,
                    'last_order_id' => $orderId,
                    'last_order_total' => (float) ($order->total_price ?? 0),
                ];
            }

            $stats[$profileId]['order_count'] = (int) $stats[$profileId]['order_count'] + 1;

            /** @var CarbonImmutable $first */
            $first = $stats[$profileId]['first_order_at'];
            /** @var CarbonImmutable $last */
            $last = $stats[$profileId]['last_order_at'];

            if ($orderedAt->lessThan($first)) {
                $stats[$profileId]['first_order_at'] = $orderedAt;
            }

            if ($orderedAt->greaterThan($last)) {
                $stats[$profileId]['last_order_at'] = $orderedAt;
                $stats[$profileId]['last_order_id'] = $orderId;
                $stats[$profileId]['last_order_total'] = (float) ($order->total_price ?? 0);
            }
        }

        return $stats;
    }

    protected function isOnlineOrder(Order $order, int $tenantId, ?string $storeKey): bool
    {
        if ((int) ($order->tenant_id ?? 0) !== $tenantId) {
            return false;
        }

        if ($storeKey !== null) {
            $orderStoreKey = strtolower(trim((string) ($order->shopify_store_key ?: $order->shopify_store ?: '')));
            if ($orderStoreKey === '' || $orderStoreKey !== strtolower($storeKey)) {
                return false;
            }
        }

        $orderType = strtolower(trim((string) ($order->order_type ?? '')));
        if (in_array($orderType, ['wholesale', 'event'], true)) {
            return false;
        }

        if (trim((string) ($order->shopify_order_id ?? '')) !== '') {
            return true;
        }

        $source = strtolower(trim((string) ($order->source ?? '')));

        return str_contains($source, 'shopify') || str_contains($source, 'online');
    }

    /**
     * @param Collection<int,int> $orderIds
     * @return array<int,string>
     */
    protected function productFamilyByOrderId(Collection $orderIds): array
    {
        if ($orderIds->isEmpty() || ! Schema::hasTable('order_lines')) {
            return [];
        }

        $rows = OrderLine::query()
            ->whereIn('order_id', $orderIds->all())
            ->get(['order_id', 'raw_title', 'sku']);

        $families = [];
        foreach ($rows as $row) {
            $orderId = (int) ($row->order_id ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $family = $this->inferProductFamily((string) ($row->raw_title ?? ''), (string) ($row->sku ?? ''));
            if (! isset($families[$orderId])) {
                $families[$orderId] = [];
            }
            $families[$orderId][$family] = (int) ($families[$orderId][$family] ?? 0) + 1;
        }

        $resolved = [];
        foreach ($families as $orderId => $counts) {
            arsort($counts);
            $resolved[(int) $orderId] = (string) array_key_first($counts);
        }

        return $resolved;
    }

    protected function inferProductFamily(string $title, string $sku): string
    {
        $haystack = strtolower(trim($title . ' ' . $sku));
        if ($haystack === '') {
            return 'unknown';
        }

        if (str_contains($haystack, 'bundle') || str_contains($haystack, 'set') || str_contains($haystack, 'kit')) {
            return 'bundle';
        }

        if (str_contains($haystack, 'wax melt') || str_contains($haystack, 'melt')) {
            return 'wax_melt';
        }

        if (str_contains($haystack, 'diffuser')) {
            return 'diffuser';
        }

        if (str_contains($haystack, 'spray')) {
            return 'spray';
        }

        if (str_contains($haystack, 'candle')) {
            return 'candle';
        }

        return 'other';
    }

    protected function workflowSuppressionReason(string $workflowKey, array $candidate, array $orderStats, CarbonImmutable $now): ?string
    {
        $profileId = (int) ($candidate['profile_id'] ?? 0);
        $stats = (array) ($orderStats[$profileId] ?? []);
        $lastOrderAt = $stats['last_order_at'] ?? null;

        if ($workflowKey === self::WORKFLOW_WELCOME) {
            $context = (array) ($candidate['context'] ?? []);
            $recentPurchaseWindow = max(1, (int) ($this->definitions[self::WORKFLOW_WELCOME]['recent_purchase_suppression_days'] ?? 14));
            $hasRecentConsent = (bool) ($context['recent_consent'] ?? false);
            $hasFirstOrderTrigger = in_array('first_order_recent', (array) ($candidate['reason_codes'] ?? []), true);

            if ($hasRecentConsent && ! $hasFirstOrderTrigger
                && $lastOrderAt instanceof CarbonImmutable
                && $lastOrderAt->greaterThanOrEqualTo($now->subDays($recentPurchaseWindow))) {
                return 'recent_purchase_suppression';
            }

            return null;
        }

        if ($workflowKey === self::WORKFLOW_WINBACK) {
            $staleDays = max(1, (int) ($this->definitions[self::WORKFLOW_WINBACK]['stale_days'] ?? 75));
            if ($lastOrderAt instanceof CarbonImmutable && $lastOrderAt->greaterThan($now->subDays($staleDays))) {
                return 'not_stale_enough';
            }

            return null;
        }

        if ($workflowKey === self::WORKFLOW_POST_PURCHASE) {
            if ((int) ($stats['order_count'] ?? 0) > 1) {
                return 'already_repeat_buyer';
            }

            return null;
        }

        return null;
    }

    protected function ensureWorkflowCampaign(string $workflowKey, int $tenantId, ?string $storeKey, ?int $actorId): MarketingCampaign
    {
        $definition = $this->definitions[$workflowKey];
        $campaignDefinition = (array) ($definition['campaign'] ?? []);
        $channel = (string) ($definition['channel'] ?? 'email');

        $slugParts = [
            trim((string) ($campaignDefinition['slug'] ?? ('lifecycle-' . $workflowKey))),
            't' . $tenantId,
            $channel,
        ];
        if ($storeKey !== null) {
            $slugParts[] = Str::slug($storeKey);
        }

        $slug = Str::slug(implode('-', array_filter($slugParts)));

        return MarketingCampaign::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'slug' => $slug,
            ],
            [
                'store_key' => $storeKey,
                'name' => (string) ($campaignDefinition['name'] ?? Str::headline($workflowKey)),
                'description' => (string) ($campaignDefinition['description'] ?? 'Lifecycle workflow campaign.'),
                'status' => 'ready_for_review',
                'channel' => $channel,
                'source_label' => 'lifecycle_workflow',
                'message_subject' => (string) ($campaignDefinition['subject'] ?? 'Message from Modern Forestry'),
                'message_body' => (string) ($campaignDefinition['message_text'] ?? ''),
                'objective' => (string) ($definition['objective'] ?? 'retention'),
                'attribution_window_days' => 14,
                'send_window_json' => ['start' => '10:00', 'end' => '18:00'],
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]
        );
    }

    protected function ensureWorkflowVariant(MarketingCampaign $campaign, string $workflowKey): ?MarketingCampaignVariant
    {
        $campaignDefinition = (array) (($this->definitions[$workflowKey] ?? [])['campaign'] ?? []);
        $messageText = trim((string) ($campaignDefinition['message_text'] ?? ''));
        if ($messageText === '') {
            return null;
        }

        /** @var MarketingCampaignVariant $variant */
        $variant = MarketingCampaignVariant::query()->updateOrCreate(
            [
                'campaign_id' => $campaign->id,
                'variant_key' => 'A',
            ],
            [
                'name' => 'Default workflow message',
                'message_text' => $messageText,
                'weight' => 100,
                'is_control' => true,
                'status' => 'active',
            ]
        );

        return $variant;
    }

    /**
     * @param Collection<int,int> $profileIds
     * @return array<int,MarketingAutomationEvent>
     */
    protected function latestWorkflowEventByProfile(int $tenantId, string $triggerKey, string $channel, Collection $profileIds): array
    {
        if ($profileIds->isEmpty()) {
            return [];
        }

        $rows = MarketingAutomationEvent::query()
            ->forTenantId($tenantId)
            ->where('trigger_key', $triggerKey)
            ->where('channel', $channel)
            ->whereIn('marketing_profile_id', $profileIds->all())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $latest = [];
        foreach ($rows as $row) {
            $profileId = (int) ($row->marketing_profile_id ?? 0);
            if ($profileId <= 0 || isset($latest[$profileId])) {
                continue;
            }
            $latest[$profileId] = $row;
        }

        return $latest;
    }

    /**
     * @param array<string,mixed> $context
     */
    protected function recordWorkflowEvent(
        int $tenantId,
        int $profileId,
        string $workflowKey,
        string $channel,
        string $status,
        ?string $storeKey,
        string $reason,
        array $context,
        CarbonImmutable $occurredAt,
        ?CarbonImmutable $processedAt = null
    ): void {
        $duplicate = MarketingAutomationEvent::query()
            ->forTenantId($tenantId)
            ->where('marketing_profile_id', $profileId)
            ->where('trigger_key', $workflowKey)
            ->where('channel', $channel)
            ->where('status', $status)
            ->where('occurred_at', '>=', $occurredAt->subHours(12))
            ->exists();

        if ($duplicate) {
            return;
        }

        MarketingAutomationEvent::query()->create([
            'tenant_id' => $tenantId,
            'marketing_profile_id' => $profileId,
            'trigger_key' => $workflowKey,
            'channel' => $channel,
            'status' => $status,
            'store_key' => $storeKey,
            'reason' => $reason,
            'context' => $context,
            'occurred_at' => $occurredAt,
            'processed_at' => $processedAt,
        ]);
    }

    /**
     * @param array<string,mixed> $smsEligibility
     * @return array<string,mixed>
     */
    protected function channelEligibility(MarketingProfile $profile, string $channel, array $smsEligibility = []): array
    {
        $channel = strtolower(trim($channel));

        if ($channel === 'sms') {
            if ($smsEligibility !== []) {
                return [
                    'eligible' => (bool) ($smsEligibility['eligible'] ?? false),
                    'reason_codes' => (array) ($smsEligibility['reason_codes'] ?? []),
                    'blocking_reason' => $smsEligibility['blocking_reason'] ?? null,
                ];
            }

            $reasonCodes = [];
            if (! (bool) $profile->accepts_sms_marketing) {
                $reasonCodes[] = 'sms_not_consented';
            }
            if (trim((string) ($profile->normalized_phone ?? '')) === '') {
                $reasonCodes[] = 'missing_phone';
            }

            return [
                'eligible' => $reasonCodes === [],
                'reason_codes' => $reasonCodes,
                'blocking_reason' => $reasonCodes[0] ?? null,
            ];
        }

        $reasonCodes = [];
        if (! (bool) $profile->accepts_email_marketing) {
            $reasonCodes[] = 'email_not_consented';
        }
        if (trim((string) ($profile->normalized_email ?? '')) === '') {
            $reasonCodes[] = 'missing_email';
        }

        return [
            'eligible' => $reasonCodes === [],
            'reason_codes' => $reasonCodes,
            'blocking_reason' => $reasonCodes[0] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function wishlistSummary(int $tenantId): array
    {
        if (! Schema::hasTable('marketing_wishlist_outreach_queue')) {
            return [
                'prepared_queue' => 0,
                'sent_queue' => 0,
                'failed_queue' => 0,
            ];
        }

        return [
            'prepared_queue' => (int) MarketingWishlistOutreachQueue::query()
                ->forTenantId($tenantId)
                ->where('queue_status', MarketingWishlistOutreachQueue::STATUS_PREPARED)
                ->count(),
            'sent_queue' => (int) MarketingWishlistOutreachQueue::query()
                ->forTenantId($tenantId)
                ->where('queue_status', MarketingWishlistOutreachQueue::STATUS_SENT)
                ->count(),
            'failed_queue' => (int) MarketingWishlistOutreachQueue::query()
                ->forTenantId($tenantId)
                ->where('queue_status', MarketingWishlistOutreachQueue::STATUS_FAILED)
                ->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function abandonmentReadiness(int $tenantId, ?string $storeKey, CarbonImmutable $now): array
    {
        if (! Schema::hasTable('marketing_storefront_events')) {
            return [
                'window_days' => 30,
                'add_to_cart_total' => 0,
                'add_to_cart_cart_token_coverage' => 0.0,
                'add_to_cart_profile_coverage' => 0.0,
                'checkout_started_total' => 0,
                'checkout_started_token_coverage' => 0.0,
                'checkout_started_profile_coverage' => 0.0,
                'purchase_total' => 0,
                'purchase_checkout_token_coverage' => 0.0,
                'purchase_linked_event_coverage' => 0.0,
            ];
        }

        $from = $now->subDays(30);

        $funnelEvents = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('source_type', 'shopify_storefront_funnel')
            ->whereIn('event_type', ['add_to_cart', 'checkout_started'])
            ->where('occurred_at', '>=', $from)
            ->get(['id', 'event_type', 'marketing_profile_id', 'meta']);

        $purchaseEvents = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('source_type', 'shopify_storefront_purchase')
            ->where('event_type', 'purchase')
            ->where('occurred_at', '>=', $from)
            ->get(['id', 'meta']);

        $addToCart = $funnelEvents->where('event_type', 'add_to_cart')->values();
        $checkoutStarts = $funnelEvents->where('event_type', 'checkout_started')->values();

        $storeKeyFilter = $storeKey !== null ? strtolower($storeKey) : null;

        $filterByStore = function (Collection $rows) use ($storeKeyFilter): Collection {
            if ($storeKeyFilter === null) {
                return $rows;
            }

            return $rows->filter(function ($row) use ($storeKeyFilter): bool {
                $meta = is_array($row->meta ?? null) ? $row->meta : [];
                $eventStoreKey = strtolower(trim((string) ($meta['store_key'] ?? '')));

                return $eventStoreKey !== '' && $eventStoreKey === $storeKeyFilter;
            })->values();
        };

        $addToCart = $filterByStore($addToCart);
        $checkoutStarts = $filterByStore($checkoutStarts);
        $purchaseEvents = $filterByStore($purchaseEvents);

        $addToCartWithToken = $addToCart->filter(function (MarketingStorefrontEvent $event): bool {
            $meta = is_array($event->meta ?? null) ? $event->meta : [];

            return trim((string) ($meta['cart_token'] ?? '')) !== '';
        })->count();

        $addToCartWithProfile = $addToCart->filter(fn (MarketingStorefrontEvent $event): bool => (int) ($event->marketing_profile_id ?? 0) > 0)->count();

        $checkoutWithToken = $checkoutStarts->filter(function (MarketingStorefrontEvent $event): bool {
            $meta = is_array($event->meta ?? null) ? $event->meta : [];

            return trim((string) ($meta['checkout_token'] ?? '')) !== '';
        })->count();

        $checkoutWithProfile = $checkoutStarts->filter(fn (MarketingStorefrontEvent $event): bool => (int) ($event->marketing_profile_id ?? 0) > 0)->count();

        $purchaseWithCheckoutToken = $purchaseEvents->filter(function (MarketingStorefrontEvent $event): bool {
            $meta = is_array($event->meta ?? null) ? $event->meta : [];

            return trim((string) ($meta['checkout_token'] ?? '')) !== '';
        })->count();

        $purchaseWithLinkedEvent = $purchaseEvents->filter(function (MarketingStorefrontEvent $event): bool {
            $meta = is_array($event->meta ?? null) ? $event->meta : [];

            return (int) ($meta['linked_storefront_event_id'] ?? 0) > 0;
        })->count();

        return [
            'window_days' => 30,
            'add_to_cart_total' => $addToCart->count(),
            'add_to_cart_cart_token_coverage' => $this->percent($addToCartWithToken, $addToCart->count()),
            'add_to_cart_profile_coverage' => $this->percent($addToCartWithProfile, $addToCart->count()),
            'checkout_started_total' => $checkoutStarts->count(),
            'checkout_started_token_coverage' => $this->percent($checkoutWithToken, $checkoutStarts->count()),
            'checkout_started_profile_coverage' => $this->percent($checkoutWithProfile, $checkoutStarts->count()),
            'purchase_total' => $purchaseEvents->count(),
            'purchase_checkout_token_coverage' => $this->percent($purchaseWithCheckoutToken, $purchaseEvents->count()),
            'purchase_linked_event_coverage' => $this->percent($purchaseWithLinkedEvent, $purchaseEvents->count()),
        ];
    }

    /**
     * @return array<int,string>
     */
    protected function cartAbandonmentBlockers(array $readiness): array
    {
        $blockers = [];

        if ((int) ($readiness['add_to_cart_total'] ?? 0) < 30) {
            $blockers[] = 'Not enough add_to_cart volume in the last 30 days to trust recovery automation.';
        }

        if ((float) ($readiness['add_to_cart_cart_token_coverage'] ?? 0) < 75.0) {
            $blockers[] = 'cart_token coverage for add_to_cart is below 75%.';
        }

        if ((float) ($readiness['add_to_cart_profile_coverage'] ?? 0) < 50.0) {
            $blockers[] = 'profile linkage coverage for add_to_cart is below 50%.';
        }

        if ((float) ($readiness['purchase_linked_event_coverage'] ?? 0) < 60.0) {
            $blockers[] = 'purchase linkage to storefront events is still below 60%.';
        }

        return $blockers;
    }

    /**
     * @return array<int,string>
     */
    protected function checkoutAbandonmentBlockers(array $readiness): array
    {
        $blockers = [];

        if ((int) ($readiness['checkout_started_total'] ?? 0) < 25) {
            $blockers[] = 'Not enough checkout_started volume in the last 30 days to run recovery safely.';
        }

        if ((float) ($readiness['checkout_started_token_coverage'] ?? 0) < 80.0) {
            $blockers[] = 'checkout_token coverage for checkout_started is below 80%.';
        }

        if ((float) ($readiness['purchase_checkout_token_coverage'] ?? 0) < 70.0) {
            $blockers[] = 'purchase checkout-token continuity is below 70%.';
        }

        if ((float) ($readiness['purchase_linked_event_coverage'] ?? 0) < 70.0) {
            $blockers[] = 'purchase linkage confidence is still too low for checkout recovery automation.';
        }

        return $blockers;
    }

    protected function abandonmentStatus(array $readiness, string $type): string
    {
        if ($type === 'cart') {
            return $this->cartAbandonmentBlockers($readiness) === [] ? 'can_ship_now' : 'needs_small_build';
        }

        return $this->checkoutAbandonmentBlockers($readiness) === [] ? 'can_ship_now' : 'needs_small_build';
    }

    /**
     * @param array<int,string> $blockers
     * @param array<int,string> $dependencies
     * @param array<int,string> $qa
     * @return array<string,mixed>
     */
    protected function workflowAuditPayload(
        string $key,
        int $eligibleNow,
        array $blockers,
        array $dependencies,
        string $trigger,
        string $suppression,
        array $qa,
        string $manualOrAutomated,
        ?string $fallbackStatus = null
    ): array {
        $definition = $this->definitions[$key] ?? [];
        $status = $fallbackStatus ?? ((count($blockers) === 0)
            ? ((string) ($definition['status_target'] ?? 'can_ship_now'))
            : 'needs_small_build');

        $statusLabel = match ($status) {
            'can_ship_now' => 'can ship now',
            'needs_small_build' => 'needs small build',
            'needs_major_build' => 'needs major build',
            default => str_replace('_', ' ', $status),
        };

        return [
            'key' => $key,
            'label' => (string) ($definition['label'] ?? Str::headline($key)),
            'priority' => (int) ($definition['priority'] ?? 999),
            'status' => $status,
            'status_label' => $statusLabel,
            'eligible_now' => max(0, $eligibleNow),
            'channel' => $definition['channel'] ?? null,
            'objective' => $definition['objective'] ?? null,
            'launch_mode' => $manualOrAutomated,
            'cooldown_days' => $definition['cooldown_days'] ?? null,
            'trigger' => $trigger,
            'suppression' => $suppression,
            'dependencies' => $dependencies,
            'blockers' => array_values($blockers),
            'success_metric' => (string) ($definition['success_metric'] ?? 'Needs definition.'),
            'qa_steps' => array_values($qa),
            'can_prepare' => in_array($key, [self::WORKFLOW_WELCOME, self::WORKFLOW_WINBACK, self::WORKFLOW_POST_PURCHASE], true)
                && $status === 'can_ship_now',
            'operator_route' => $key === self::WORKFLOW_WISHLIST ? route('marketing.wishlist') : null,
        ];
    }

    protected function percent(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 1);
    }

    protected function numericStringToInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function asCarbonImmutable(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
