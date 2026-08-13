<?php

namespace App\Services\Wholesale;

use App\Models\TenantWholesaleSetting;
use App\Models\WholesaleProspectDailyUsage;
use App\Models\WholesaleProspectDiscoveryRun;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WholesaleProspectResearchLimitService
{
    /** @return array{result_limit:int,run_limit:int,remaining_results:int,remaining_runs:int} */
    public function availability(int $tenantId, ?Carbon $date = null): array
    {
        $date ??= now()->startOfDay();
        $settings = $this->settings($tenantId);
        $usage = WholesaleProspectDailyUsage::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->whereDate('research_date', $date)
            ->first();
        $consumedResults = (int) ($usage?->reserved_results ?? 0) + (int) ($usage?->researched_results ?? 0);
        $consumedRuns = (int) ($usage?->queued_runs ?? 0);

        return [
            'result_limit' => $settings['result_limit'],
            'run_limit' => $settings['run_limit'],
            'remaining_results' => max(0, $settings['result_limit'] - $consumedResults),
            'remaining_runs' => max(0, $settings['run_limit'] - $consumedRuns),
        ];
    }

    public function reserve(int $tenantId, int $maximumResults, Carbon $date): void
    {
        DB::transaction(function () use ($tenantId, $maximumResults, $date): void {
            $settings = $this->settings($tenantId);
            $usage = WholesaleProspectDailyUsage::query()->forAllTenants()
                ->where('tenant_id', $tenantId)
                ->whereDate('research_date', $date)
                ->lockForUpdate()
                ->first();
            if (! $usage) {
                $usage = WholesaleProspectDailyUsage::query()->create([
                    'tenant_id' => $tenantId,
                    'research_date' => $date->toDateString(),
                ]);
                $usage = WholesaleProspectDailyUsage::query()->forAllTenants()->lockForUpdate()->findOrFail($usage->id);
            }

            $resultUse = (int) $usage->reserved_results + (int) $usage->researched_results;
            if ($resultUse + $maximumResults > $settings['result_limit']) {
                throw new DomainException('Today’s research limit would be exceeded. Review completed prospects before starting another search.');
            }
            if ((int) $usage->queued_runs >= $settings['run_limit']) {
                throw new DomainException('Today’s research-run limit has been reached. Review the current queue before starting another search.');
            }

            $usage->increment('reserved_results', $maximumResults);
            $usage->increment('queued_runs');
        });
    }

    public function reconcile(WholesaleProspectDiscoveryRun $run, int $researchedResults): void
    {
        DB::transaction(function () use ($run, $researchedResults): void {
            $lockedRun = WholesaleProspectDiscoveryRun::query()->forAllTenants()->lockForUpdate()->findOrFail($run->id);
            if ($lockedRun->research_usage_reconciled_at !== null) {
                return;
            }
            $usage = WholesaleProspectDailyUsage::query()->forAllTenants()
                ->where('tenant_id', $lockedRun->tenant_id)
                ->whereDate('research_date', $lockedRun->research_date ?? $lockedRun->created_at)
                ->lockForUpdate()
                ->first();
            if ($usage) {
                $usage->forceFill([
                    'reserved_results' => max(0, (int) $usage->reserved_results - (int) $lockedRun->maximum_results),
                    'researched_results' => (int) $usage->researched_results + max(0, $researchedResults),
                    'completed_runs' => (int) $usage->completed_runs + 1,
                ])->save();
            }
            $lockedRun->forceFill(['research_usage_reconciled_at' => now()])->save();
        });
    }

    /** @return array{result_limit:int,run_limit:int} */
    protected function settings(int $tenantId): array
    {
        $setting = TenantWholesaleSetting::query()->forAllTenants()->where('tenant_id', $tenantId)->first();

        return [
            'result_limit' => max(1, (int) ($setting?->prospect_daily_research_limit ?? 25)),
            'run_limit' => max(1, (int) ($setting?->prospect_daily_run_limit ?? 4)),
        ];
    }
}
