<?php

namespace App\Services\Reporting;

use App\Models\OrderLine;
use App\Models\OrderLineScentSplit;
use App\Models\PourBatchLine;
use App\Models\Scent;
use App\Models\ScentRecipeComponent;
use App\Models\Size;
use App\Services\Pouring\MeasurementResolver;
use App\Services\ScentGovernance\ScentRecipeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DemandReportingService
{
    /** @var array<int,string> */
    protected array $currentStatuses = ['submitted_to_pouring', 'pouring', 'brought_down', 'verified'];

    public function __construct(
        protected MeasurementResolver $measurementResolver,
        protected ScentRecipeService $scentRecipeService,
        protected AnalyticsComparisonService $comparisonService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function forecastedScentDemand(int $weeks = 4, ?string $channel = null): array
    {
        return $this->scentDemandByState('forecast', $weeks, $channel);
    }

    /**
     * @return array<string,mixed>
     */
    public function currentScentDemand(int $weeks = 4, ?string $channel = null): array
    {
        return $this->scentDemandByState('current', $weeks, $channel);
    }

    /**
     * @return array<string,mixed>
     */
    public function actualScentDemand(int $weeks = 4, ?string $channel = null): array
    {
        return $this->scentDemandByState('actual', $weeks, $channel);
    }

    /**
     * @return array<string,mixed>
     */
    public function scentDemandByState(string $state, int $weeks = 4, ?string $channel = null): array
    {
        $state = $this->normalizeState($state);
        [$from, $to] = $this->window($weeks);

        return $this->scentDemandByWindow($state, $from, $to, $channel, ['weeks' => $weeks]);
    }

    /**
     * @param  array<string,mixed>  $windowMeta
     * @return array<string,mixed>
     */
    public function scentDemandByWindow(
        string $state,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $channel = null,
        array $windowMeta = []
    ): array {
        $state = $this->normalizeState($state);
        $channelFilter = $this->normalizeChannel($channel);

        return $this->buildScentSnapshot($state, $from, $to, $channelFilter, $windowMeta);
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    public function scentDemandWithComparison(string $state, array $timeframe, ?string $channel = null): array
    {
        $state = $this->normalizeState($state);
        $channelFilter = $this->normalizeChannel($channel);

        /** @var CarbonImmutable $primaryFrom */
        $primaryFrom = data_get($timeframe, 'primary.from', CarbonImmutable::now()->startOfDay());
        /** @var CarbonImmutable $primaryTo */
        $primaryTo = data_get($timeframe, 'primary.to', CarbonImmutable::now()->endOfDay());

        $primary = $this->buildScentSnapshot(
            $state,
            $primaryFrom,
            $primaryTo,
            $channelFilter,
            ['label' => data_get($timeframe, 'labels.primary')]
        );

        $comparison = null;
        if (data_get($timeframe, 'comparison.from') instanceof CarbonImmutable && data_get($timeframe, 'comparison.to') instanceof CarbonImmutable) {
            /** @var CarbonImmutable $comparisonFrom */
            $comparisonFrom = data_get($timeframe, 'comparison.from');
            /** @var CarbonImmutable $comparisonTo */
            $comparisonTo = data_get($timeframe, 'comparison.to');

            $comparison = $this->buildScentSnapshot(
                $state,
                $comparisonFrom,
                $comparisonTo,
                $channelFilter,
                ['label' => data_get($timeframe, 'labels.comparison')]
            );
        }

        return [
            'state' => $state,
            'channel' => $channelFilter,
            'timeframe' => $this->serializeTimeframe($timeframe),
            'primary' => $primary,
            'comparison' => $comparison,
            'delta' => $this->comparisonService->compareTotals(
                primaryTotals: (array) ($primary['totals'] ?? []),
                comparisonTotals: is_array($comparison) ? (array) ($comparison['totals'] ?? []) : null,
                keys: ['units', 'wax_grams', 'oil_grams']
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function explodedOilDemand(string $state, int $weeks = 4, ?string $channel = null): array
    {
        $snapshot = $this->scentDemandByState($state, $weeks, $channel);

        return $this->explodeOilFromSnapshot($snapshot, $state);
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    public function explodedOilDemandWithComparison(string $state, array $timeframe, ?string $channel = null): array
    {
        $state = $this->normalizeState($state);
        $channelFilter = $this->normalizeChannel($channel);

        /** @var CarbonImmutable $primaryFrom */
        $primaryFrom = data_get($timeframe, 'primary.from', CarbonImmutable::now()->startOfDay());
        /** @var CarbonImmutable $primaryTo */
        $primaryTo = data_get($timeframe, 'primary.to', CarbonImmutable::now()->endOfDay());

        $primarySnapshot = $this->buildScentSnapshot(
            $state,
            $primaryFrom,
            $primaryTo,
            $channelFilter,
            ['label' => data_get($timeframe, 'labels.primary')]
        );
        $primary = $this->explodeOilFromSnapshot($primarySnapshot, $state);

        $comparison = null;
        if (data_get($timeframe, 'comparison.from') instanceof CarbonImmutable && data_get($timeframe, 'comparison.to') instanceof CarbonImmutable) {
            /** @var CarbonImmutable $comparisonFrom */
            $comparisonFrom = data_get($timeframe, 'comparison.from');
            /** @var CarbonImmutable $comparisonTo */
            $comparisonTo = data_get($timeframe, 'comparison.to');

            $comparisonSnapshot = $this->buildScentSnapshot(
                $state,
                $comparisonFrom,
                $comparisonTo,
                $channelFilter,
                ['label' => data_get($timeframe, 'labels.comparison')]
            );
            $comparison = $this->explodeOilFromSnapshot($comparisonSnapshot, $state);
        }

        return [
            'state' => $state,
            'channel' => $channelFilter,
            'timeframe' => $this->serializeTimeframe($timeframe),
            'primary' => $primary,
            'comparison' => $comparison,
            'delta' => $this->comparisonService->compareTotals(
                primaryTotals: (array) ($primary['totals'] ?? []),
                comparisonTotals: is_array($comparison) ? (array) ($comparison['totals'] ?? []) : null,
                keys: ['oil_grams']
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function waxDemand(string $state, int $weeks = 4, ?string $channel = null): array
    {
        $snapshot = $this->scentDemandByState($state, $weeks, $channel);

        return $this->waxFromSnapshot($snapshot, $state);
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    public function waxDemandWithComparison(string $state, array $timeframe, ?string $channel = null): array
    {
        $state = $this->normalizeState($state);
        $channelFilter = $this->normalizeChannel($channel);

        /** @var CarbonImmutable $primaryFrom */
        $primaryFrom = data_get($timeframe, 'primary.from', CarbonImmutable::now()->startOfDay());
        /** @var CarbonImmutable $primaryTo */
        $primaryTo = data_get($timeframe, 'primary.to', CarbonImmutable::now()->endOfDay());

        $primarySnapshot = $this->buildScentSnapshot(
            $state,
            $primaryFrom,
            $primaryTo,
            $channelFilter,
            ['label' => data_get($timeframe, 'labels.primary')]
        );
        $primary = $this->waxFromSnapshot($primarySnapshot, $state);

        $comparison = null;
        if (data_get($timeframe, 'comparison.from') instanceof CarbonImmutable && data_get($timeframe, 'comparison.to') instanceof CarbonImmutable) {
            /** @var CarbonImmutable $comparisonFrom */
            $comparisonFrom = data_get($timeframe, 'comparison.from');
            /** @var CarbonImmutable $comparisonTo */
            $comparisonTo = data_get($timeframe, 'comparison.to');

            $comparisonSnapshot = $this->buildScentSnapshot(
                $state,
                $comparisonFrom,
                $comparisonTo,
                $channelFilter,
                ['label' => data_get($timeframe, 'labels.comparison')]
            );
            $comparison = $this->waxFromSnapshot($comparisonSnapshot, $state);
        }

        return [
            'state' => $state,
            'channel' => $channelFilter,
            'timeframe' => $this->serializeTimeframe($timeframe),
            'primary' => $primary,
            'comparison' => $comparison,
            'delta' => $this->comparisonService->compareTotals(
                primaryTotals: (array) ($primary['totals'] ?? []),
                comparisonTotals: is_array($comparison) ? (array) ($comparison['totals'] ?? []) : null,
                keys: ['wax_grams']
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function explodedOilDemandByWindow(
        string $state,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $channel = null,
        array $windowMeta = []
    ): array {
        $state = $this->normalizeState($state);
        $channelFilter = $this->normalizeChannel($channel);
        $snapshot = $this->buildScentSnapshot($state, $from, $to, $channelFilter, $windowMeta);

        return $this->explodeOilFromSnapshot($snapshot, $state);
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<int,array<string,mixed>>
     */
    public function trendSeries(
        string $state,
        array $timeframe,
        ?string $channel = null,
        string $metric = 'units',
        int $points = 8
    ): array {
        $state = $this->normalizeState($state);
        $metric = in_array($metric, ['units', 'wax_grams', 'oil_grams'], true) ? $metric : 'units';
        $channelFilter = $this->normalizeChannel($channel);
        $points = max(2, min(24, $points));

        /** @var CarbonImmutable $from */
        $from = data_get($timeframe, 'primary.from', CarbonImmutable::now()->startOfDay());
        /** @var CarbonImmutable $to */
        $to = data_get($timeframe, 'primary.to', CarbonImmutable::now()->endOfDay());

        $totalDays = max(1, $to->diffInDays($from) + 1);
        $bucketDays = max(1, (int) ceil($totalDays / $points));

        $series = [];
        $cursor = $from->startOfDay();
        while ($cursor->lte($to)) {
            $bucketEnd = $cursor->addDays($bucketDays - 1)->endOfDay();
            if ($bucketEnd->gt($to)) {
                $bucketEnd = $to->copy();
            }

            $snapshot = $this->buildScentSnapshot(
                $state,
                $cursor,
                $bucketEnd,
                $channelFilter,
                ['label' => $cursor->format('M j').' - '.$bucketEnd->format('M j')]
            );

            $series[] = [
                'label' => (string) data_get($snapshot, 'window.label', $cursor->format('M j').' - '.$bucketEnd->format('M j')),
                'from' => $cursor->toDateString(),
                'to' => $bucketEnd->toDateString(),
                'value' => round((float) data_get($snapshot, 'totals.'.$metric, 0), 2),
            ];

            $cursor = $bucketEnd->addDay()->startOfDay();
        }

        return $series;
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    public function oilContributorsWithComparison(
        string $state,
        array $timeframe,
        ?string $channel = null,
        ?int $focusOilId = null,
        int $limit = 5
    ): array {
        $state = $this->normalizeState($state);
        $channelFilter = $this->normalizeChannel($channel);

        /** @var CarbonImmutable $primaryFrom */
        $primaryFrom = data_get($timeframe, 'primary.from', CarbonImmutable::now()->startOfDay());
        /** @var CarbonImmutable $primaryTo */
        $primaryTo = data_get($timeframe, 'primary.to', CarbonImmutable::now()->endOfDay());

        $primarySnapshot = $this->buildScentSnapshot(
            $state,
            $primaryFrom,
            $primaryTo,
            $channelFilter,
            ['label' => data_get($timeframe, 'labels.primary')]
        );
        $primary = $this->buildOilContributorsFromSnapshot($primarySnapshot, $state, $focusOilId, $limit);

        $comparison = null;
        if (data_get($timeframe, 'comparison.from') instanceof CarbonImmutable && data_get($timeframe, 'comparison.to') instanceof CarbonImmutable) {
            /** @var CarbonImmutable $comparisonFrom */
            $comparisonFrom = data_get($timeframe, 'comparison.from');
            /** @var CarbonImmutable $comparisonTo */
            $comparisonTo = data_get($timeframe, 'comparison.to');
            $comparisonSnapshot = $this->buildScentSnapshot(
                $state,
                $comparisonFrom,
                $comparisonTo,
                $channelFilter,
                ['label' => data_get($timeframe, 'labels.comparison')]
            );
            $comparison = $this->buildOilContributorsFromSnapshot($comparisonSnapshot, $state, $focusOilId, $limit);
        }

        return [
            'state' => $state,
            'channel' => $channelFilter,
            'timeframe' => $this->serializeTimeframe($timeframe),
            'primary' => $primary,
            'comparison' => $comparison,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function blendTemplateDemand(string $state, int $weeks = 4, ?string $channel = null): array
    {
        $snapshot = $this->scentDemandByState($state, $weeks, $channel);
        $rows = collect($snapshot['rows'] ?? []);
        $scentIds = $rows->pluck('scent_id')->filter(fn ($id): bool => (int) $id > 0)->unique()->values();

        $scents = Scent::query()
            ->with(['currentRecipe.components.blendTemplate', 'activeRecipe.components.blendTemplate'])
            ->whereIn('id', $scentIds)
            ->get()
            ->keyBy('id');

        /** @var array<int,array<string,mixed>> $buckets */
        $buckets = [];

        foreach ($rows as $row) {
            $scentId = (int) ($row['scent_id'] ?? 0);
            $oilGrams = (float) ($row['oil_grams'] ?? 0);
            if ($scentId <= 0 || $oilGrams <= 0) {
                continue;
            }

            /** @var Scent|null $scent */
            $scent = $scents->get($scentId);
            $recipe = $scent?->currentRecipe ?: $scent?->activeRecipe;
            $components = collect($recipe?->components ?? [])->values();
            if ($components->isEmpty()) {
                continue;
            }

            $shares = $this->normalizeShares(
                rows: $components->all(),
                percentage: fn ($component): ?float => blank($component->percentage ?? null) ? null : (float) $component->percentage,
                parts: fn ($component): ?float => blank($component->parts ?? null) ? null : (float) $component->parts
            );

            foreach ($components as $index => $component) {
                if ((string) ($component->component_type ?? '') !== ScentRecipeComponent::TYPE_BLEND_TEMPLATE) {
                    continue;
                }

                $blendId = (int) ($component->blend_template_id ?? 0);
                if ($blendId <= 0) {
                    continue;
                }

                $share = (float) ($shares[$index] ?? 0);
                if ($share <= 0) {
                    continue;
                }

                $bucket = $buckets[$blendId] ?? [
                    'blend_template_id' => $blendId,
                    'blend_template_name' => (string) ($component->blendTemplate?->name ?? ('Blend #'.$blendId)),
                    'oil_grams' => 0.0,
                    'state' => (string) ($snapshot['state'] ?? $state),
                    'channel' => (string) ($snapshot['channel'] ?? 'all'),
                ];
                $bucket['oil_grams'] = round((float) $bucket['oil_grams'] + ($oilGrams * $share), 4);
                $buckets[$blendId] = $bucket;
            }
        }

        $normalizedRows = collect(array_values($buckets))
            ->sortByDesc('oil_grams')
            ->values()
            ->all();

        return [
            'state' => (string) ($snapshot['state'] ?? $state),
            'window' => $snapshot['window'] ?? [],
            'channel' => (string) ($snapshot['channel'] ?? 'all'),
            'rows' => $normalizedRows,
            'totals' => [
                'oil_grams' => round((float) collect($normalizedRows)->sum('oil_grams'), 4),
                'blend_template_count' => count($normalizedRows),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $windowMeta
     * @return array<string,mixed>
     */
    protected function buildScentSnapshot(
        string $state,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $channel,
        array $windowMeta = []
    ): array {
        $rows = $state === 'actual'
            ? $this->actualDemandRows($from, $to, $channel)
            : $this->orderDemandRows($state, $from, $to, $channel);

        return [
            'state' => $state,
            'window' => array_merge([
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ], $windowMeta),
            'channel' => $channel,
            'rows' => $rows->values()->all(),
            'totals' => [
                'units' => (int) round($rows->sum(fn (array $row): float => (float) ($row['units'] ?? 0))),
                'wax_grams' => round((float) $rows->sum(fn (array $row): float => (float) ($row['wax_grams'] ?? 0)), 2),
                'oil_grams' => round((float) $rows->sum(fn (array $row): float => (float) ($row['oil_grams'] ?? 0)), 2),
                'row_count' => $rows->count(),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    protected function explodeOilFromSnapshot(array $snapshot, string $state): array
    {
        $rows = collect($snapshot['rows'] ?? []);

        /** @var array<int,array<string,mixed>> $oilBuckets */
        $oilBuckets = [];
        $unresolved = [];

        foreach ($rows as $row) {
            $scentId = (int) ($row['scent_id'] ?? 0);
            $oilDemandGrams = (float) ($row['oil_grams'] ?? 0);
            if ($scentId <= 0 || $oilDemandGrams <= 0) {
                continue;
            }

            $flattened = $this->scentRecipeService->flattenForScent(
                scent: $scentId,
                totalGrams: $oilDemandGrams,
                allowLegacyFallback: true,
                includeTree: false
            );

            $components = collect($flattened['components'] ?? []);
            if ($components->isEmpty()) {
                $unresolved[] = [
                    'scent_id' => $scentId,
                    'scent_name' => (string) ($row['scent_name'] ?? ''),
                    'reason' => 'No flattened oil components returned for scent demand row.',
                ];

                continue;
            }

            foreach ($components as $component) {
                $oilId = (int) ($component['base_oil_id'] ?? 0);
                if ($oilId <= 0) {
                    continue;
                }

                $bucket = $oilBuckets[$oilId] ?? [
                    'base_oil_id' => $oilId,
                    'base_oil_name' => (string) ($component['base_oil_name'] ?? 'Oil #'.$oilId),
                    'grams' => 0.0,
                    'percent_of_total' => 0.0,
                    'state' => (string) ($snapshot['state'] ?? $state),
                    'channel' => (string) ($snapshot['channel'] ?? 'all'),
                ];

                $bucket['grams'] = round((float) $bucket['grams'] + (float) ($component['grams'] ?? 0), 4);
                $oilBuckets[$oilId] = $bucket;
            }
        }

        $total = round((float) collect($oilBuckets)->sum('grams'), 4);
        foreach ($oilBuckets as $oilId => $bucket) {
            $oilBuckets[$oilId]['percent_of_total'] = $total > 0
                ? round(((float) $bucket['grams'] / $total) * 100, 4)
                : 0.0;
        }

        $normalizedRows = collect(array_values($oilBuckets))
            ->sortByDesc('grams')
            ->values()
            ->all();

        return [
            'state' => (string) ($snapshot['state'] ?? $state),
            'window' => $snapshot['window'] ?? [],
            'channel' => (string) ($snapshot['channel'] ?? 'all'),
            'rows' => $normalizedRows,
            'totals' => [
                'oil_grams' => $total,
                'oil_count' => count($normalizedRows),
            ],
            'unresolved' => $unresolved,
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    protected function waxFromSnapshot(array $snapshot, string $state): array
    {
        $rows = collect($snapshot['rows'] ?? [])
            ->groupBy(fn (array $row): string => (string) ($row['channel'] ?? 'unknown'))
            ->map(fn (Collection $group, string $channelKey): array => [
                'channel' => $channelKey,
                'wax_grams' => round((float) $group->sum(fn (array $row): float => (float) ($row['wax_grams'] ?? 0)), 2),
                'units' => (int) round($group->sum(fn (array $row): float => (float) ($row['units'] ?? 0))),
            ])
            ->values()
            ->all();

        return [
            'state' => (string) ($snapshot['state'] ?? $state),
            'window' => $snapshot['window'] ?? [],
            'channel' => (string) ($snapshot['channel'] ?? 'all'),
            'rows' => $rows,
            'totals' => [
                'wax_grams' => round((float) collect($rows)->sum('wax_grams'), 2),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    protected function buildOilContributorsFromSnapshot(
        array $snapshot,
        string $state,
        ?int $focusOilId = null,
        int $limit = 5
    ): array {
        $rows = collect($snapshot['rows'] ?? []);
        $limit = max(1, $limit);

        /** @var array<int,array<string,mixed>> $buckets */
        $buckets = [];

        foreach ($rows as $row) {
            $scentId = (int) ($row['scent_id'] ?? 0);
            $scentName = (string) ($row['scent_name'] ?? 'Unknown');
            $oilDemandGrams = (float) ($row['oil_grams'] ?? 0);
            if ($scentId <= 0 || $oilDemandGrams <= 0) {
                continue;
            }

            $flattened = $this->scentRecipeService->flattenForScent(
                scent: $scentId,
                totalGrams: $oilDemandGrams,
                allowLegacyFallback: true,
                includeTree: false
            );

            foreach (collect($flattened['components'] ?? []) as $component) {
                $oilId = (int) ($component['base_oil_id'] ?? 0);
                if ($oilId <= 0) {
                    continue;
                }
                if ($focusOilId !== null && $focusOilId > 0 && $oilId !== $focusOilId) {
                    continue;
                }

                $bucket = $buckets[$oilId] ?? [
                    'base_oil_id' => $oilId,
                    'base_oil_name' => (string) ($component['base_oil_name'] ?? 'Oil #'.$oilId),
                    'total_grams' => 0.0,
                    'contributors' => [],
                ];

                $grams = round((float) ($component['grams'] ?? 0), 4);
                $bucket['total_grams'] = round((float) $bucket['total_grams'] + $grams, 4);
                $bucket['contributors'][$scentId] = [
                    'scent_id' => $scentId,
                    'scent_name' => $scentName,
                    'grams' => round((float) (($bucket['contributors'][$scentId]['grams'] ?? 0) + $grams), 4),
                ];
                $buckets[$oilId] = $bucket;
            }
        }

        $oilRows = collect(array_values($buckets))
            ->map(function (array $row) use ($limit): array {
                $contributors = collect(array_values($row['contributors'] ?? []))
                    ->sortByDesc('grams')
                    ->take($limit)
                    ->values()
                    ->all();

                return [
                    'base_oil_id' => (int) ($row['base_oil_id'] ?? 0),
                    'base_oil_name' => (string) ($row['base_oil_name'] ?? ''),
                    'total_grams' => round((float) ($row['total_grams'] ?? 0), 4),
                    'contributors' => $contributors,
                ];
            })
            ->sortByDesc('total_grams')
            ->values()
            ->all();

        return [
            'state' => (string) ($snapshot['state'] ?? $state),
            'window' => $snapshot['window'] ?? [],
            'channel' => (string) ($snapshot['channel'] ?? 'all'),
            'rows' => $oilRows,
            'totals' => [
                'oil_count' => count($oilRows),
                'oil_grams' => round((float) collect($oilRows)->sum('total_grams'), 4),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    protected function serializeTimeframe(array $timeframe): array
    {
        return [
            'time_mode' => (string) ($timeframe['time_mode'] ?? 'rolling'),
            'preset' => (string) ($timeframe['preset'] ?? 'last_30_days'),
            'comparison_mode' => (string) ($timeframe['comparison_mode'] ?? 'none'),
            'labels' => [
                'primary' => (string) (data_get($timeframe, 'labels.primary') ?? ''),
                'comparison' => data_get($timeframe, 'labels.comparison'),
            ],
            'primary' => [
                'from' => data_get($timeframe, 'primary.from_date'),
                'to' => data_get($timeframe, 'primary.to_date'),
                'days' => (int) (data_get($timeframe, 'primary.days') ?? 0),
            ],
            'comparison' => data_get($timeframe, 'comparison') ? [
                'from' => data_get($timeframe, 'comparison.from_date'),
                'to' => data_get($timeframe, 'comparison.to_date'),
                'days' => (int) (data_get($timeframe, 'comparison.days') ?? 0),
            ] : null,
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function orderDemandRows(string $state, CarbonImmutable $from, CarbonImmutable $to, ?string $channel): Collection
    {
        $aggregate = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->whereNotNull('order_lines.scent_id')
            ->whereNotNull('order_lines.size_id')
            ->whereBetween('orders.due_at', [$from->toDateTimeString(), $to->toDateTimeString()]);

        if (Schema::hasTable('order_line_scent_splits')) {
            $aggregate->whereDoesntHave('scentSplits');
        }

        if ($channel !== null) {
            $aggregate->where('orders.order_type', $channel);
        }

        if ($state === 'forecast') {
            $aggregate
                ->whereNull('orders.published_at')
                ->whereNotIn('orders.status', ['cancelled', 'completed', 'brought_down', 'verified']);
        } else {
            $aggregate
                ->whereNotNull('orders.published_at')
                ->whereIn('orders.status', $this->currentStatuses);
        }

        $sized = $aggregate
            ->selectRaw('order_lines.scent_id as scent_id, coalesce(orders.order_type, "retail") as channel_key, order_lines.size_id as size_id, SUM(coalesce(order_lines.ordered_qty, order_lines.quantity, 0) + coalesce(order_lines.extra_qty, 0)) as qty')
            ->groupBy('order_lines.scent_id', 'orders.order_type', 'order_lines.size_id')
            ->get();

        if (Schema::hasTable('order_line_scent_splits')) {
            $splitAggregate = OrderLineScentSplit::query()
                ->join('order_lines', 'order_lines.id', '=', 'order_line_scent_splits.order_line_id')
                ->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->whereNotNull('order_line_scent_splits.scent_id')
                ->whereNotNull('order_lines.size_id')
                ->whereBetween('orders.due_at', [$from->toDateTimeString(), $to->toDateTimeString()]);

            if ($channel !== null) {
                $splitAggregate->where('orders.order_type', $channel);
            }

            if ($state === 'forecast') {
                $splitAggregate
                    ->whereNull('orders.published_at')
                    ->whereNotIn('orders.status', ['cancelled', 'completed', 'brought_down', 'verified']);
            } else {
                $splitAggregate
                    ->whereNotNull('orders.published_at')
                    ->whereIn('orders.status', $this->currentStatuses);
            }

            $splitSized = $splitAggregate
                ->selectRaw('order_line_scent_splits.scent_id as scent_id, coalesce(orders.order_type, "retail") as channel_key, order_lines.size_id as size_id, SUM(coalesce(order_line_scent_splits.quantity, 0)) as qty')
                ->groupBy('order_line_scent_splits.scent_id', 'orders.order_type', 'order_lines.size_id')
                ->get();

            if ($splitSized->isNotEmpty()) {
                $sized = $sized->concat($splitSized)->values();
            }
        }

        if ($sized->isEmpty()) {
            return collect();
        }

        $countQuery = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->whereNotNull('order_lines.scent_id')
            ->whereNotNull('order_lines.size_id')
            ->whereBetween('orders.due_at', [$from->toDateTimeString(), $to->toDateTimeString()]);

        if (Schema::hasTable('order_line_scent_splits')) {
            $countQuery->whereDoesntHave('scentSplits');
        }

        if ($channel !== null) {
            $countQuery->where('orders.order_type', $channel);
        }

        if ($state === 'forecast') {
            $countQuery
                ->whereNull('orders.published_at')
                ->whereNotIn('orders.status', ['cancelled', 'completed', 'brought_down', 'verified']);
        } else {
            $countQuery
                ->whereNotNull('orders.published_at')
                ->whereIn('orders.status', $this->currentStatuses);
        }

        $orderCounts = $countQuery
            ->selectRaw('order_lines.scent_id as scent_id, coalesce(orders.order_type, "retail") as channel_key, COUNT(DISTINCT orders.id) as order_count')
            ->groupBy('order_lines.scent_id', 'orders.order_type')
            ->get()
            ->keyBy(fn ($row): string => ((int) $row->scent_id).'|'.((string) $row->channel_key))
            ->all();

        if (Schema::hasTable('order_line_scent_splits')) {
            $splitCountQuery = OrderLineScentSplit::query()
                ->join('order_lines', 'order_lines.id', '=', 'order_line_scent_splits.order_line_id')
                ->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->whereNotNull('order_line_scent_splits.scent_id')
                ->whereNotNull('order_lines.size_id')
                ->whereBetween('orders.due_at', [$from->toDateTimeString(), $to->toDateTimeString()]);

            if ($channel !== null) {
                $splitCountQuery->where('orders.order_type', $channel);
            }

            if ($state === 'forecast') {
                $splitCountQuery
                    ->whereNull('orders.published_at')
                    ->whereNotIn('orders.status', ['cancelled', 'completed', 'brought_down', 'verified']);
            } else {
                $splitCountQuery
                    ->whereNotNull('orders.published_at')
                    ->whereIn('orders.status', $this->currentStatuses);
            }

            $splitCounts = $splitCountQuery
                ->selectRaw('order_line_scent_splits.scent_id as scent_id, coalesce(orders.order_type, "retail") as channel_key, COUNT(DISTINCT orders.id) as order_count')
                ->groupBy('order_line_scent_splits.scent_id', 'orders.order_type')
                ->get();

            foreach ($splitCounts as $row) {
                $key = ((int) ($row->scent_id ?? 0)).'|'.((string) ($row->channel_key ?? 'retail'));
                if (! isset($orderCounts[$key])) {
                    $orderCounts[$key] = $row;
                    continue;
                }

                $orderCounts[$key]->order_count = max(
                    (int) ($orderCounts[$key]->order_count ?? 0),
                    (int) ($row->order_count ?? 0)
                );
            }
        }

        $sizeMap = Size::query()->whereIn('id', $sized->pluck('size_id')->unique()->values())->get()->keyBy('id');
        $scentMap = Scent::query()->whereIn('id', $sized->pluck('scent_id')->unique()->values())->get()->keyBy('id');

        /** @var array<string,array<string,mixed>> $rows */
        $rows = [];
        foreach ($sized as $group) {
            $scentId = (int) ($group->scent_id ?? 0);
            $sizeId = (int) ($group->size_id ?? 0);
            $qty = max(0, (int) ($group->qty ?? 0));
            $channelKey = $this->normalizeChannel((string) ($group->channel_key ?? 'retail')) ?? 'retail';
            if ($scentId <= 0 || $sizeId <= 0 || $qty <= 0) {
                continue;
            }

            /** @var Size|null $size */
            $size = $sizeMap->get($sizeId);
            $ingredients = $size
                ? $this->measurementResolver->resolveLineIngredients((string) ($size->code ?: $size->label ?: ''), $qty)
                : null;

            $key = $scentId.'|'.$channelKey;
            $rows[$key] ??= [
                'state' => $state,
                'scent_id' => $scentId,
                'scent_name' => (string) ($scentMap->get($scentId)?->display_name ?: $scentMap->get($scentId)?->name ?: 'Scent #'.$scentId),
                'channel' => $channelKey,
                'units' => 0,
                'wax_grams' => 0.0,
                'oil_grams' => 0.0,
                'order_count' => (int) (($orderCounts[$key]->order_count ?? 0)),
            ];

            $rows[$key]['units'] += $qty;
            $rows[$key]['wax_grams'] = round((float) $rows[$key]['wax_grams'] + (float) ($ingredients['wax_grams'] ?? 0), 4);
            $rows[$key]['oil_grams'] = round((float) $rows[$key]['oil_grams'] + (float) ($ingredients['oil_grams'] ?? 0), 4);
        }

        return collect(array_values($rows))
            ->sortByDesc('units')
            ->values();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function actualDemandRows(CarbonImmutable $from, CarbonImmutable $to, ?string $channel): Collection
    {
        $query = PourBatchLine::query()
            ->join('pour_batches', 'pour_batches.id', '=', 'pour_batch_lines.pour_batch_id')
            ->whereNotNull('pour_batch_lines.scent_id')
            ->whereNotNull('pour_batch_lines.size_id')
            ->where(function ($q): void {
                $q->where('pour_batch_lines.status', 'completed')
                    ->orWhereNotNull('pour_batch_lines.completed_at')
                    ->orWhere('pour_batches.status', 'completed')
                    ->orWhereNotNull('pour_batches.completed_at');
            })
            ->whereBetween(
                \DB::raw('coalesce(pour_batch_lines.completed_at, pour_batches.completed_at, pour_batch_lines.created_at)'),
                [$from->toDateTimeString(), $to->toDateTimeString()]
            );

        if ($channel !== null) {
            $query->where('pour_batches.order_type', $channel);
        }

        $rows = $query
            ->selectRaw('pour_batch_lines.scent_id as scent_id, coalesce(pour_batches.order_type, "retail") as channel_key, SUM(coalesce(pour_batch_lines.quantity, 0)) as units, SUM(coalesce(pour_batch_lines.wax_grams, 0)) as wax_grams, SUM(coalesce(pour_batch_lines.oil_grams, 0)) as oil_grams, COUNT(*) as record_count')
            ->groupBy('pour_batch_lines.scent_id', 'pour_batches.order_type')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $scentMap = Scent::query()->whereIn('id', $rows->pluck('scent_id')->unique()->values())->get()->keyBy('id');

        return $rows->map(function ($row) use ($scentMap): array {
            $scentId = (int) ($row->scent_id ?? 0);
            $channelKey = $this->normalizeChannel((string) ($row->channel_key ?? 'retail')) ?? 'retail';

            return [
                'state' => 'actual',
                'scent_id' => $scentId,
                'scent_name' => (string) ($scentMap->get($scentId)?->display_name ?: $scentMap->get($scentId)?->name ?: 'Scent #'.$scentId),
                'channel' => $channelKey,
                'units' => max(0, (int) ($row->units ?? 0)),
                'wax_grams' => round((float) ($row->wax_grams ?? 0), 4),
                'oil_grams' => round((float) ($row->oil_grams ?? 0), 4),
                'order_count' => (int) ($row->record_count ?? 0),
            ];
        })->sortByDesc('units')->values();
    }

    /**
     * @param  array<int,mixed>  $rows
     * @return array<int,float>
     */
    protected function normalizeShares(array $rows, callable $percentage, callable $parts): array
    {
        $count = count($rows);
        if ($count === 0) {
            return [];
        }

        $percentages = [];
        $partsValues = [];
        $missing = [];

        foreach ($rows as $index => $row) {
            $pct = $this->positive($percentage($row));
            $prt = $this->positive($parts($row));

            if ($pct !== null) {
                $percentages[$index] = $pct;

                continue;
            }
            if ($prt !== null) {
                $partsValues[$index] = $prt;

                continue;
            }
            $missing[] = $index;
        }

        $weights = array_fill(0, $count, 0.0);

        if ($percentages !== []) {
            foreach ($percentages as $index => $value) {
                $weights[$index] = $value;
            }

            $remaining = max(0.0, 100.0 - array_sum($percentages));
            if ($remaining > 0.00001 && $partsValues !== []) {
                $totalParts = array_sum($partsValues);
                if ($totalParts > 0.00001) {
                    foreach ($partsValues as $index => $value) {
                        $weights[$index] = $remaining * ($value / $totalParts);
                    }
                }
            } elseif ($remaining > 0.00001 && $missing !== []) {
                $each = $remaining / count($missing);
                foreach ($missing as $index) {
                    $weights[$index] = $each;
                }
            }
        } elseif ($partsValues !== []) {
            foreach ($partsValues as $index => $value) {
                $weights[$index] = $value;
            }
            foreach ($missing as $index) {
                $weights[$index] = 1.0;
            }
        } else {
            foreach (array_keys($weights) as $index) {
                $weights[$index] = 1.0;
            }
        }

        $total = array_sum($weights);
        if ($total <= 0.00001) {
            return array_fill(0, $count, 1 / $count);
        }

        $shares = [];
        foreach ($weights as $index => $weight) {
            $shares[$index] = $weight / $total;
        }

        return $shares;
    }

    protected function positive(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float) $value;

        return $number > 0 ? $number : null;
    }

    protected function normalizeState(string $state): string
    {
        $state = strtolower(trim($state));

        return in_array($state, ['forecast', 'current', 'actual'], true) ? $state : 'forecast';
    }

    protected function normalizeChannel(?string $channel): ?string
    {
        if ($channel === null) {
            return null;
        }

        $channel = strtolower(trim($channel));
        if ($channel === '' || $channel === 'all') {
            return null;
        }

        return in_array($channel, ['retail', 'wholesale', 'event'], true) ? $channel : null;
    }

    /**
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    protected function window(int $weeks): array
    {
        $weeks = max(1, $weeks);
        $from = CarbonImmutable::now()->startOfDay();
        $to = $from->addWeeks($weeks)->endOfDay();

        return [$from, $to];
    }
}
