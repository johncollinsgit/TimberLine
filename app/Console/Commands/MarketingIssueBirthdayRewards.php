<?php

namespace App\Console\Commands;

use App\Models\CustomerBirthdayProfile;
use App\Services\Marketing\BirthdayRewardEngineService;
use Illuminate\Console\Command;

class MarketingIssueBirthdayRewards extends Command
{
    protected $signature = 'marketing:issue-birthday-rewards
        {--cycle-year= : Reward cycle year override}
        {--limit=500 : Maximum profiles to evaluate}
        {--dry-run : Evaluate only without issuing rewards}';

    protected $description = 'Issue annual birthday rewards for eligible customers with guardrails.';

    public function handle(BirthdayRewardEngineService $engine): int
    {
        $cycleYear = (int) ($this->option('cycle-year') ?: now()->year);
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $profiles = CustomerBirthdayProfile::query()
            ->whereNotNull('birth_month')
            ->whereNotNull('birth_day')
            ->orderBy('reward_last_issued_year')
            ->orderBy('birth_month')
            ->orderBy('birth_day')
            ->limit($limit)
            ->get();

        $summary = [
            'evaluated' => 0,
            'eligible' => 0,
            'issued' => 0,
            'already_claimed' => 0,
            'outside_claim_window' => 0,
            'skipped' => 0,
        ];

        foreach ($profiles as $profile) {
            $summary['evaluated']++;
            $status = $engine->statusForProfile($profile, ['cycle_year' => $cycleYear]);
            $state = (string) ($status['state'] ?? 'birthday_saved');

            if ($state !== 'birthday_reward_eligible') {
                if ($state === 'already_claimed') {
                    $summary['already_claimed']++;
                } elseif ($state === 'birthday_saved') {
                    $summary['outside_claim_window']++;
                } else {
                    $summary['skipped']++;
                }

                continue;
            }

            $summary['eligible']++;
            if ($dryRun) {
                continue;
            }

            try {
                $result = $engine->issueAnnualReward($profile, ['cycle_year' => $cycleYear]);
            } catch (\Throwable $e) {
                $this->error('Birthday reward issuance failed: '.$e->getMessage());

                return self::FAILURE;
            }

            if ((bool) ($result['ok'] ?? false)) {
                $summary['issued']++;
            } else {
                $errorState = (string) ($result['state'] ?? 'skipped');
                if ($errorState === 'outside_claim_window') {
                    $summary['outside_claim_window']++;
                } elseif ($errorState === 'already_claimed') {
                    $summary['already_claimed']++;
                } else {
                    $summary['skipped']++;
                }
            }
        }

        $this->line('cycle_year='.$cycleYear);
        $this->line('mode='.($dryRun ? 'dry-run' : 'live'));
        foreach ($summary as $key => $value) {
            $this->line($key.'='.(int) $value);
        }

        return self::SUCCESS;
    }
}
