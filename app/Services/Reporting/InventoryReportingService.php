<?php

namespace App\Services\Reporting;

use App\Services\Inventory\InventoryService;
use Illuminate\Support\Collection;

class InventoryReportingService
{
    public function __construct(
        protected DemandReportingService $demandReporting,
        protected InventoryService $inventoryService,
        protected AnalyticsComparisonService $comparisonService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function reorderRiskInputs(string $state = 'current', int $weeks = 4, ?string $channel = null): array
    {
        $oilDemand = $this->demandReporting->explodedOilDemand($state, $weeks, $channel);
        $waxDemand = $this->demandReporting->waxDemand($state, $weeks, $channel);

        $snapshot = $this->buildRiskSnapshotFromDemand($oilDemand, $waxDemand);

        return [
            'state' => (string) ($oilDemand['state'] ?? $state),
            'window' => $oilDemand['window'] ?? [],
            'channel' => $oilDemand['channel'] ?? $channel,
            'oil' => $snapshot['oil'],
            'wax' => $snapshot['wax'],
        ];
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    public function reorderRiskWithComparison(array $timeframe, string $state = 'current', ?string $channel = null): array
    {
        $oilDemandBundle = $this->demandReporting->explodedOilDemandWithComparison($state, $timeframe, $channel);
        $waxDemandBundle = $this->demandReporting->waxDemandWithComparison($state, $timeframe, $channel);

        $primary = $this->buildRiskSnapshotFromDemand(
            (array) ($oilDemandBundle['primary'] ?? []),
            (array) ($waxDemandBundle['primary'] ?? [])
        );

        $comparison = null;
        if (is_array($oilDemandBundle['comparison'] ?? null) && is_array($waxDemandBundle['comparison'] ?? null)) {
            $comparison = $this->buildRiskSnapshotFromDemand(
                (array) ($oilDemandBundle['comparison'] ?? []),
                (array) ($waxDemandBundle['comparison'] ?? [])
            );
        }

        return [
            'state' => (string) ($oilDemandBundle['state'] ?? $state),
            'channel' => $oilDemandBundle['channel'] ?? $channel,
            'timeframe' => $oilDemandBundle['timeframe'] ?? [],
            'primary' => $primary,
            'comparison' => $comparison,
            'delta' => $this->comparisonService->compareTotals(
                primaryTotals: $this->totalsForRiskSnapshot($primary),
                comparisonTotals: is_array($comparison) ? $this->totalsForRiskSnapshot($comparison) : null,
                keys: [
                    'oil_demand_grams',
                    'wax_demand_grams',
                    'oil_reorder_count',
                    'oil_low_count',
                    'wax_reorder_count',
                    'wax_low_count',
                ]
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $oilDemand
     * @param  array<string,mixed>  $waxDemand
     * @return array<string,mixed>
     */
    protected function buildRiskSnapshotFromDemand(array $oilDemand, array $waxDemand): array
    {
        $demandByOilId = collect($oilDemand['rows'] ?? [])
            ->mapWithKeys(fn (array $row): array => [
                (int) ($row['base_oil_id'] ?? 0) => (float) ($row['grams'] ?? 0),
            ])
            ->filter(fn (float $grams, int $oilId): bool => $oilId > 0 && $grams > 0)
            ->all();

        $oilRows = collect($this->inventoryService->evaluateDemandAgainstOilInventory($demandByOilId))
            ->map(function (array $row): array {
                $status = (string) data_get($row, 'state_after_demand.status', 'ok');
                $row['risk_level'] = $status;
                $row['risk_score'] = $this->riskScore($status);

                return $row;
            })
            ->sortByDesc(fn (array $row): array => [
                (int) ($row['risk_score'] ?? 0),
                (float) data_get($row, 'demand_grams', 0),
            ])
            ->values();

        $totalWaxDemand = (float) data_get($waxDemand, 'totals.wax_grams', 0);
        $waxRows = $this->inventoryService->waxRows()
            ->map(function (array $row) use ($totalWaxDemand): array {
                $onHand = (float) ($row['on_hand_grams'] ?? 0);
                $threshold = (float) ($row['reorder_threshold_grams'] ?? 0);
                $projected = max(0.0, $onHand - $totalWaxDemand);

                $now = $this->inventoryService->evaluateReorderState($onHand, $threshold);
                $after = $this->inventoryService->evaluateReorderState($projected, $threshold);
                $status = (string) ($after['status'] ?? 'ok');

                return [
                    'wax_inventory_id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['name'] ?? 'Wax'),
                    'demand_grams' => round($totalWaxDemand, 2),
                    'on_hand_grams' => round($onHand, 2),
                    'projected_on_hand_grams' => round($projected, 2),
                    'reorder_threshold_grams' => round($threshold, 2),
                    'state_now' => $now,
                    'state_after_demand' => $after,
                    'risk_level' => $status,
                    'risk_score' => $this->riskScore($status),
                ];
            })
            ->sortByDesc(fn (array $row): int => (int) ($row['risk_score'] ?? 0))
            ->values();

        return [
            'oil' => [
                'rows' => $oilRows->all(),
                'summary' => $this->summarizeRiskRows($oilRows),
                'demand_totals' => $oilDemand['totals'] ?? [],
            ],
            'wax' => [
                'rows' => $waxRows->all(),
                'summary' => $this->summarizeRiskRows($waxRows),
                'demand_totals' => $waxDemand['totals'] ?? [],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,float>
     */
    protected function totalsForRiskSnapshot(array $snapshot): array
    {
        return [
            'oil_demand_grams' => (float) data_get($snapshot, 'oil.demand_totals.oil_grams', 0),
            'wax_demand_grams' => (float) data_get($snapshot, 'wax.demand_totals.wax_grams', 0),
            'oil_reorder_count' => (float) data_get($snapshot, 'oil.summary.reorder_count', 0),
            'oil_low_count' => (float) data_get($snapshot, 'oil.summary.low_count', 0),
            'wax_reorder_count' => (float) data_get($snapshot, 'wax.summary.reorder_count', 0),
            'wax_low_count' => (float) data_get($snapshot, 'wax.summary.low_count', 0),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<string,int>
     */
    protected function summarizeRiskRows(Collection $rows): array
    {
        return [
            'ok_count' => $rows->where('risk_level', 'ok')->count(),
            'low_count' => $rows->where('risk_level', 'low')->count(),
            'reorder_count' => $rows->where('risk_level', 'reorder')->count(),
            'row_count' => $rows->count(),
        ];
    }

    protected function riskScore(string $status): int
    {
        return match ($status) {
            'reorder' => 3,
            'low' => 2,
            default => 1,
        };
    }
}
