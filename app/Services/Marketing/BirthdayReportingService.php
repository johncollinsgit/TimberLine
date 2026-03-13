<?php

namespace App\Services\Marketing;

use App\Models\BirthdayRewardIssuance;
use App\Models\BirthdayMessageEvent;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

class BirthdayReportingService
{
    /**
     * @return array<string,mixed>
     */
    public function summary(?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ?: now();
        $year = (int) $asOf->year;

        $totalProfiles = (int) MarketingProfile::query()->count();
        $withBirthday = (int) $this->baseBirthdayQuery()->count();
        $missingBirthday = max(0, $totalProfiles - $withBirthday);
        $emailSubscribed = (int) CustomerBirthdayProfile::query()->where('email_subscribed', true)->count();
        $smsSubscribed = (int) CustomerBirthdayProfile::query()->where('sms_subscribed', true)->count();
        $shopifyMatched = (int) CustomerBirthdayProfile::query()
            ->whereHas('marketingProfile.links', fn (Builder $query) => $query->where('source_type', 'shopify_customer'))
            ->count();
        $nonShopify = max(0, $withBirthday - $shopifyMatched);

        $weekDates = collect();
        $start = $asOf->copy()->startOfWeek();
        $end = $asOf->copy()->endOfWeek();
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor = $cursor->copy()->addDay()) {
            $weekDates->push([(int) $cursor->month, (int) $cursor->day]);
        }

        $tomorrow = $asOf->copy()->addDay();

        $birthdaysToday = (int) $this->baseBirthdayQuery()
            ->where('birth_month', (int) $asOf->month)
            ->where('birth_day', (int) $asOf->day)
            ->count();

        $birthdaysTomorrow = (int) $this->baseBirthdayQuery()
            ->where('birth_month', (int) $tomorrow->month)
            ->where('birth_day', (int) $tomorrow->day)
            ->count();

        $birthdaysThisWeek = (int) $this->baseBirthdayQuery()
            ->where(function (Builder $query) use ($weekDates): void {
                foreach ($weekDates as [$month, $day]) {
                    $query->orWhere(function (Builder $dayQuery) use ($month, $day): void {
                        $dayQuery->where('birth_month', $month)->where('birth_day', $day);
                    });
                }
            })
            ->count();

        $birthdaysThisMonth = (int) $this->baseBirthdayQuery()
            ->where('birth_month', (int) $asOf->month)
            ->count();

        $issuedThisYear = (int) BirthdayRewardIssuance::query()
            ->where('cycle_year', $year)
            ->count();

        $activatedThisYear = (int) BirthdayRewardIssuance::query()
            ->where('cycle_year', $year)
            ->whereIn('status', ['claimed', 'redeemed'])
            ->count();

        $redeemedThisYear = (int) BirthdayRewardIssuance::query()
            ->where('cycle_year', $year)
            ->where('status', 'redeemed')
            ->count();

        $attributedRevenue = (float) (BirthdayRewardIssuance::query()
            ->where('cycle_year', $year)
            ->where('status', 'redeemed')
            ->sum('attributed_revenue') ?? 0);

        $averageOrderValue = $redeemedThisYear > 0
            ? round($attributedRevenue / $redeemedThisYear, 2)
            : 0.0;

        $syncFailures = (int) BirthdayRewardIssuance::query()
            ->where('cycle_year', $year)
            ->where('discount_sync_status', 'failed')
            ->count();

        $emailEventsThisYear = BirthdayMessageEvent::query()
            ->where('channel', 'email')
            ->whereYear('created_at', $year);

        $emailsSent = (int) (clone $emailEventsThisYear)->whereNotNull('sent_at')->count();
        $emailsOpened = (int) (clone $emailEventsThisYear)->whereNotNull('opened_at')->count();
        $emailsClicked = (int) (clone $emailEventsThisYear)->whereNotNull('clicked_at')->count();

