<?php

namespace App\Services\Marketing;

use App\Models\MarketingEventSourceMapping;
use App\Models\MarketingOrderEventAttribution;
use App\Models\MarketingProfile;
use App\Models\SquareOrder;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MarketingEventAttributionService
{
    /**
     * @return array{created:int,updated:int,removed:int}
     */
    public function refreshSquareOrderAttributions(?int $limit = null, ?int $tenantId = null): array
    {
        $created = 0;
        $updated = 0;
        $removed = 0;

        $query = SquareOrder::query()
            ->when($tenantId !== null, fn ($builder) => $builder->forTenantId($tenantId))
            ->orderBy('id');
        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        foreach ($query->get() as $order) {
            $result = $this->refreshForSquareOrder($order);
            $created += $result['created'];
            $updated += $result['updated'];
            $removed += $result['removed'];
        }

        return compact('created', 'updated', 'removed');
    }

    /**
     * @return array{created:int,updated:int,removed:int}
     */
    public function refreshForSquareOrder(SquareOrder $order): array
    {
        $created = 0;
        $updated = 0;
        $removed = 0;

        $orderTenantId = $this->positiveInt($order->tenant_id);
        if ($this->strictModeEnabled() && $orderTenantId === null) {
            return compact('created', 'updated', 'removed');
        }

        $matches = $this->resolvedMappingsForSquareOrder($order, $orderTenantId);
        $matchedEventIds = [];

        foreach ($matches as $match) {
            $mappedEventId = (int) $match['mapping']->event_instance_id;
            if ($mappedEventId <= 0) {
                continue;
            }

            $matchedEventIds[] = $mappedEventId;
            $attributes = [
                'source_type' => 'square_order',
                'source_id' => $order->square_order_id,
                'event_instance_id' => $mappedEventId,
            ];
            if ($this->orderAttributionTenantRailAvailable()) {
                $attributes['tenant_id'] = $orderTenantId;
            }

            $record = MarketingOrderEventAttribution::query()->firstOrNew($attributes);

            $wasExisting = $record->exists;
            $record->fill([
                'tenant_id' => $this->orderAttributionTenantRailAvailable() ? $orderTenantId : null,
                'attribution_method' => 'mapping:' . $match['source_system'],
                'confidence' => $match['confidence'],
                'meta' => [
                    'raw_value' => $match['raw_value'],
                    'normalized_value' => $match['normalized_value'],
                    'square_order_row_id' => $order->id,
                ],
            ]);
            $record->save();

            if ($wasExisting) {
                $updated++;
            } else {
                $created++;
            }
        }

        $toDelete = MarketingOrderEventAttribution::query()
            ->where('source_type', 'square_order')
            ->where('source_id', $order->square_order_id)
            ->when(
                $this->orderAttributionTenantRailAvailable() && $orderTenantId !== null,
                fn ($query) => $query->where('tenant_id', $orderTenantId),
                fn ($query) => $this->orderAttributionTenantRailAvailable()
                    ? $query->whereNull('tenant_id')
                    : $query
            )
            ->when($matchedEventIds !== [], fn ($q) => $q->whereNotIn('event_instance_id', $matchedEventIds))
            ->get();

        foreach ($toDelete as $record) {
            $record->delete();
            $removed++;
        }

        return compact('created', 'updated', 'removed');
    }

    /**
     * @return array<int,array{
     *  event_instance_id:int,
     *  event_title:string,
     *  event_date:?string,
     *  source_count:int,
     *  confidence:?float,
     *  attribution_methods:array<int,string>
     * }>
     */
    public function eventSummaryForProfile(MarketingProfile $profile): array
    {
        $tenantId = $this->positiveInt($profile->tenant_id);
        $squareOrderIds = $profile->links()
            ->where('source_type', 'square_order')
            ->pluck('source_id')
            ->values();

        if ($squareOrderIds->isEmpty()) {
            return [];
        }

        if ($tenantId !== null) {
            $squareOrderIds = SquareOrder::query()
                ->forTenantId($tenantId)
                ->whereIn('square_order_id', $squareOrderIds->all())
                ->pluck('square_order_id')
                ->values();
        }

        if ($squareOrderIds->isEmpty()) {
            return [];
        }

        $records = MarketingOrderEventAttribution::query()
            ->where('source_type', 'square_order')
            ->whereIn('source_id', $squareOrderIds->all())
            ->when(
                $this->orderAttributionTenantRailAvailable() && $tenantId !== null,
                fn ($query) => $query->where('tenant_id', $tenantId)
            )
            ->with('eventInstance:id,title,starts_at')
            ->get();

        return $records
            ->groupBy('event_instance_id')
            ->map(function (Collection $group, int $eventInstanceId): array {
                $first = $group->first();
                return [
                    'event_instance_id' => $eventInstanceId,
                    'event_title' => (string) ($first?->eventInstance?->title ?? 'Unknown event'),
                    'event_date' => optional($first?->eventInstance?->starts_at)->toDateString(),
                    'source_count' => $group->pluck('source_id')->unique()->count(),
                    'confidence' => $group->max('confidence') !== null ? (float) $group->max('confidence') : null,
                    'attribution_methods' => $group->pluck('attribution_method')->filter()->unique()->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int,array{source_system:string,raw_value:string,normalized_value:string}>
     */
    public function unresolvedValuesForProfile(MarketingProfile $profile): array
    {
        $tenantId = $this->positiveInt($profile->tenant_id);
        $squareOrderIds = $profile->links()
            ->where('source_type', 'square_order')
            ->pluck('source_id')
            ->values();

        if ($squareOrderIds->isEmpty()) {
            return [];
        }

        $orders = SquareOrder::query()
            ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
            ->whereIn('square_order_id', $squareOrderIds->all())
            ->get();

        return $this->unmappedValuesFromOrders($orders, $tenantId)->values()->all();
    }

    /**
     * @return Collection<int,array{source_system:string,raw_value:string,normalized_value:string}>
     */
    public function unmappedValuesFromOrders(?Collection $orders = null, ?int $tenantId = null): Collection
    {
        $orders = $orders ?: SquareOrder::query()
            ->when($tenantId !== null, fn ($builder) => $builder->forTenantId($tenantId))
            ->orderByDesc('id')
            ->limit(500)
            ->get();
        $effectiveTenantId = $this->resolveTenantIdFromOrders($orders, $tenantId);

        $existingQuery = MarketingEventSourceMapping::query();
        if ($this->eventSourceMappingTenantRailAvailable()) {
            if ($effectiveTenantId !== null) {
                $existingQuery->where('tenant_id', $effectiveTenantId);
            } elseif ($this->strictModeEnabled()) {
                $existingQuery->whereRaw('1 = 0');
            }
        }

        $existing = $existingQuery
            ->get(['source_system', 'raw_value'])
            ->map(fn (MarketingEventSourceMapping $row): string => $row->source_system . '|' . trim((string) $row->raw_value))
            ->unique()
            ->flip();

        $values = collect();
        foreach ($orders as $order) {
            foreach ($this->candidateSourcesForOrder($order) as $candidate) {
                $key = $candidate['source_system'] . '|' . $candidate['raw_value'];
                if (!isset($existing[$key])) {
                    $values->push($candidate);
                }
            }
        }

        return $values
            ->unique(fn (array $item) => $item['source_system'] . '|' . $item['raw_value'])
            ->values();
    }

    /**
     * @return array<int,array{
     *  source_system:string,
     *  raw_value:string,
     *  normalized_value:string,
     *  mapping:MarketingEventSourceMapping,
     *  confidence:?float
     * }>
     */
    protected function resolvedMappingsForSquareOrder(SquareOrder $order, ?int $tenantId = null): array
    {
        if ($this->strictModeEnabled() && $tenantId === null) {
            return [];
        }

        $resolved = [];
        foreach ($this->candidateSourcesForOrder($order) as $candidate) {
            $mappingQuery = MarketingEventSourceMapping::query()
                ->where('source_system', $candidate['source_system'])
                ->where(function ($query) use ($candidate): void {
                    $query->where('raw_value', $candidate['raw_value'])
                        ->orWhere('normalized_value', $candidate['normalized_value']);
                })
                ->where('is_active', true);
            if ($this->eventSourceMappingTenantRailAvailable()) {
                if ($tenantId !== null) {
                    $mappingQuery->where('tenant_id', $tenantId);
                } elseif ($this->strictModeEnabled()) {
                    $mappingQuery->whereRaw('1 = 0');
                }
            }

            $mapping = $mappingQuery
                ->orderByDesc('confidence')
                ->orderByDesc('id')
                ->first();

            if (! $mapping || ! $mapping->event_instance_id) {
                continue;
            }

            $resolved[] = [
                'source_system' => $candidate['source_system'],
                'raw_value' => $candidate['raw_value'],
                'normalized_value' => $candidate['normalized_value'],
                'confidence' => $mapping->confidence !== null ? (float) $mapping->confidence : null,
                'mapping' => $mapping,
            ];
        }

        return $resolved;
    }

    /**
     * @return array<int,array{source_system:string,raw_value:string,normalized_value:string}>
     */
    protected function candidateSourcesForOrder(SquareOrder $order): array
    {
        $values = [];
        $taxNames = is_array($order->raw_tax_names) ? $order->raw_tax_names : [];
        foreach ($taxNames as $name) {
            $raw = trim((string) $name);
            if ($raw !== '') {
                $values[] = [
                    'source_system' => 'square_tax_name',
                    'raw_value' => $raw,
                    'normalized_value' => $this->normalize($raw),
                ];
            }
        }

        $sourceName = trim((string) ($order->source_name ?? ''));
        if ($sourceName !== '') {
            $values[] = [
                'source_system' => 'square_source_name',
                'raw_value' => $sourceName,
                'normalized_value' => $this->normalize($sourceName),
            ];
        }

        return collect($values)
            ->unique(fn (array $row) => $row['source_system'] . '|' . $row['raw_value'])
            ->values()
            ->all();
    }

    protected function normalize(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    protected function strictModeEnabled(): bool
    {
        if (! Schema::hasTable('tenants')) {
            return false;
        }

        return (int) Tenant::query()->count() > 0;
    }

    protected function eventSourceMappingTenantRailAvailable(): bool
    {
        return Schema::hasTable('marketing_event_source_mappings')
            && Schema::hasColumn('marketing_event_source_mappings', 'tenant_id');
    }

    protected function orderAttributionTenantRailAvailable(): bool
    {
        return Schema::hasTable('marketing_order_event_attributions')
            && Schema::hasColumn('marketing_order_event_attributions', 'tenant_id');
    }

    protected function resolveTenantIdFromOrders(Collection $orders, ?int $explicitTenantId): ?int
    {
        $tenantId = $this->positiveInt($explicitTenantId);
        if ($tenantId !== null) {
            return $tenantId;
        }

        $tenantIds = $orders
            ->map(fn ($order): ?int => $this->positiveInt($order->tenant_id ?? null))
            ->filter()
            ->unique()
            ->values();

        return $tenantIds->count() === 1 ? (int) $tenantIds->first() : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
