<?php

namespace App\Services\Marketing;

use App\Models\MarketingEventSourceMapping;
use App\Models\MarketingOrderEventAttribution;
use App\Models\MarketingProfile;
use App\Models\SquareOrder;
use Illuminate\Support\Collection;

class MarketingEventAttributionService
{
    /**
     * @return array{created:int,updated:int,removed:int}
     */
    public function refreshSquareOrderAttributions(?int $limit = null): array
    {
        $created = 0;
        $updated = 0;
        $removed = 0;

        $query = SquareOrder::query()->orderBy('id');
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

        $matches = $this->resolvedMappingsForSquareOrder($order);
        $matchedEventIds = [];

        foreach ($matches as $match) {
            $mappedEventId = (int) $match['mapping']->event_instance_id;
            if ($mappedEventId <= 0) {
                continue;
            }

            $matchedEventIds[] = $mappedEventId;
            $record = MarketingOrderEventAttribution::query()->firstOrNew([
                'source_type' => 'square_order',
                'source_id' => $order->square_order_id,
                'event_instance_id' => $mappedEventId,
            ]);

            $wasExisting = $record->exists;
            $record->fill([
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
        $squareOrderIds = $profile->links()
            ->where('source_type', 'square_order')
            ->pluck('source_id')
            ->values();

        if ($squareOrderIds->isEmpty()) {
            return [];
        }

        $records = MarketingOrderEventAttribution::query()
            ->where('source_type', 'square_order')
            ->whereIn('source_id', $squareOrderIds->all())
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
        $squareOrderIds = $profile->links()
            ->where('source_type', 'square_order')
            ->pluck('source_id')
            ->values();

        if ($squareOrderIds->isEmpty()) {
            return [];
        }

        $orders = SquareOrder::query()
            ->whereIn('square_order_id', $squareOrderIds->all())
            ->get();

        return $this->unmappedValuesFromOrders($orders)->values()->all();
    }

    /**
     * @return Collection<int,array{source_system:string,raw_value:string,normalized_value:string}>
     */
    public function unmappedValuesFromOrders(?Collection $orders = null): Collection
    {
        $orders = $orders ?: SquareOrder::query()->orderByDesc('id')->limit(500)->get();
        $existing = MarketingEventSourceMapping::query()
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
    protected function resolvedMappingsForSquareOrder(SquareOrder $order): array
    {
        $resolved = [];
        foreach ($this->candidateSourcesForOrder($order) as $candidate) {
            $mapping = MarketingEventSourceMapping::query()
                ->where('source_system', $candidate['source_system'])
                ->where(function ($query) use ($candidate): void {
                    $query->where('raw_value', $candidate['raw_value'])
                        ->orWhere('normalized_value', $candidate['normalized_value']);
                })
                ->where('is_active', true)
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
}
