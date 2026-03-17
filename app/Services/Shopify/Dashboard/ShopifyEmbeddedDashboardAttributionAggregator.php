<?php

namespace App\Services\Shopify\Dashboard;

use Illuminate\Support\Collection;

class ShopifyEmbeddedDashboardAttributionAggregator
{
    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $visibleSources
     * @return array{rows:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    public function aggregate(Collection $rows, array $visibleSources): array
    {
        $totalRevenue = (float) $rows->sum(fn (array $row): float => (float) ($row['revenue'] ?? 0));

        $grouped = $rows
            ->groupBy(fn (array $row): string => (string) ($row['channel'] ?? 'unknown'))
            ->map(function (Collection $group, string $channel) use ($totalRevenue): array {
                $revenue = round((float) $group->sum(fn (array $row): float => (float) ($row['revenue'] ?? 0)), 2);
                $orders = (int) $group->count();

                return [
                    'key' => $channel,
                    'label' => $this->label($channel),
                    'revenue' => $revenue,
                    'orders' => $orders,
                    'share_of_total' => $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 1) : 0.0,
                    'confidence' => $this->summaryConfidence($group),
                    'mapped_record_count' => $orders,
                ];
            });

        $orderedRows = collect($visibleSources)
            ->map(function (string $source) use ($grouped): array {
                return $grouped->get($source, [
                    'key' => $source,
                    'label' => $this->label($source),
                    'revenue' => 0.0,
                    'orders' => 0,
                    'share_of_total' => 0.0,
                    'confidence' => 'none',
                    'mapped_record_count' => 0,
                ]);
            })
            ->all();

        return [
            'rows' => $orderedRows,
            'summary' => [
                'record_count' => (int) $rows->count(),
                'unknown_record_count' => (int) $rows->where('channel', 'unknown')->count(),
                'other_record_count' => (int) $rows->where('channel', 'other')->count(),
                'has_unknown_rows' => $rows->contains(fn (array $row): bool => (string) ($row['channel'] ?? '') === 'unknown'),
                'has_other_rows' => $rows->contains(fn (array $row): bool => (string) ($row['channel'] ?? '') === 'other'),
            ],
        ];
    }

    protected function label(string $key): string
    {
        return match ($key) {
            'text' => 'Text',
            'email' => 'Email',
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'google' => 'Google',
            'direct' => 'Direct',
            'unknown' => 'Unknown',
            default => 'Other',
        };
    }

    protected function summaryConfidence(Collection $rows): string
    {
        $scores = $rows
            ->map(function (array $row): int {
                return match ((string) ($row['attributionConfidence'] ?? 'low')) {
                    'high' => 3,
                    'medium' => 2,
                    'low' => 1,
                    default => 0,
                };
            })
            ->filter();

        $score = (int) round((float) $scores->avg());

        return match (true) {
            $score >= 3 => 'high',
            $score === 2 => 'medium',
            $score === 1 => 'low',
            default => 'none',
        };
    }
}
