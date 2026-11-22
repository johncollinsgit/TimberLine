<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\MakerVideo;
use App\Models\Marketplace\Order;

class MakerVideoPilotReport
{
    public function lastSixtyDays(): array
    {
        $from = now(config('marketplace.timezone'))->startOfDay()->subDays(59)->utc();
        $submitted = MakerVideo::where('first_submitted_at', '>=', $from);
        $byMaker = (clone $submitted)->selectRaw('maker_id, COUNT(*) AS posts')->groupBy('maker_id')->pluck('posts');
        $labor = (clone $submitted)->selectRaw('COUNT(production_minutes) AS recorded_hours, SUM(production_minutes) AS minutes, COUNT(production_cost_cents) AS recorded_cost, SUM(production_cost_cents) AS spend')->first();
        $report = ['makers' => $byMaker->count(), 'repeat_makers' => $byMaker->filter(fn ($posts) => $posts >= 2)->count(), 'videos' => $byMaker->sum(),
            'hours_logged' => (int) $labor->recorded_hours, 'minutes' => (int) $labor->minutes,
            'cost_logged' => (int) $labor->recorded_cost, 'cost' => (int) $labor->spend,
            'orders' => 0, 'merchandise' => 0, 'margin' => 0, 'known_processing' => 0, 'incomplete_processing' => 0];
        $orders = Order::where('created_at', '>=', $from)->where('test_mode', false)->where('review_required', false)
            ->whereHas('lines', fn ($q) => $q->whereNotNull('video_tag_id'))->with('lines')->lazyById(100);
        foreach ($orders as $order) {
            $attributed = $order->lines->whereNotNull('video_tag_id');
            $paidUnits = 0;
            foreach ($attributed as $line) {
                $net = $line->unit_price_cents * $line->quantity - $line->discount_cents;
                $retained = $line->quantity - $line->refunded_quantity;
                $paidUnits += $retained;
                $net -= intdiv($net * $line->refunded_quantity, $line->quantity);
                $report['merchandise'] += $net;
                $report['margin'] += $net - $line->maker_unit_cents * $retained;
            }
            $report['orders'] += (int) ($paidUnits > 0);
            if ($order->processing_cost_cents !== null && $attributed->count() === $order->lines->count()) {
                $report['known_processing'] += $order->processing_cost_cents;
            } else {
                $report['incomplete_processing']++;
            }
        }

        return $report;
    }
}
