<?php

namespace App\Console\Commands;

use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Services\Marketing\BirthdayClubService;
use Illuminate\Console\Command;

class ReconcileBirthdayClub extends Command
{
    protected $signature = 'birthday-club:reconcile {--tenant-id=} {--apply-coverage : Register annual coverage only; never issue, activate or send}';

    protected $description = 'Read birthday entitlements and optionally register durable annual coverage without changing gifts.';

    public function handle(BirthdayClubService $club): int
    {
        $tenantId = filter_var($this->option('tenant-id'), FILTER_VALIDATE_INT);
        if (! $tenantId || $tenantId < 1) {
            $this->error('A positive tenant id is required.');

            return self::FAILURE;
        }
        $summary = ['tenant_id' => $tenantId, 'apply_coverage' => (bool) $this->option('apply-coverage'), 'profiles' => 0, 'entitlements' => 0, 'cycles' => 0, 'ambiguous_profile_ids' => [], 'states' => [], 'types' => []];
        foreach (CustomerBirthdayProfile::query()->whereHas('marketingProfile', fn ($q) => $q->where('tenant_id', $tenantId))->with('marketingProfile')->lazyById(250) as $birthday) {
            $summary['profiles']++;
            $rewards = BirthdayRewardIssuance::query()->where('customer_birthday_profile_id', $birthday->id)->get();
            $years = $rewards->pluck('cycle_year')->all();
            if ($birthday->reward_last_issued_year) {
                $years[] = (int) $birthday->reward_last_issued_year;
            }
            foreach ($rewards as $reward) {
                $summary['entitlements']++;
                $effectiveState = $reward->isExpired() ? 'expired' : $reward->status;
                $summary['states'][$effectiveState] = ($summary['states'][$effectiveState] ?? 0) + 1;
                $summary['types'][$reward->reward_type] = ($summary['types'][$reward->reward_type] ?? 0) + 1;
            }
            try {
                $club->identity($birthday->marketingProfile);
                foreach (array_unique($years) as $year) {
                    $summary['cycles']++;
                    if ($this->option('apply-coverage')) {
                        $club->registerExisting($birthday, (int) $year);
                    }
                }
            } catch (\RuntimeException) {
                $summary['ambiguous_profile_ids'][] = (int) $birthday->marketing_profile_id;
            }
        }
        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        return $summary['ambiguous_profile_ids'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
