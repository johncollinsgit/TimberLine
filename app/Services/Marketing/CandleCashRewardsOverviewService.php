<?php

namespace App\Services\Marketing;

use App\Models\CandleCashReward;
use App\Models\CandleCashTask;

class CandleCashRewardsOverviewService
{
    public function __construct(
        protected CandleCashService $candleCashService
    ) {
    }

    /**
     * @return array{
     *     program_name:string,
     *     measurement_label:string,
     *     earning_rules_active:bool,
     *     earning_rule_count:int,
     *     redeem_rules_active:bool,
     *     redeem_rule_count:int,
     *     program_summary:string,
     *     earning_modes:array<int,string>,
     *     earn_preview:array<int,array{title:string,detail:string}>,
     *     redeem_preview:array<int,array{title:string,detail:string}>
     * }
     */
    public function build(): array
    {
        $activeTasks = CandleCashTask::query()
            ->where('enabled', true)
            ->whereNull('archived_at')
            ->orderBy('display_order')
            ->get(['id', 'title', 'handle', 'verification_mode', 'reward_amount']);

        $rewards = CandleCashReward::query()
            ->orderBy('points_cost')
            ->orderBy('id')
            ->get(['id', 'name', 'description', 'points_cost', 'reward_type', 'reward_value', 'is_active']);

        $activeRewards = $rewards->where('is_active', true)->values();
        $earningModes = $activeTasks
            ->pluck('verification_mode')
            ->filter()
            ->map(fn (?string $value): string => str($value)->replace('_', ' ')->headline()->toString())
            ->unique()
            ->values();

        $primaryReward = $activeRewards->first();
        $programSummary = $primaryReward
            ? 'Customers earn Candle Cash through live tasks, then redeem Candle Cash for rewards like '.$primaryReward->name.'.'
            : 'Customers earn Candle Cash through live tasks and can redeem it against the reward rows configured in Backstage.';

        return [
            'program_name' => 'Candle Cash',
            'measurement_label' => '1 Candle Cash = 1 Candle Cash',
            'earning_rules_active' => $activeTasks->isNotEmpty(),
            'earning_rule_count' => $activeTasks->count(),
            'redeem_rules_active' => $activeRewards->isNotEmpty(),
            'redeem_rule_count' => $activeRewards->count(),
            'program_summary' => $programSummary,
            'earning_modes' => $earningModes->all(),
            'earn_preview' => $activeTasks->take(3)->map(fn (CandleCashTask $task): array => [
                'title' => (string) $task->title,
                'detail' => $this->candleCashService->formatCandleCash((float) $task->reward_amount),
            ])->values()->all(),
            'redeem_preview' => $activeRewards->take(3)->map(fn (CandleCashReward $reward): array => [
                'title' => (string) $reward->name,
                'detail' => $this->candleCashService->formatCandleCash($this->candleCashService->amountFromPoints((int) $reward->points_cost)),
            ])->values()->all(),
        ];
    }
}
