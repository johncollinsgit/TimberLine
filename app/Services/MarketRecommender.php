<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventShipment;

class MarketRecommender
{
    public function recommendForEvent(Event $event, float $growth = 1.10, float $safety = 0.05): array
    {
        $history = EventShipment::query()
            ->whereHas('event', function ($q) use ($event) {
                $q->where('name', $event->name);
            })
            ->get();

        $basis = 'same_event_name';

        if ($history->isEmpty() && $event->city && $event->starts_at) {
            $month = $event->starts_at->month;
            $history = EventShipment::query()
                ->whereHas('event', function ($q) use ($event, $month) {
                    $q->where('city', $event->city)
                        ->whereMonth('starts_at', $month);
                })
                ->get();
            $basis = 'same_city_month';
        }

        if ($history->isEmpty()) {
            $history = EventShipment::query()->get();
            $basis = 'global';
        }

        return $this->buildRecommendations($history, $basis, $growth, $safety);
    }

    public function recommendForEvents(array $eventIds, float $growth = 1.10, float $safety = 0.05): array
    {
        $events = Event::query()->whereIn('id', $eventIds)->get();
        $history = EventShipment::query()
            ->whereIn('event_id', $events->pluck('id'))
            ->get();

        return $this->buildRecommendations($history, 'event_set', $growth, $safety);
    }

    protected function buildRecommendations($history, string $basis, float $growth, float $safety): array
    {
        $groups = $history->groupBy(fn ($row) => ($row->scent_id ?? 'null') . ':' . ($row->size_id ?? 'null'));
        $lines = [];
        foreach ($groups as $key => $rows) {
            $n = $rows->count();
            $avgSent = $rows->avg(fn ($r) => $r->sent_qty ?? $r->planned_qty ?? 0);
            $avgReturned = $rows->avg(fn ($r) => $r->returned_qty ?? 0);
            $avgSold = $rows->avg(function ($r) {
                if ($r->sold_qty !== null) return $r->sold_qty;
                if ($r->sent_qty !== null && $r->returned_qty !== null) {
                    return max(0, $r->sent_qty - $r->returned_qty);
                }
                return 0;
            });

            $recommend = round(($avgSold * $growth) + max(0, round($avgSent * $safety)));
            $confidence = $n >= 3 ? 'high' : ($n === 2 ? 'medium' : 'low');

            $lines[] = [
                'scent_id' => $rows->first()->scent_id,
                'size_id' => $rows->first()->size_id,
                'recommended_qty' => (int) $recommend,
                'reason' => [
                    'basis' => $basis,
                    'history_count' => $n,
                    'avg_sent' => round($avgSent),
                    'avg_returned' => round($avgReturned),
                    'avg_sold' => round($avgSold),
                    'growth_factor' => $growth,
                    'safety_stock' => max(0, round($avgSent * $safety)),
                    'confidence' => $confidence,
                ],
            ];
        }
        return $lines;
    }
}
