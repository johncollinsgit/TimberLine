<?php

namespace App\Services\Marketing;

use App\Models\Order;
use Carbon\CarbonImmutable;

class MarketingOrderAttributionCoverageReport
{
    /**
     * @var array<int,string>
     */
    protected array $trackedFields = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'referrer',
        'referring_site',
        'landing_site',
        'landing_page',
        'source_name',
        'source_identifier',
        'source_type',
        'browser_ip',
        'user_agent',
        'accept_language',
        'session_hash',
    ];

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function report(array $filters = []): array
    {
        $tenantId = $this->positiveInt($filters['tenant_id'] ?? null);
        $since = $this->dateValue($filters['since'] ?? null);
        $until = $this->dateValue($filters['until'] ?? null);
        $store = $this->stringValue($filters['store'] ?? null);
        $chunk = max(25, (int) ($filters['chunk'] ?? 500));
        $withAttributionOnly = (bool) ($filters['with_attribution_only'] ?? false);
        $missingOnly = (bool) ($filters['missing_only'] ?? false);

        $baseQuery = $this->scopedQuery($since, $until, $store, $tenantId);
        $scopedQuery = $this->applyScopeFilters(clone $baseQuery, $withAttributionOnly, $missingOnly);

        $total = (clone $scopedQuery)->count();
        $withAttribution = (clone $scopedQuery)->whereNotNull('attribution_meta')->count();
        $withoutAttribution = max(0, $total - $withAttribution);

        $missingFields = array_fill_keys($this->trackedFields, 0);
        $confidenceCounts = [];
        $versionCounts = [];
        $sourceNameCounts = [];
        $sourceTypeCounts = [];
        $captureContextCounts = [];

        foreach ((clone $scopedQuery)
            ->select(['id', 'attribution_meta'])
            ->orderBy('id')
            ->lazyById($chunk) as $order) {
            $meta = is_array($order->attribution_meta ?? null) ? $order->attribution_meta : [];

            foreach ($this->trackedFields as $field) {
                if ($meta === [] || $this->missingValue($meta[$field] ?? null)) {
                    $missingFields[$field]++;
                }
            }

            if ($meta === []) {
                continue;
            }

            $this->incrementCount($confidenceCounts, $this->stringValue($meta['confidence'] ?? null));
            $this->incrementCount($versionCounts, $this->stringValue($meta['ingested_attribution_version'] ?? null));
            $this->incrementCount($sourceNameCounts, $this->stringValue($meta['source_name'] ?? null));
            $this->incrementCount($sourceTypeCounts, $this->stringValue($meta['source_type'] ?? null));

            $contexts = collect(array_merge(
                $this->arrayStrings($meta['capture_contexts'] ?? []),
                $this->stringValue($meta['capture_context'] ?? null) ? [(string) $meta['capture_context']] : []
            ))
                ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
                ->unique()
                ->values()
                ->all();

            foreach ($contexts as $context) {
                $this->incrementCount($captureContextCounts, $context);
            }
        }

        $missingRows = [];
        foreach ($this->trackedFields as $field) {
            $count = (int) ($missingFields[$field] ?? 0);
            $missingRows[$field] = [
                'count' => $count,
                'rate' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
            ];
        }

        uasort($missingRows, fn (array $left, array $right): int => $right['count'] <=> $left['count']);

        return [
            'scope' => [
                'tenant_id' => $tenantId,
                'since' => $since?->toIso8601String(),
                'until' => $until?->toIso8601String(),
                'store' => $store,
                'chunk' => $chunk,
                'with_attribution_only' => $withAttributionOnly,
                'missing_only' => $missingOnly,
            ],
            'totals' => [
                'total_orders' => $total,
                'with_attribution_meta' => $withAttribution,
                'without_attribution_meta' => $withoutAttribution,
                'attribution_coverage_rate' => $total > 0 ? round(($withAttribution / $total) * 100, 1) : 0.0,
            ],
            'missing_fields' => $missingRows,
            'top_missing_fields' => array_slice($missingRows, 0, 5, true),
            'quality' => [
                'confidence' => $this->valueRows($confidenceCounts, $withAttribution),
                'ingested_attribution_version' => $this->valueRows($versionCounts, $withAttribution),
                'capture_context' => $this->valueRows($captureContextCounts, $withAttribution),
                'source_name' => $this->valueRows($sourceNameCounts, $withAttribution),
                'source_type' => $this->valueRows($sourceTypeCounts, $withAttribution),
            ],
        ];
    }

    protected function scopedQuery(?CarbonImmutable $since, ?CarbonImmutable $until, ?string $store, ?int $tenantId)
    {
        return Order::query()
            ->forTenantId($tenantId)
            ->when($since, fn ($query) => $query->where('ordered_at', '>=', $since))
            ->when($until, fn ($query) => $query->where('ordered_at', '<=', $until))
            ->when($store, function ($query, string $store): void {
                $query->where(function ($nested) use ($store): void {
                    $nested->where('shopify_store_key', $store)
                        ->orWhere('shopify_store', $store);
                });
            });
    }

    protected function applyScopeFilters($query, bool $withAttributionOnly, bool $missingOnly)
    {
        if ($withAttributionOnly) {
            $query->whereNotNull('attribution_meta');
        }

        if ($missingOnly) {
            $query->whereNull('attribution_meta');
        }

        return $query;
    }

    protected function dateValue(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    protected function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function missingValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
    }

    /**
     * @param  array<int|string,mixed>  $value
     * @return array<int,string>
     */
    protected function arrayStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,int>  $counts
     * @return array<string,array{count:int,rate:float}>
     */
    protected function valueRows(array $counts, int $denominator): array
    {
        arsort($counts);

        $rows = [];
        foreach ($counts as $value => $count) {
            $rows[$value] = [
                'count' => (int) $count,
                'rate' => $denominator > 0 ? round(($count / $denominator) * 100, 1) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string,int>  $counts
     */
    protected function incrementCount(array &$counts, ?string $key): void
    {
        if ($key === null) {
            return;
        }

        $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $tenantId = (int) $value;

        return $tenantId > 0 ? $tenantId : null;
    }
}
