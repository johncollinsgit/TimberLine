<?php

namespace App\Services\Marketing;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MessageLinkAggregationService
{
    /**
     * @param  Collection<int,\App\Models\MarketingMessageEngagementEvent>  $clickEvents
     * @param  Collection<int,\App\Models\MarketingMessageOrderAttribution>  $attributions
     * @return array<int,array<string,mixed>>
     */
    public function aggregate(Collection $clickEvents, Collection $attributions): array
    {
        if ($clickEvents->isEmpty()) {
            return [];
        }

        $attributionByEvent = $attributions
            ->filter(fn ($row): bool => (int) ($row->marketing_message_engagement_event_id ?? 0) > 0)
            ->groupBy(fn ($row): int => (int) $row->marketing_message_engagement_event_id);

        return $clickEvents
            ->groupBy(function ($event): string {
                $normalized = trim((string) ($event->normalized_url ?? ''));
                if ($normalized !== '') {
                    return $normalized;
                }

                $url = trim((string) ($event->url ?? ''));

                return $url !== '' ? $url : ('event:'.$event->id);
            })
            ->map(function (Collection $events, string $urlKey) use ($attributionByEvent): array {
                $first = $events->sortBy('occurred_at')->first();
                $last = $events->sortByDesc('occurred_at')->first();
                $clickCount = $events->count();
                $uniqueClickCount = $events
                    ->pluck('marketing_profile_id')
                    ->map(fn ($value): int => (int) $value)
                    ->filter(fn (int $value): bool => $value > 0)
                    ->unique()
                    ->count();

                $eventAttributions = $events
                    ->map(function ($event) use ($attributionByEvent): Collection {
                        return $attributionByEvent->get((int) $event->id, collect());
                    })
                    ->flatten(1);

                $orderCount = $eventAttributions
                    ->pluck('order_id')
                    ->map(fn ($value): int => (int) $value)
                    ->filter(fn (int $value): bool => $value > 0)
                    ->unique()
                    ->count();
                $revenueCents = (int) $eventAttributions->sum('revenue_cents');

                $rawUrl = trim((string) ($first?->url ?? ''));
                $normalizedUrl = trim((string) ($first?->normalized_url ?? ''));
                $resolvedRawUrl = $rawUrl !== '' ? $rawUrl : ($normalizedUrl !== '' ? $normalizedUrl : null);
                $resolvedNormalizedUrl = $normalizedUrl !== '' ? $normalizedUrl : ($rawUrl !== '' ? $rawUrl : null);

                return [
                    'link_label' => $this->resolvedLabel($events),
                    'url' => $resolvedRawUrl,
                    'normalized_url' => $resolvedNormalizedUrl,
                    'click_count' => $clickCount,
                    'unique_click_count' => $uniqueClickCount,
                    'first_click_at' => optional($first?->occurred_at)->toIso8601String(),
                    'last_click_at' => optional($last?->occurred_at)->toIso8601String(),
                    'attributed_orders' => $orderCount,
                    'attributed_revenue_cents' => $revenueCents,
                    'conversion_rate' => $clickCount > 0
                        ? round(($orderCount / max(1, $clickCount)) * 100, 2)
                        : 0.0,
                    'url_key' => $urlKey,
                ];
            })
            ->sortByDesc(fn (array $row): int => (int) ($row['click_count'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,\App\Models\MarketingMessageEngagementEvent>  $events
     */
    protected function resolvedLabel(Collection $events): string
    {
        $provided = $events
            ->pluck('link_label')
            ->map(fn ($value): string => trim((string) $value))
            ->first(fn (string $value): bool => $value !== '');
        if (is_string($provided) && $provided !== '') {
            return Str::limit($provided, 90);
        }

        $url = $events
            ->pluck('url')
            ->map(fn ($value): string => trim((string) $value))
            ->first(fn (string $value): bool => $value !== '')
            ?? $events
                ->pluck('normalized_url')
                ->map(fn ($value): string => trim((string) $value))
                ->first(fn (string $value): bool => $value !== '');

        if (! is_string($url) || $url === '') {
            return 'Link';
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return Str::limit($url, 90);
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path !== '') {
            return Str::limit(urldecode((string) basename($path)), 90);
        }

        $host = trim((string) ($parts['host'] ?? ''));

        return $host !== '' ? Str::limit($host, 90) : Str::limit($url, 90);
    }
}
