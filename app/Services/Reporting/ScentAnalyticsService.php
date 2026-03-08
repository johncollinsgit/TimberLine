<?php

namespace App\Services\Reporting;

use App\Models\MappingException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScentAnalyticsService
{
    /**
     * @return array<string,mixed>
     */
    public function unmappedExceptionSummary(int $limit = 10, ?string $channel = null): array
    {
        $limit = max(1, $limit);

        $query = MappingException::query()
            ->whereNull('resolved_at');

        if (Schema::hasColumn('mapping_exceptions', 'excluded_at')) {
            $query->whereNull('excluded_at');
        }

        if ($channel !== null && trim($channel) !== '' && strtolower(trim($channel)) !== 'all') {
            $normalized = strtolower(trim($channel));
            $query->where(function ($inner) use ($normalized): void {
                $inner->whereRaw('lower(store_key) like ?', ['%'.$normalized.'%']);
            });
        }

        $openCount = (clone $query)->count();

        $byStore = (clone $query)
            ->selectRaw('coalesce(store_key, "unknown") as store_key, COUNT(*) as open_count')
            ->groupBy('store_key')
            ->orderByDesc('open_count')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'store_key' => (string) ($row->store_key ?? 'unknown'),
                'channel' => $this->deriveChannel((string) ($row->store_key ?? '')),
                'open_count' => (int) ($row->open_count ?? 0),
            ])
            ->values()
            ->all();

        $rawNameExpr = $this->rawNameSqlExpression();

        $topRawNames = (clone $query)
            ->selectRaw($rawNameExpr.' as raw_name, COUNT(*) as open_count')
            ->groupBy(DB::raw($rawNameExpr))
            ->orderByDesc('open_count')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'raw_name' => (string) ($row->raw_name ?? 'Unknown'),
                'open_count' => (int) ($row->open_count ?? 0),
            ])
            ->values()
            ->all();

        $channelBuckets = collect($byStore)
            ->groupBy('channel')
            ->map(fn ($rows, $key): array => [
                'channel' => (string) $key,
                'open_count' => collect($rows)->sum('open_count'),
            ])
            ->sortByDesc('open_count')
            ->values()
            ->all();

        return [
            'open_count' => $openCount,
            'channel_filter' => $channel ? strtolower(trim($channel)) : null,
            'by_store' => $byStore,
            'by_channel' => $channelBuckets,
            'top_raw_names' => $topRawNames,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function unmappedExceptionDetails(int $limit = 150, ?string $channel = null): array
    {
        $limit = max(1, min(500, $limit));
        $query = $this->openExceptionQuery($channel);

        $rows = (clone $query)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (MappingException $row): array {
                return [
                    'id' => (int) $row->id,
                    'raw_name' => $this->rawNameForRow($row),
                    'raw_title' => (string) ($row->raw_title ?? ''),
                    'raw_variant' => (string) ($row->raw_variant ?? ''),
                    'store_key' => (string) ($row->store_key ?? 'unknown'),
                    'channel' => $this->deriveChannel((string) ($row->store_key ?? '')),
                    'account_name' => (string) ($row->account_name ?? ''),
                    'order_id' => $row->order_id ? (int) $row->order_id : null,
                    'order_line_id' => $row->order_line_id ? (int) $row->order_line_id : null,
                    'reason' => (string) ($row->reason ?? ''),
                    'created_at' => optional($row->created_at)?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();

        return [
            'count' => count($rows),
            'rows' => $rows,
            'channel_filter' => $channel ? strtolower(trim($channel)) : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<int,array<string,mixed>>
     */
    public function unmappedExceptionTrend(array $timeframe, int $points = 8, ?string $channel = null): array
    {
        /** @var CarbonImmutable $from */
        $from = data_get($timeframe, 'primary.from', CarbonImmutable::now()->subDays(29)->startOfDay());
        /** @var CarbonImmutable $to */
        $to = data_get($timeframe, 'primary.to', CarbonImmutable::now()->endOfDay());

        $points = max(2, min(24, $points));
        $totalDays = max(1, $to->diffInDays($from) + 1);
        $bucketDays = max(1, (int) ceil($totalDays / $points));

        $base = $this->openExceptionQuery($channel)
            ->whereBetween('created_at', [$from->toDateTimeString(), $to->toDateTimeString()]);

        $series = [];
        $cursor = $from->startOfDay();
        while ($cursor->lte($to)) {
            $bucketEnd = $cursor->addDays($bucketDays - 1)->endOfDay();
            if ($bucketEnd->gt($to)) {
                $bucketEnd = $to->copy();
            }

            $count = (clone $base)
                ->whereBetween('created_at', [$cursor->toDateTimeString(), $bucketEnd->toDateTimeString()])
                ->count();

            $series[] = [
                'label' => $cursor->format('M j').' - '.$bucketEnd->format('M j'),
                'from' => $cursor->toDateString(),
                'to' => $bucketEnd->toDateString(),
                'value' => (int) $count,
            ];

            $cursor = $bucketEnd->addDay()->startOfDay();
        }

        return $series;
    }

    protected function deriveChannel(string $storeKey): string
    {
        $normalized = strtolower(trim($storeKey));

        if (str_contains($normalized, 'wholesale')) {
            return 'wholesale';
        }

        if (str_contains($normalized, 'market') || str_contains($normalized, 'event')) {
            return 'event';
        }

        if (str_contains($normalized, 'club') || str_contains($normalized, 'subscription')) {
            return 'candle_club';
        }

        return 'retail';
    }

    protected function rawNameSqlExpression(): string
    {
        $hasRawScent = Schema::hasColumn('mapping_exceptions', 'raw_scent_name');

        if ($hasRawScent) {
            return 'coalesce(nullif(raw_scent_name, ""), nullif(raw_title, ""), "Unknown")';
        }

        return 'coalesce(nullif(raw_title, ""), "Unknown")';
    }

    protected function rawNameForRow(MappingException $row): string
    {
        $rawScentName = trim((string) ($row->raw_scent_name ?? ''));
        if ($rawScentName !== '') {
            return $rawScentName;
        }

        $rawTitle = trim((string) ($row->raw_title ?? ''));
        if ($rawTitle !== '') {
            return $rawTitle;
        }

        return 'Unknown';
    }

    protected function openExceptionQuery(?string $channel = null): \Illuminate\Database\Eloquent\Builder
    {
        $query = MappingException::query()
            ->whereNull('resolved_at');

        if (Schema::hasColumn('mapping_exceptions', 'excluded_at')) {
            $query->whereNull('excluded_at');
        }

        if ($channel !== null && trim($channel) !== '' && strtolower(trim($channel)) !== 'all') {
            $normalized = strtolower(trim($channel));
            $query->where(function ($inner) use ($normalized): void {
                $inner->whereRaw('lower(store_key) like ?', ['%'.$normalized.'%']);
            });
        }

        return $query;
    }
}
