<?php

namespace App\Services\Shopify;

use App\Models\CandleCashRedemption;
use App\Models\CandleCashReferral;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\User;
use App\Services\Marketing\CandleClubMembershipService;
use App\Services\Marketing\TwilioSenderConfigService;
use App\Services\Marketing\CandleCashService;
use App\Support\Schema\SchemaCapabilityMap;
use App\Support\Diagnostics\ShopifyEmbeddedDeepProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopifyEmbeddedCustomerDetailService
{
    private const DEFERRED_EXTERNAL_PROFILE_LIMIT = 6;

    public function __construct(
        protected TwilioSenderConfigService $senderConfigService,
        protected CandleCashService $candleCashService,
        protected CandleClubMembershipService $membershipService,
        protected SchemaCapabilityMap $schemaCapabilities
    ) {
    }

    /**
     * @return array{
     *   summary:array<string,mixed>,
     *   statuses:array<string,bool>,
     *   activity:array<int,array<string,mixed>>,
     *   external_profiles:Collection<int,CustomerExternalProfile>
     * }
     */
    public function build(MarketingProfile $profile, ?int $tenantId = null): array
    {
        return array_merge(
            $this->buildCritical($profile, $tenantId),
            $this->buildDeferredSections($profile, $tenantId),
        );
    }

    /**
     * @return array{
     *   summary:array<string,mixed>,
     *   statuses:array<string,bool>,
     *   consent:array<string,mixed>,
     *   messaging:array<string,mixed>
     * }
     */
    public function buildCritical(MarketingProfile $profile, ?int $tenantId = null): array
    {
        ShopifyEmbeddedDeepProfile::time(
            'customer_detail.assert_tenant_scope',
            function () use ($profile, $tenantId): null {
                $this->assertTenantScopedProfile($profile->id, $tenantId);

                return null;
            }
        );

        ShopifyEmbeddedDeepProfile::time('customer_detail.load_critical_relations', function () use ($profile): null {
            $profile->loadMissing([
                'candleCashBalance',
                'birthdayProfile',
            ]);

            return null;
        });

        $balancePoints = ShopifyEmbeddedDeepProfile::time(
            'customer_detail.balance_points',
            fn (): int => $this->balancePoints($profile)
        );
        $rewardsActions = ShopifyEmbeddedDeepProfile::time(
            'customer_detail.rewards_actions_count',
            fn (): int => $this->rewardsActionsCount($profile->id)
        );
        $statuses = [
            'candle_club' => ShopifyEmbeddedDeepProfile::time(
                'customer_detail.status_candle_club',
                fn (): bool => $this->hasCandleClub($profile->id)
            ),
            'referral' => ShopifyEmbeddedDeepProfile::time(
                'customer_detail.status_referral',
                fn (): bool => $this->hasReferralCompletion($profile->id)
            ),
            'review' => ShopifyEmbeddedDeepProfile::time(
                'customer_detail.status_review',
                fn (): bool => $this->hasReviewCompletion($profile->id)
            ),
            'birthday' => ShopifyEmbeddedDeepProfile::time(
                'customer_detail.status_birthday',
                fn (): bool => $this->hasBirthdayCompletion($profile->id)
            ),
            'wholesale' => ShopifyEmbeddedDeepProfile::time(
                'customer_detail.status_wholesale',
                fn (): bool => $this->hasWholesaleEligibility($profile->id)
            ),
        ];

        $summary = [
            'candle_cash' => $balancePoints,
            'candle_cash_display' => $this->candleCashService->formatRewardCurrency($this->candleCashService->amountFromPoints($balancePoints)),
            'candle_club_active' => $statuses['candle_club'],
            'rewards_actions_count' => $rewardsActions,
            'last_activity_at' => null,
            'last_activity_display' => 'Loading recent activity…',
            'birthday_tracked' => $this->birthdayTracked($profile),
            'wholesale_eligible' => $statuses['wholesale'],
        ];

        return [
            'summary' => $summary,
            'statuses' => $statuses,
            'consent' => ShopifyEmbeddedDeepProfile::time(
                'customer_detail.consent_snapshot',
                fn (): array => $this->consentSnapshot($profile)
            ),
            'messaging' => ShopifyEmbeddedDeepProfile::time(
                'customer_detail.messaging_snapshot',
                fn (): array => $this->messagingSnapshot($profile)
            ),
        ];
    }

    /**
     * @return array{
     *   activity:array<int,array<string,mixed>>,
     *   activity_count:int,
     *   external_profiles:Collection<int,CustomerExternalProfile>,
     *   external_profiles_count:int,
     *   deferred_meta:array{last_activity_at:?CarbonImmutable,last_activity_display:string}
     * }
     */
    public function buildDeferredSections(MarketingProfile $profile, ?int $tenantId = null): array
    {
        ShopifyEmbeddedDeepProfile::time(
            'customer_detail.assert_tenant_scope',
            function () use ($profile, $tenantId): null {
                $this->assertTenantScopedProfile($profile->id, $tenantId);

                return null;
            }
        );

        ShopifyEmbeddedDeepProfile::time('customer_detail.load_deferred_relations', function () use ($profile): null {
            $profile->loadMissing([
                'externalProfiles' => fn ($query) => $query->orderByDesc('synced_at')->orderByDesc('id')->limit(self::DEFERRED_EXTERNAL_PROFILE_LIMIT),
            ]);

            return null;
        });

        $activity = ShopifyEmbeddedDeepProfile::time(
            'customer_detail.activity_feed',
            fn (): array => $this->activityFeed($profile)
        );
        $lastActivityAt = $this->latestActivityTimestamp($activity);
        $externalProfilesCount = ShopifyEmbeddedDeepProfile::time(
            'customer_detail.external_profiles_count',
            fn (): int => $this->externalProfilesCount($profile->id)
        );

        return [
            'activity' => $activity,
            'activity_count' => count($activity),
            'external_profiles' => $profile->externalProfiles,
            'external_profiles_count' => $externalProfilesCount,
            'deferred_meta' => [
                'last_activity_at' => $lastActivityAt,
                'last_activity_display' => $lastActivityAt ? $this->formatTimestamp($lastActivityAt) : 'No recent activity',
            ],
        ];
    }

    protected function assertTenantScopedProfile(int $profileId, ?int $tenantId): void
    {
        $query = MarketingProfile::query()->whereKey($profileId);

        if ($tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $tenantId);
        }

        if ($query->exists()) {
            return;
        }

        $exception = new ModelNotFoundException();
        $exception->setModel(MarketingProfile::class, [$profileId]);

        throw $exception;
    }

    protected function balancePoints(MarketingProfile $profile): int
    {
        if (! $this->hasTable('candle_cash_balances')) {
            return 0;
        }

        return (int) ($profile->candleCashBalance?->balance ?? 0);
    }

    protected function rewardsActionsCount(int $profileId): int
    {
        if (! $this->hasTable('candle_cash_task_completions')) {
            return 0;
        }

        return (int) DB::table('candle_cash_task_completions')
            ->where('marketing_profile_id', $profileId)
            ->count();
    }

    protected function hasCandleClub(int $profileId): bool
    {
        /** @var MarketingProfile|null $profile */
        $profile = MarketingProfile::query()
            ->with('externalProfiles:id,marketing_profile_id,vip_tier,source_channels')
            ->find($profileId);

        return $this->membershipService->isActiveMember($profile);
    }

    protected function hasReferralCompletion(int $profileId): bool
    {
        if (! $this->hasTaskCompletion($profileId, ['refer-a-friend', 'referred-friend-bonus'])) {
            if (! $this->hasTable('candle_cash_referrals')) {
                return false;
            }

            return DB::table('candle_cash_referrals')
                ->where('referrer_marketing_profile_id', $profileId)
                ->where(function ($query): void {
                    $query
                        ->whereIn('status', ['qualified', 'rewarded', 'completed'])
                        ->orWhereNotNull('rewarded_at');
                })
                ->exists();
        }

        return true;
    }

    protected function hasReviewCompletion(int $profileId): bool
    {
        if ($this->hasTaskCompletion($profileId, ['google-review', 'product-review', 'photo-review'])) {
            return true;
        }

        if (! $this->hasTable('marketing_review_summaries')) {
            return false;
        }

        return DB::table('marketing_review_summaries')
            ->where('marketing_profile_id', $profileId)
            ->where('review_count', '>', 0)
            ->exists();
    }

    protected function hasBirthdayCompletion(int $profileId): bool
    {
        if ($this->hasTaskCompletion($profileId, ['birthday-signup'])) {
            return true;
        }

        if ($this->hasTable('birthday_reward_issuances')) {
            $issued = DB::table('birthday_reward_issuances')
                ->where('marketing_profile_id', $profileId)
                ->where(function ($query): void {
                    $query
                        ->whereIn('status', ['issued', 'claimed', 'redeemed'])
                        ->orWhereNotNull('claimed_at');
                })
                ->exists();

            if ($issued) {
                return true;
            }
        }

        if (! $this->hasTable('customer_birthday_profiles')) {
            return false;
        }

        return DB::table('customer_birthday_profiles')
            ->where('marketing_profile_id', $profileId)
            ->whereNotNull('reward_last_issued_at')
            ->exists();
    }

    protected function hasWholesaleEligibility(int $profileId): bool
    {
        $external = false;
        if ($this->hasTable('customer_external_profiles')) {
            $external = DB::table('customer_external_profiles')
                ->where('marketing_profile_id', $profileId)
                ->where(function ($query): void {
                    $query
                        ->whereRaw("lower(coalesce(store_key, '')) = 'wholesale'")
                        ->orWhereRaw("lower(coalesce(integration, '')) = 'wholesale'")
                        ->orWhereRaw("lower(coalesce(provider, '')) = 'wholesale'");
                })
                ->exists();
        }

        if ($external) {
            return true;
        }

        if (! $this->hasTable('marketing_profile_links')) {
            return false;
        }

        return DB::table('marketing_profile_links')
            ->where('marketing_profile_id', $profileId)
            ->whereRaw("lower(coalesce(source_type, '')) like 'wholesale%'")
            ->exists();
    }

    protected function birthdayTracked(MarketingProfile $profile): bool
    {
        $birthday = $profile->birthdayProfile;
        if ($birthday === null) {
            return false;
        }

        return $birthday->birth_month !== null || $birthday->birth_day !== null || $birthday->reward_last_issued_at !== null;
    }

    protected function hasTaskCompletion(int $profileId, array $handles): bool
    {
        if (! $this->hasTable('candle_cash_task_completions') || ! $this->hasTable('candle_cash_tasks')) {
            return false;
        }

        return DB::table('candle_cash_task_completions as completions')
            ->join('candle_cash_tasks as tasks', 'tasks.id', '=', 'completions.candle_cash_task_id')
            ->where('completions.marketing_profile_id', $profileId)
            ->whereIn('tasks.handle', $handles)
            ->whereIn('completions.status', ['awarded', 'approved', 'completed'])
            ->exists();
    }

    protected function externalProfilesCount(int $profileId): int
    {
        if (! $this->hasTable('customer_external_profiles')) {
            return 0;
        }

        return (int) DB::table('customer_external_profiles')
            ->where('marketing_profile_id', $profileId)
            ->count();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function activityFeed(MarketingProfile $profile): array
    {
        $entries = collect();
        $transactions = collect();
        $messageDeliveries = collect();

        if ($this->hasTable('candle_cash_transactions')) {
            $transactions = CandleCashTransaction::query()
                ->where('marketing_profile_id', $profile->id)
                ->orderByDesc('id')
                ->limit(18)
                ->get();
        }

        if ($this->hasTable('marketing_message_deliveries')) {
            $messageDeliveries = MarketingMessageDelivery::query()
                ->where('marketing_profile_id', $profile->id)
                ->orderByDesc('id')
                ->limit(12)
                ->get();
        }

        $actorLabels = $this->resolveActorLabels(
            $transactions
                ->filter(fn (CandleCashTransaction $transaction): bool => $this->isManualAdjustment($transaction) || $this->isCandleCashSend($transaction))
                ->pluck('source_id')
                ->merge($messageDeliveries->pluck('created_by'))
                ->all()
        );

        if ($transactions->isNotEmpty()) {
            $entries = $entries->merge($transactions->map(function (CandleCashTransaction $transaction) use ($actorLabels): array {
                $source = (string) ($transaction->source ?: 'internal');
                $type = 'Transaction';
                $label = strtoupper((string) $transaction->type);
                $status = $source;
                $actor = null;
                $giftDetailParts = null;

                if ($this->isManualAdjustment($transaction)) {
                    $type = 'Manual Adjustment';
                    $label = 'Rewards';
                    $status = 'Admin';
                    $actor = $this->resolveActorLabel($transaction->source_id, $actorLabels);
                } elseif ($this->isCandleCashSend($transaction)) {
                    $type = 'Reward Credit Sent';
                    $label = 'Rewards';
                    $status = 'Admin';
                    $actor = $this->resolveActorLabel($transaction->source_id, $actorLabels);
                    $giftDetailParts = [$transaction->description ?: 'Sent reward credit'];
                    if ($transaction->gift_intent !== null && trim((string) $transaction->gift_intent) !== '') {
                        $giftDetailParts[] = 'Intent: ' . Str::headline(str_replace('_', ' ', (string) $transaction->gift_intent));
                    }
                    if ($transaction->gift_origin !== null && trim((string) $transaction->gift_origin) !== '') {
                        $giftDetailParts[] = 'Origin: ' . Str::headline(str_replace('_', ' ', (string) $transaction->gift_origin));
                    }
                    if ($transaction->campaign_key !== null && trim((string) $transaction->campaign_key) !== '') {
                        $giftDetailParts[] = 'Campaign: ' . $transaction->campaign_key;
                    }
                    if ($transaction->notification_status !== null && trim((string) $transaction->notification_status) !== '') {
                        $notificationLabel = Str::headline(str_replace('_', ' ', (string) $transaction->notification_status));
                        $via = $transaction->notified_via ? ' via ' . Str::headline(str_replace('_', ' ', (string) $transaction->notified_via)) : '';
                        $giftDetailParts[] = 'Notification: ' . $notificationLabel . $via;
                    }
                } elseif ($transaction->type === 'earn') {
                    $type = 'Reward Earned';
                    $label = $transaction->description ?: 'Rewards';
                } elseif ($transaction->type === 'redeem') {
                    $type = 'Redemption';
                    $label = $transaction->description ?: 'Rewards';
                }

                $detailText = $giftDetailParts !== null ? implode(' · ', $giftDetailParts) : ($transaction->description ?: '—');

                return [
                    'occurred_at' => $transaction->created_at,
                    'type' => $type,
                    'label' => $label,
                    'candle_cash_display' => $this->candleCashService->candleCashAmountLabelFromPoints($transaction->candle_cash_delta, true),
                    'status' => $status,
                    'detail' => $detailText,
                    'actor' => $actor,
                ];
            }));
        }

        if ($messageDeliveries->isNotEmpty()) {
            $entries = $entries->merge($messageDeliveries->map(function (MarketingMessageDelivery $delivery) use ($actorLabels): array {
                $actor = $this->resolveActorLabel($delivery->created_by ? (string) $delivery->created_by : null, $actorLabels);
                $channel = strtolower((string) ($delivery->channel ?: 'message'));
                $type = $channel === 'sms' ? 'SMS Message' : strtoupper($channel);

                return [
                    'occurred_at' => $delivery->sent_at ?: $delivery->created_at,
                    'type' => $type,
                    'label' => 'Direct message',
                    'candle_cash_display' => null,
                    'status' => (string) ($delivery->send_status ?: 'sent'),
                    'detail' => Str::limit((string) ($delivery->rendered_message ?: '—'), 120),
                    'actor' => $actor,
                ];
            }));
        }

        if ($this->hasTable('candle_cash_redemptions')) {
            $redemptions = CandleCashRedemption::query()
                ->where('marketing_profile_id', $profile->id)
                ->with('reward:id,name')
                ->orderByDesc('id')
                ->limit(12)
                ->get();

            $entries = $entries->merge($redemptions->map(function (CandleCashRedemption $redemption): array {
                return [
                    'occurred_at' => $redemption->issued_at ?: $redemption->created_at,
                    'type' => 'Redemption',
                    'label' => $redemption->reward?->name ?: ('Reward #' . $redemption->reward_id),
                    'candle_cash_display' => $this->candleCashService->candleCashAmountLabelFromPoints(-1 * (int) ($redemption->candle_cash_spent ?? 0), true),
                    'status' => (string) ($redemption->status ?: 'issued'),
                    'detail' => $redemption->redemption_code ?: '—',
                    'actor' => null,
                ];
            }));
        }

        if ($this->hasTable('candle_cash_referrals')) {
            $referrals = CandleCashReferral::query()
                ->where('referrer_marketing_profile_id', $profile->id)
                ->with('referrerTransaction:id,candle_cash_delta')
                ->orderByDesc('id')
                ->limit(12)
                ->get();

            $entries = $entries->merge($referrals->map(function (CandleCashReferral $referral): array {
                $points = $referral->referrerTransaction?->candle_cash_delta;
                $status = (string) ($referral->status ?: $referral->referrer_reward_status ?: 'captured');
                $type = in_array($status, ['qualified', 'rewarded', 'completed'], true) || $referral->rewarded_at
                    ? 'Referral Reward'
                    : 'Referral';

                return [
                    'occurred_at' => $referral->rewarded_at ?: $referral->qualified_at ?: $referral->created_at,
                    'type' => $type,
                    'label' => $referral->referral_code ? strtoupper($referral->referral_code) : strtoupper($status),
                    'candle_cash_display' => $points !== null ? $this->candleCashService->candleCashAmountLabelFromPoints($points, true) : null,
                    'status' => $status,
                    'detail' => $referral->referral_code ?: '—',
                    'actor' => null,
                ];
            }));
        }

        if ($this->hasTable('candle_cash_task_completions')) {
            $completions = CandleCashTaskCompletion::query()
                ->where('marketing_profile_id', $profile->id)
                ->with('task:id,title,handle')
                ->orderByDesc('id')
                ->limit(12)
                ->get();

            $entries = $entries->merge($completions->map(function (CandleCashTaskCompletion $completion): array {
                $occurredAt = $completion->awarded_at ?: $completion->reviewed_at ?: $completion->submitted_at ?: $completion->created_at;
                $handle = strtolower((string) ($completion->task?->handle ?? ''));
                $label = $completion->task?->title ?: ($completion->task?->handle ?: 'Reward action');
                $type = 'Reward Action';

                if ($handle !== '') {
                    if (str_contains($handle, 'birthday')) {
                        $type = 'Birthday Reward';
                    } elseif (str_contains($handle, 'review')) {
                        $type = 'Review Reward';
                    } elseif (str_contains($handle, 'refer')) {
                        $type = 'Referral Reward';
                    }
                }

                return [
                    'occurred_at' => $occurredAt,
                    'type' => $type,
                    'label' => $label,
                    'candle_cash' => $completion->reward_candle_cash !== null ? $this->candleCashService->amountFromPoints($completion->reward_candle_cash) : null,
                    'candle_cash_display' => $completion->reward_candle_cash !== null
                        ? $this->candleCashService->candleCashAmountLabelFromPoints($completion->reward_candle_cash, true)
                        : ($completion->reward_amount !== null ? '+' . $this->candleCashService->formatRewardCurrency((float) $completion->reward_amount) : null),
                    'status' => (string) ($completion->status ?: 'submitted'),
                    'detail' => $completion->task?->handle ?: '—',
                    'actor' => null,
                ];
            }));
        }

        return $entries
            ->filter(fn (array $row): bool => ! empty($row['occurred_at']))
            ->sortByDesc(fn (array $row) => $row['occurred_at'])
            ->take(20)
            ->map(function (array $row): array {
                $row['occurred_at_display'] = $this->formatTimestamp($row['occurred_at']);
                $row['actor'] = $row['actor'] ?? '—';

                return $row;
            })
            ->values()
            ->all();
    }

    protected function formatTimestamp(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '—';
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('M j, Y g:i A');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    /**
     * @return array{
     *   email:array{status:bool,label:string,opted_out_at:?string,last_event:?array<string,mixed>},
     *   sms:array{status:bool,label:string,opted_out_at:?string,last_event:?array<string,mixed>}
     * }
     */
    protected function consentSnapshot(MarketingProfile $profile): array
    {
        $lastEvents = [
            'email' => null,
            'sms' => null,
        ];

        if ($this->hasTable('marketing_consent_events')) {
            $events = MarketingConsentEvent::query()
                ->where('marketing_profile_id', $profile->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(12)
                ->get()
                ->groupBy(fn (MarketingConsentEvent $event): string => (string) $event->channel);

            foreach (['email', 'sms'] as $channel) {
                $event = $events->get($channel)?->first();
                if ($event) {
                    $lastEvents[$channel] = [
                        'event_type' => (string) $event->event_type,
                        'source_type' => (string) ($event->source_type ?: ''),
                        'occurred_at' => $event->occurred_at,
                        'occurred_at_display' => $this->formatTimestamp($event->occurred_at),
                    ];
                }
            }
        }

        $emailOptedOutAt = $profile->email_opted_out_at;
        $smsOptedOutAt = $profile->sms_opted_out_at;

        return [
            'email' => [
                'status' => (bool) $profile->accepts_email_marketing,
                'label' => (bool) $profile->accepts_email_marketing ? 'Consented' : 'Not consented',
                'opted_out_at' => $emailOptedOutAt ? $this->formatTimestamp($emailOptedOutAt) : null,
                'last_event' => $lastEvents['email'],
            ],
            'sms' => [
                'status' => (bool) $profile->accepts_sms_marketing,
                'label' => (bool) $profile->accepts_sms_marketing ? 'Consented' : 'Not consented',
                'opted_out_at' => $smsOptedOutAt ? $this->formatTimestamp($smsOptedOutAt) : null,
                'last_event' => $lastEvents['sms'],
            ],
        ];
    }

    /**
     * @return array{
     *   sms:array{supported:bool,consented:bool,has_phone:bool,phone_display:string,consent_label:string,default_sender_key:?string,senders:array<int,array<string,mixed>>},
     *   email:array{supported:bool}
     * }
     */
    protected function messagingSnapshot(MarketingProfile $profile): array
    {
        $phone = trim((string) ($profile->normalized_phone ?: $profile->phone));
        $hasPhone = $phone !== '';
        $consented = (bool) $profile->accepts_sms_marketing;
        $senders = $this->senderConfigService->all();
        $defaultSender = $this->senderConfigService->defaultSender();
        $smsSupported = $this->senderConfigService->smsSupported();

        return [
            'sms' => [
                'supported' => $smsSupported,
                'consented' => $consented,
                'has_phone' => $hasPhone,
                'phone_display' => $hasPhone ? $phone : 'No phone on file',
                'consent_label' => $consented ? 'Consented' : 'Consent needed',
                'default_sender_key' => $defaultSender['key'] ?? null,
                'senders' => $senders,
            ],
            'email' => [
                'supported' => false,
            ],
        ];
    }

    /**
     * @param array<int|string|null> $sourceIds
     * @return array<int,string>
     */
    protected function resolveActorLabels(array $sourceIds): array
    {
        if (! $this->hasTable('users')) {
            return [];
        }

        $ids = collect($sourceIds)
            ->filter(fn ($value): bool => $value !== null && $value !== '')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids->all())
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(function (User $user): array {
                $label = trim((string) $user->name);
                if ($label === '') {
                    $label = trim((string) $user->email);
                }

                return [$user->id => ($label !== '' ? $label : 'Admin')];
            })
            ->all();
    }

    protected function resolveActorLabel(?string $sourceId, array $labels): string
    {
        $id = (int) ($sourceId ?? 0);

        return $labels[$id] ?? 'Admin';
    }

    protected function isManualAdjustment(CandleCashTransaction $transaction): bool
    {
        return $transaction->type === 'adjust'
            && in_array((string) $transaction->source, ['admin', 'shopify_embedded_admin'], true);
    }

    protected function isCandleCashSend(CandleCashTransaction $transaction): bool
    {
        return $transaction->type === 'gift'
            && in_array((string) $transaction->source, ['admin', 'shopify_embedded_admin'], true);
    }

    /**
     * @param array<int,array<string,mixed>> $activity
     */
    protected function latestActivityTimestamp(array $activity): ?CarbonImmutable
    {
        $value = $activity[0]['occurred_at'] ?? null;
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value === null || (string) $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function hasTable(string $table): bool
    {
        return $this->schemaCapabilities->hasTable($table);
    }
}