        $segmentsByMonth = CustomerBirthdayProfile::query()
            ->selectRaw('birth_month, count(*) as total')
            ->whereNotNull('birth_month')
            ->whereNotNull('birth_day')
            ->groupBy('birth_month')
            ->orderBy('birth_month')
            ->get()
            ->map(fn ($row): array => [
                'month' => (int) $row->birth_month,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        $signupSources = CustomerBirthdayProfile::query()
            ->selectRaw('signup_source, count(*) as total')
            ->whereNotNull('signup_source')
            ->groupBy('signup_source')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'label' => (string) $row->signup_source,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        $recentTrend = $this->recentTrend($asOf);

        return [
            'total_profiles' => $totalProfiles,
            'with_birthday' => $withBirthday,
            'missing_birthday' => $missingBirthday,
            'capture_rate' => $totalProfiles > 0 ? round(($withBirthday / $totalProfiles) * 100, 2) : 0.0,
            'birthdays_today' => $birthdaysToday,
            'birthdays_tomorrow' => $birthdaysTomorrow,
            'birthdays_this_week' => $birthdaysThisWeek,
            'birthdays_this_month' => $birthdaysThisMonth,
            'email_subscribed' => $emailSubscribed,
            'sms_subscribed' => $smsSubscribed,
            'shopify_matched' => $shopifyMatched,
            'non_shopify' => $nonShopify,
            'rewards_issued_this_year' => $issuedThisYear,
            'rewards_activated_this_year' => $activatedThisYear,
            'rewards_redeemed_this_year' => $redeemedThisYear,
            'reward_activation_rate' => $issuedThisYear > 0 ? round(($activatedThisYear / $issuedThisYear) * 100, 2) : 0.0,
            'reward_redemption_rate' => $activatedThisYear > 0 ? round(($redeemedThisYear / $activatedThisYear) * 100, 2) : 0.0,
            'attributed_revenue' => round($attributedRevenue, 2),
            'reward_average_order_value' => $averageOrderValue,
            'discount_sync_failures' => $syncFailures,
            'emails_sent_this_year' => $emailsSent,
            'emails_opened_this_year' => $emailsOpened,
            'emails_clicked_this_year' => $emailsClicked,
            'email_open_rate' => $emailsSent > 0 ? round(($emailsOpened / $emailsSent) * 100, 2) : 0.0,
            'email_click_rate' => $emailsSent > 0 ? round(($emailsClicked / $emailsSent) * 100, 2) : 0.0,
            'segments_by_month' => $segmentsByMonth,
            'signup_sources' => $signupSources,
            'recent_trend' => $recentTrend,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function campaignSummary(?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ?: now();
        $start = $asOf->copy()->startOfMonth();

        $events = BirthdayMessageEvent::query()
            ->where('created_at', '>=', $start)
            ->get();

        $grouped = collect(['birthday_email', 'birthday_sms', 'followup_email', 'followup_sms'])
            ->mapWithKeys(function (string $campaignType) use ($events): array {
                $rows = $events->where('campaign_type', $campaignType);

                return [$campaignType => [
                    'sent' => $rows->whereNotNull('sent_at')->count(),
                    'opened' => $rows->whereNotNull('opened_at')->count(),
                    'clicked' => $rows->whereNotNull('clicked_at')->count(),
                    'converted' => $rows->whereNotNull('conversion_at')->count(),
                ]];
            })
            ->all();

        return $grouped;
    }

    /**
     * @return array<string,mixed>
     */
    public function rewardSummary(): array
    {
        $issued = (int) BirthdayRewardIssuance::query()->count();
        $activated = (int) BirthdayRewardIssuance::query()->whereIn('status', ['claimed', 'redeemed'])->count();
        $redeemed = (int) BirthdayRewardIssuance::query()->where('status', 'redeemed')->count();
        $revenue = (float) (BirthdayRewardIssuance::query()
            ->where('status', 'redeemed')
            ->sum('attributed_revenue') ?? 0);

        return [
            'available' => (int) BirthdayRewardIssuance::query()->where('status', 'issued')->count(),
            'activated' => $activated,
            'redeemed' => $redeemed,
            'expired' => (int) BirthdayRewardIssuance::query()->where('status', 'expired')->count(),
            'sync_failures' => (int) BirthdayRewardIssuance::query()->where('discount_sync_status', 'failed')->count(),
            'activation_rate' => $issued > 0 ? round(($activated / $issued) * 100, 2) : 0.0,
            'redemption_rate' => $activated > 0 ? round(($redeemed / $activated) * 100, 2) : 0.0,
            'attributed_revenue' => round($revenue, 2),
            'average_order_value' => $redeemed > 0 ? round($revenue / $redeemed, 2) : 0.0,
            'latest' => BirthdayRewardIssuance::query()
                ->with('marketingProfile:id,first_name,last_name,email')
                ->latest('id')
                ->limit(10)
                ->get(),
        ];
    }

    /**
     * @return Collection<int,array{date:string,signups:int,sends:int,opens:int,clicks:int,issued:int,redeemed:int}>
     */
    protected function recentTrend(CarbonInterface $asOf): Collection
    {
        $start = $asOf->copy()->subDays(29)->startOfDay();
        $days = collect();

        for ($cursor = $start->copy(); $cursor->lte($asOf); $cursor = $cursor->copy()->addDay()) {
            $dateKey = $cursor->toDateString();
            $days->push([
                'date' => $dateKey,
                'signups' => (int) CustomerBirthdayProfile::query()->whereDate('created_at', $dateKey)->count(),
                'sends' => (int) BirthdayMessageEvent::query()->whereDate('sent_at', $dateKey)->count(),
                'opens' => (int) BirthdayMessageEvent::query()->whereDate('opened_at', $dateKey)->count(),
                'clicks' => (int) BirthdayMessageEvent::query()->whereDate('clicked_at', $dateKey)->count(),
                'issued' => (int) BirthdayRewardIssuance::query()->whereDate('issued_at', $dateKey)->count(),
                'redeemed' => (int) BirthdayRewardIssuance::query()->whereDate('redeemed_at', $dateKey)->count(),
            ]);
        }

        return $days;
    }

    protected function baseBirthdayQuery(): Builder
    {
        return CustomerBirthdayProfile::query()
            ->whereNotNull('birth_month')
            ->whereNotNull('birth_day');
    }
}
