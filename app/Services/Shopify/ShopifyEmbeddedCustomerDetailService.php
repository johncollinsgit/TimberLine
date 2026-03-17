<?php

namespace App\Services\Shopify;

use App\Models\CandleCashRedemption;
use App\Models\CandleCashReferral;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopifyEmbeddedCustomerDetailService
{
    /**
     * @return array{
     *   summary:array<string,mixed>,
     *   statuses:array<string,bool>,
     *   activity:array<int,array<string,mixed>>,
     *   external_profiles:Collection<int,CustomerExternalProfile>
     * }
     */
    public function build(MarketingProfile $profile): array
    {
        $profile->load([
            'candleCashBalance',
            'birthdayProfile',
            'externalProfiles' => fn ($query) => $query->orderByDesc('synced_at')->orderByDesc('id')->limit(6),
        ]);

        $balancePoints = $this->balancePoints($profile);
        $rewardsActions = $this->rewardsActionsCount($profile->id);
        $statuses = [
            'candle_club' => $this->hasCandleClub($profile->id),
            'referral' => $this->hasReferralCompletion($profile->id),
            'review' => $this->hasReviewCompletion($profile->id),
            'birthday' => $this->hasBirthdayCompletion($profile->id),
            'wholesale' => $this->hasWholesaleEligibility($profile->id),
        ];

        $lastActivityAt = $this->lastActivityAt($profile);

        $summary = [
            'candle_cash' => $balancePoints,
            'candle_cash_display' => number_format($balancePoints),
            'candle_club_active' => $statuses['candle_club'],
            'rewards_actions_count' => $rewardsActions,
            'last_activity_at' => $lastActivityAt,
            'last_activity_display' => $this->formatTimestamp($lastActivityAt),
            'birthday_tracked' => $this->birthdayTracked($profile),
            'wholesale_eligible' => $statuses['wholesale'],
        ];

        return [
            'summary' => $summary,
            'statuses' => $statuses,
            'activity' => $this->activityFeed($profile),
            'external_profiles' => $profile->externalProfiles,
            'consent' => $this->consentSnapshot($profile),
        ];
    }

    protected function balancePoints(MarketingProfile $profile): int
    {
        if (! Schema::hasTable('candle_cash_balances')) {
            return 0;
        }

        return (int) ($profile->candleCashBalance?->balance ?? 0);
    }

    protected function rewardsActionsCount(int $profileId): int
    {
        if (! Schema::hasTable('candle_cash_task_completions')) {
            return 0;
        }

        return (int) DB::table('candle_cash_task_completions')
            ->where('marketing_profile_id', $profileId)
            ->count();
    }

    protected function hasCandleClub(int $profileId): bool
    {
        $taskCompletion = $this->hasTaskCompletion($profileId, ['candle-club-join']);
        $groupMember = false;

        if (Schema::hasTable('marketing_group_members') && Schema::hasTable('marketing_groups')) {
            $groupMember = DB::table('marketing_group_members as members')
                ->join('marketing_groups as groups', 'groups.id', '=', 'members.marketing_group_id')
                ->where('members.marketing_profile_id', $profileId)
                ->whereRaw("lower(coalesce(groups.name, '')) like '%candle club%'")
                ->exists();
        }

        return $taskCompletion || $groupMember;
    }

    protected function hasReferralCompletion(int $profileId): bool
    {
        if (! $this->hasTaskCompletion($profileId, ['refer-a-friend', 'referred-friend-bonus'])) {
            if (! Schema::hasTable('candle_cash_referrals')) {
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

        if (! Schema::hasTable('marketing_review_summaries')) {
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

        if (Schema::hasTable('birthday_reward_issuances')) {
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

        if (! Schema::hasTable('customer_birthday_profiles')) {
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
        if (Schema::hasTable('customer_external_profiles')) {
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

        if (! Schema::hasTable('marketing_profile_links')) {
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
        if (! Schema::hasTable('candle_cash_task_completions') || ! Schema::hasTable('candle_cash_tasks')) {
            return false;
        }

        return DB::table('candle_cash_task_completions as completions')
            ->join('candle_cash_tasks as tasks', 'tasks.id', '=', 'completions.candle_cash_task_id')
            ->where('completions.marketing_profile_id', $profileId)
            ->whereIn('tasks.handle', $handles)
            ->whereIn('completions.status', ['awarded', 'approved', 'completed'])
            ->exists();
    }

    protected function lastActivityAt(MarketingProfile $profile): ?CarbonImmutable
    {
        $timestamps = collect([
            $profile->updated_at,
            $this->maxTimestamp('candle_cash_transactions', 'created_at', $profile->id, 'marketing_profile_id'),
            $this->maxTimestamp('candle_cash_task_completions', 'created_at', $profile->id, 'marketing_profile_id'),
            $this->maxTimestamp('candle_cash_referrals', 'updated_at', $profile->id, 'referrer_marketing_profile_id'),
            $this->maxTimestamp('marketing_review_summaries', 'updated_at', $profile->id, 'marketing_profile_id'),
            $this->maxTimestamp('customer_external_profiles', 'updated_at', $profile->id, 'marketing_profile_id'),
            $profile->birthdayProfile?->reward_last_issued_at,
            $this->maxTimestamp('birthday_reward_issuances', 'updated_at', $profile->id, 'marketing_profile_id'),
            $this->maxTimestamp('candle_cash_redemptions', 'updated_at', $profile->id, 'marketing_profile_id'),
        ])->filter();

        if ($timestamps->isEmpty()) {
            return null;
        }

        $latest = $timestamps->map(function ($value): ?CarbonImmutable {
            if ($value === null || (string) $value === '') {
                return null;
            }

            try {
                return CarbonImmutable::parse((string) $value);
            } catch (\Throwable) {
                return null;
            }
        })->filter()->sortDesc()->first();

        return $latest ?: null;
    }

    protected function maxTimestamp(string $table, string $column, int $profileId, string $foreignKey): ?string
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        return DB::table($table)
            ->where($foreignKey, $profileId)
            ->max($column);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function activityFeed(MarketingProfile $profile): array
    {
        $entries = collect();

        if (Schema::hasTable('candle_cash_transactions')) {
            $transactions = CandleCashTransaction::query()
                ->where('marketing_profile_id', $profile->id)
                ->orderByDesc('id')
                ->limit(18)
                ->get();

            $entries = $entries->merge($transactions->map(function (CandleCashTransaction $transaction): array {
                return [
                    'occurred_at' => $transaction->created_at,
                    'type' => 'Transaction',
                    'label' => strtoupper((string) $transaction->type),
                    'points' => (int) $transaction->points,
                    'status' => (string) ($transaction->source ?: 'internal'),
                    'detail' => $transaction->description ?: '—',
                ];
            }));
        }

        if (Schema::hasTable('candle_cash_redemptions')) {
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
                    'points' => -1 * (int) ($redemption->points_spent ?? 0),
                    'status' => (string) ($redemption->status ?: 'issued'),
                    'detail' => $redemption->redemption_code ?: '—',
                ];
            }));
        }

        if (Schema::hasTable('candle_cash_referrals')) {
            $referrals = CandleCashReferral::query()
                ->where('referrer_marketing_profile_id', $profile->id)
                ->with('referrerTransaction:id,points')
                ->orderByDesc('id')
                ->limit(12)
                ->get();

            $entries = $entries->merge($referrals->map(function (CandleCashReferral $referral): array {
                $points = $referral->referrerTransaction?->points;
                $status = (string) ($referral->status ?: $referral->referrer_reward_status ?: 'captured');

                return [
                    'occurred_at' => $referral->rewarded_at ?: $referral->qualified_at ?: $referral->created_at,
                    'type' => 'Referral',
                    'label' => strtoupper($status),
                    'points' => $points !== null ? (int) $points : null,
                    'status' => $status,
                    'detail' => $referral->referral_code ?: '—',
                ];
            }));
        }

        if (Schema::hasTable('candle_cash_task_completions')) {
            $completions = CandleCashTaskCompletion::query()
                ->where('marketing_profile_id', $profile->id)
                ->with('task:id,title,handle')
                ->orderByDesc('id')
                ->limit(12)
                ->get();

            $entries = $entries->merge($completions->map(function (CandleCashTaskCompletion $completion): array {
                $occurredAt = $completion->awarded_at ?: $completion->reviewed_at ?: $completion->submitted_at ?: $completion->created_at;
                $label = $completion->task?->title ?: ($completion->task?->handle ?: 'Reward action');

                return [
                    'occurred_at' => $occurredAt,
                    'type' => 'Reward action',
                    'label' => $label,
                    'points' => $completion->reward_points !== null ? (int) $completion->reward_points : null,
                    'status' => (string) ($completion->status ?: 'submitted'),
                    'detail' => $completion->task?->handle ?: '—',
                ];
            }));
        }

        return $entries
            ->filter(fn (array $row): bool => ! empty($row['occurred_at']))
            ->sortByDesc(fn (array $row) => $row['occurred_at'])
            ->take(20)
            ->map(function (array $row): array {
                $row['occurred_at_display'] = $this->formatTimestamp($row['occurred_at']);

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

        if (Schema::hasTable('marketing_consent_events')) {
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
}
