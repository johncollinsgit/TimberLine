<?php

namespace App\Services\Reporting;

use App\Models\MappingException;
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
}
