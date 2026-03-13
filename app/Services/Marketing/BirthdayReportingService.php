<?php

namespace App\Services\Marketing;

use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use Carbon\CarbonInterface;
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

        $weekDates = collect();
        $start = $asOf->copy()->startOfWeek();
        $end = $asOf->copy()->endOfWeek();
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor = $cursor->copy()->addDay()) {
            $weekDates->push([(int) $cursor->month, (int) $cursor->day]);
        }

        $birthdaysToday = (int) $this->baseBirthdayQuery()
            ->where('birth_month', (int) $asOf->month)
            ->where('birth_day', (int) $asOf->day)
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

        $claimedThisYear = (int) BirthdayRewardIssuance::query()
            ->where('cycle_year', $year)
            ->whereIn('status', ['claimed', 'redeemed'])
            ->count();

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

        return [
            'total_profiles' => $totalProfiles,
            'with_birthday' => $withBirthday,
            'missing_birthday' => $missingBirthday,
            'capture_rate' => $totalProfiles > 0 ? round(($withBirthday / $totalProfiles) * 100, 2) : 0.0,
            'birthdays_today' => $birthdaysToday,
            'birthdays_this_week' => $birthdaysThisWeek,
            'birthdays_this_month' => $birthdaysThisMonth,
            'rewards_issued_this_year' => $issuedThisYear,
            'rewards_claimed_this_year' => $claimedThisYear,
            'segments_by_month' => $segmentsByMonth,
        ];
    }

    protected function baseBirthdayQuery(): Builder
    {
        return CustomerBirthdayProfile::query()
            ->whereNotNull('birth_month')
            ->whereNotNull('birth_day');
    }
}
