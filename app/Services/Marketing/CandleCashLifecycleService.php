<?php

namespace App\Services\Marketing;

use App\Models\MarketingAutomationEvent;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CandleCashLifecycleService
{
    public const TRIGGER_EARNED_NOT_USED = 'candle_cash_earned_not_used';

    public const TRIGGER_REMINDER = 'candle_cash_reminder';

    public const TRIGGER_LAPSED_WITH_VALUE = 'candle_cash_lapsed_with_value';

    /**
     * @return array{rows:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    public function preview(array $options = []): array
    {
        $tenantId = $this->positiveInt($options['tenant_id'] ?? null);
        $storeKey = $this->nullableString($options['store_key'] ?? null);
        $trigger = $this->resolveTrigger($options['trigger'] ?? null);
        $channel = $this->resolveChannel($options['channel'] ?? null);
        $limit = max(1, min(500, (int) ($options['limit'] ?? config('marketing.candle_cash.lifecycle.preview_limit', 200))));
        $now = isset($options['now']) ? CarbonImmutable::parse((string) $options['now']) : CarbonImmutable::now();
        $earnedWindowDays = max(1, (int) config('marketing.candle_cash.lifecycle.earned_not_used_days', 14));
        $reminderCooldownDays = max(1, (int) config('marketing.candle_cash.lifecycle.reminder_cooldown_days', 14));
        $lapsedDays = max(1, (int) config('marketing.candle_cash.lifecycle.lapsed_purchaser_days', 60));

        $candidateRows = collect((array) $this->analyticsService->reminderCandidates($tenantId)['rows'])
            ->filter(fn (array $row): bool => (int) ($row['marketing_profile_id'] ?? 0) > 0)
            ->values();

        if ($candidateRows->isEmpty()) {
            return [
                'rows' => [],
                'summary' => [
                    'trigger' => $trigger,
                    'channel' => $channel,
                    'tenant_id' => $tenantId,
                    'store_key' => $storeKey,
                    'evaluated_count' => 0,
                    'qualified_count' => 0,
                    'excluded_reasons' => [],
                ],
            ];
        }

        $profileIds = $candidateRows->pluck('marketing_profile_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

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
                'email_opted_out_at',
                'sms_opted_out_at',
            ])
            ->keyBy('id');

        $redemptionByProfile = $this->latestRedeemedAtByProfile($profileIds, $tenantId);
        $lastOrderByProfile = $this->lastOrderAtByProfile($profileIds, $tenantId, $storeKey);
        $cooldownCutoff = $now->subDays($reminderCooldownDays);

        $excludedReasons = [];

        $qualified = $candidateRows
            ->map(function (array $row) use (
                $profiles,
                $redemptionByProfile,
                $lastOrderByProfile,
                $trigger,
                $channel,
                $earnedWindowDays,
                $cooldownCutoff,
                $lapsedDays,
                $now,
                &$excludedReasons
            ): ?array {
                $profileId = (int) ($row['marketing_profile_id'] ?? 0);
                if ($profileId <= 0) {
                    $excludedReasons['missing_profile_id'] = ($excludedReasons['missing_profile_id'] ?? 0) + 1;

                    return null;
                }

                /** @var MarketingProfile|null $profile */
                $profile = $profiles->get($profileId);
                if (! $profile) {
                    $excludedReasons['profile_missing_or_not_in_tenant'] = ($excludedReasons['profile_missing_or_not_in_tenant'] ?? 0) + 1;

                    return null;
                }

                $latestEarnedAt = $this->parseDate($row['latest_earned_date'] ?? null);
                if (! $latestEarnedAt) {
                    $excludedReasons['latest_earned_at_missing'] = ($excludedReasons['latest_earned_at_missing'] ?? 0) + 1;

                    return null;
                }

                $earnedWindowReached = $latestEarnedAt->lessThanOrEqualTo($now->subDays($earnedWindowDays));
                if (! $earnedWindowReached) {
                    $excludedReasons['earned_window_not_reached'] = ($excludedReasons['earned_window_not_reached'] ?? 0) + 1;

                    return null;
                }

                $lastRedeemedAt = $redemptionByProfile[$profileId] ?? null;
                $hasRedeemedAfterLatestEarn = $lastRedeemedAt instanceof CarbonImmutable && $lastRedeemedAt->greaterThanOrEqualTo($latestEarnedAt);
                if ($hasRedeemedAfterLatestEarn) {
                    $excludedReasons['already_redeemed_after_earn'] = ($excludedReasons['already_redeemed_after_earn'] ?? 0) + 1;

                    return null;
                }

                if ($trigger === self::TRIGGER_LAPSED_WITH_VALUE) {
                    $lastOrderAt = $lastOrderByProfile[$profileId] ?? null;
                    $lapsedCutoff = $now->subDays($lapsedDays);
                    if ($lastOrderAt instanceof CarbonImmutable && $lastOrderAt->greaterThan($lapsedCutoff)) {
                        $excludedReasons['not_lapsed_purchaser'] = ($excludedReasons['not_lapsed_purchaser'] ?? 0) + 1;

                        return null;
                    }
                }

                if ($trigger === self::TRIGGER_REMINDER || $trigger === self::TRIGGER_LAPSED_WITH_VALUE) {
                    if (! $this->isContactableForChannel($profile, $channel)) {
                        $excludedReasons['not_contactable_for_channel'] = ($excludedReasons['not_contactable_for_channel'] ?? 0) + 1;

                        return null;
                    }
                }

                if ($trigger === self::TRIGGER_REMINDER) {
                    if ($this->hasRecentLifecycleEvent($profileId, (int) ($profile->tenant_id ?? 0), $channel, $cooldownCutoff)) {
                        $excludedReasons['cooldown_active'] = ($excludedReasons['cooldown_active'] ?? 0) + 1;

                        return null;
                    }
                }

                return [
                    'marketing_profile_id' => $profileId,
                    'tenant_id' => (int) ($profile->tenant_id ?? 0) ?: null,
                    'first_name' => (string) ($profile->first_name ?? ''),
                    'last_name' => (string) ($profile->last_name ?? ''),
                    'email' => $this->normalizedEmailForProfile($profile),
                    'phone' => $this->normalizedPhoneForProfile($profile),
                    'trigger_key' => $trigger,
                    'channel' => $channel,
                    'outstanding_candle_cash' => round((float) ($row['outstanding_candle_cash'] ?? 0), 2),
                    'outstanding_amount' => round((float) ($row['outstanding_amount'] ?? 0), 2),
                    'formatted_outstanding_amount' => (string) ($row['formatted_outstanding_amount'] ?? '$0.00'),
                    'earned_date' => $row['earned_date'] ?? null,
                    'latest_earned_date' => $row['latest_earned_date'] ?? null,
                    'last_redeemed_at' => $lastRedeemedAt?->toIso8601String(),
                    'last_order_at' => ($lastOrderByProfile[$profileId] ?? null)?->toIso8601String(),
                    'top_sources' => (array) ($row['top_sources'] ?? []),
                    'qualification_reason' => $trigger === self::TRIGGER_LAPSED_WITH_VALUE
                        ? 'Customer has outstanding program-earned Candle Cash, has not redeemed after latest earn, and is lapsed by the configured purchaser window.'
                        : 'Customer has outstanding program-earned Candle Cash and no post-earn redemption in the configured window.',
                ];
            })
            ->filter(fn (?array $row): bool => is_array($row))
            ->sortByDesc('outstanding_candle_cash')
            ->take($limit)
            ->values();

        return [
            'rows' => $qualified->all(),
            'summary' => [
                'trigger' => $trigger,
                'channel' => $channel,
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'evaluated_count' => $candidateRows->count(),
                'qualified_count' => $qualified->count(),
                'excluded_reasons' => $excludedReasons,
                'windows' => [
                    'earned_not_used_days' => $earnedWindowDays,
                    'reminder_cooldown_days' => $reminderCooldownDays,
                    'lapsed_purchaser_days' => $lapsedDays,
                ],
            ],
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array{recorded:int,skipped:int}
     */
    public function recordQueuedIntents(Collection $rows, array $options = []): array
    {
        $occurredAt = isset($options['occurred_at'])
            ? CarbonImmutable::parse((string) $options['occurred_at'])
            : CarbonImmutable::now();
        $storeKey = $this->nullableString($options['store_key'] ?? null);

        $recorded = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $profileId = (int) ($row['marketing_profile_id'] ?? 0);
            $tenantId = $this->positiveInt($row['tenant_id'] ?? null);
            $trigger = $this->resolveTrigger($row['trigger_key'] ?? null);
            $channel = $this->resolveChannel($row['channel'] ?? null);
            if ($profileId <= 0) {
                $skipped++;

                continue;
            }

            $exists = MarketingAutomationEvent::query()
                ->forTenantId($tenantId)
                ->where('marketing_profile_id', $profileId)
                ->where('trigger_key', $trigger)
                ->where('channel', $channel)
                ->where('status', 'queued_intent')
                ->where('occurred_at', '>=', $occurredAt->subHours(24))
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            MarketingAutomationEvent::query()->create([
                'tenant_id' => $tenantId,
                'marketing_profile_id' => $profileId,
                'trigger_key' => $trigger,
                'channel' => $channel,
                'status' => 'queued_intent',
                'store_key' => $storeKey,
                'reason' => (string) ($row['qualification_reason'] ?? ''),
                'context' => [
                    'outstanding_candle_cash' => round((float) ($row['outstanding_candle_cash'] ?? 0), 2),
                    'outstanding_amount' => round((float) ($row['outstanding_amount'] ?? 0), 2),
                    'earned_date' => $row['earned_date'] ?? null,
                    'latest_earned_date' => $row['latest_earned_date'] ?? null,
                    'top_sources' => (array) ($row['top_sources'] ?? []),
                ],
                'occurred_at' => $occurredAt,
            ]);

            $recorded++;
        }

        return [
            'recorded' => $recorded,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  Collection<int,int>  $profileIds
     * @return array<int,CarbonImmutable>
     */
    protected function latestRedeemedAtByProfile(Collection $profileIds, ?int $tenantId): array
    {
        if ($profileIds->isEmpty()) {
            return [];
        }

        $rows = \App\Models\CandleCashRedemption::query()
            ->join('marketing_profiles as mp', 'mp.id', '=', 'candle_cash_redemptions.marketing_profile_id')
            ->when($tenantId !== null, fn ($query) => $query->where('mp.tenant_id', $tenantId))
            ->whereIn('candle_cash_redemptions.marketing_profile_id', $profileIds->all())
            ->where('candle_cash_redemptions.status', 'redeemed')
            ->whereNotNull('candle_cash_redemptions.redeemed_at')
            ->selectRaw('candle_cash_redemptions.marketing_profile_id, MAX(candle_cash_redemptions.redeemed_at) as last_redeemed_at')
            ->groupBy('candle_cash_redemptions.marketing_profile_id')
            ->get();

        $byProfile = [];
        foreach ($rows as $row) {
            $profileId = (int) ($row->marketing_profile_id ?? 0);
            $lastRedeemed = $this->parseDate($row->last_redeemed_at ?? null);
            if ($profileId > 0 && $lastRedeemed) {
                $byProfile[$profileId] = $lastRedeemed;
            }
        }

        return $byProfile;
    }

    /**
     * @param  Collection<int,int>  $profileIds
     * @return array<int,CarbonImmutable>
     */
    protected function lastOrderAtByProfile(Collection $profileIds, ?int $tenantId, ?string $storeKey): array
    {
        if ($profileIds->isEmpty()) {
            return [];
        }

        $shopifyCustomerIdsByProfile = MarketingProfileLink::query()
            ->forTenantId($tenantId)
            ->where('source_type', 'shopify_customer')
            ->whereIn('marketing_profile_id', $profileIds->all())
            ->when($storeKey !== null, fn ($query) => $query->where('source_id', 'like', $storeKey.':%'))
            ->get(['marketing_profile_id', 'source_id'])
            ->groupBy('marketing_profile_id')
            ->map(function (Collection $links): array {
                return $links
                    ->map(function (MarketingProfileLink $link): ?string {
                        $sourceId = trim((string) $link->source_id);
                        if ($sourceId === '' || ! str_contains($sourceId, ':')) {
                            return null;
                        }

                        return trim((string) str($sourceId)->afterLast(':'));
                    })
                    ->filter(fn (?string $customerId): bool => $customerId !== null && $customerId !== '')
                    ->unique()
                    ->values()
                    ->all();
            });

        $shopifyCustomerIds = $shopifyCustomerIdsByProfile
            ->flatten()
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->unique()
            ->values();

        if ($shopifyCustomerIds->isEmpty()) {
            return [];
        }

        $lastOrderByCustomer = Order::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($storeKey !== null, fn ($query) => $query->where('shopify_store_key', $storeKey))
            ->whereIn('shopify_customer_id', $shopifyCustomerIds->all())
            ->selectRaw(
                "shopify_customer_id, MAX(COALESCE(ordered_at, created_at)) as last_order_at"
            )
            ->groupBy('shopify_customer_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->shopify_customer_id => $this->parseDate($row->last_order_at ?? null),
            ])
            ->all();

        $lastOrderByProfile = [];
        foreach ($shopifyCustomerIdsByProfile as $profileId => $customerIds) {
            $dates = collect((array) $customerIds)
                ->map(fn (string $customerId): ?CarbonImmutable => $lastOrderByCustomer[$customerId] ?? null)
                ->filter(fn (?CarbonImmutable $value): bool => $value instanceof CarbonImmutable)
                ->sortDesc();

            $latest = $dates->first();
            if ($latest instanceof CarbonImmutable) {
                $lastOrderByProfile[(int) $profileId] = $latest;
            }
        }

        return $lastOrderByProfile;
    }

    protected function isContactableForChannel(MarketingProfile $profile, string $channel): bool
    {
        if ($channel === 'sms') {
            return $this->normalizedPhoneForProfile($profile) !== null
                && (bool) $profile->accepts_sms_marketing
                && $profile->sms_opted_out_at === null;
        }

        return $this->normalizedEmailForProfile($profile) !== null
            && (bool) $profile->accepts_email_marketing
            && $profile->email_opted_out_at === null;
    }

    protected function hasRecentLifecycleEvent(int $profileId, int $tenantId, string $channel, CarbonImmutable $cutoff): bool
    {
        return MarketingAutomationEvent::query()
            ->forTenantId($tenantId > 0 ? $tenantId : null)
            ->where('marketing_profile_id', $profileId)
            ->where('trigger_key', self::TRIGGER_REMINDER)
            ->where('channel', $channel)
            ->whereIn('status', ['queued_intent', 'dispatched', 'sent', 'processed'])
            ->where('occurred_at', '>=', $cutoff)
            ->exists();
    }

    protected function normalizedEmailForProfile(MarketingProfile $profile): ?string
    {
        $email = strtolower(trim((string) ($profile->normalized_email ?: $profile->email ?: '')));

        return $email !== '' ? $email : null;
    }

    protected function normalizedPhoneForProfile(MarketingProfile $profile): ?string
    {
        $phone = trim((string) ($profile->normalized_phone ?: $profile->phone ?: ''));

        return $phone !== '' ? $phone : null;
    }

    protected function resolveTrigger(mixed $value): string
    {
        $candidate = strtolower(trim((string) $value));
        $allowed = [
            self::TRIGGER_EARNED_NOT_USED,
            self::TRIGGER_REMINDER,
            self::TRIGGER_LAPSED_WITH_VALUE,
        ];

        return in_array($candidate, $allowed, true) ? $candidate : self::TRIGGER_REMINDER;
    }

    protected function resolveChannel(mixed $value): string
    {
        $candidate = strtolower(trim((string) $value));
        if (in_array($candidate, ['email', 'sms'], true)) {
            return $candidate;
        }

        return strtolower((string) config('marketing.candle_cash.lifecycle.default_channel', 'email')) === 'sms'
            ? 'sms'
            : 'email';
    }

    protected function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $cast = (int) $value;

        return $cast > 0 ? $cast : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $cast = strtolower(trim((string) $value));

        return $cast !== '' ? $cast : null;
    }

    public function __construct(
        protected CandleCashEarnedAnalyticsService $analyticsService
    ) {
    }
}
