<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\MakerVideo;
use App\Models\Marketplace\MakerVideoTag;
use App\Models\Marketplace\OrderLine;

class MakerVideoSales
{
    public function attribution(Listing $listing, int $tagId): array
    {
        $tag = MakerVideoTag::where('active', true)->whereIn('video_id',
            MakerVideo::published()->select('id')->where('maker_id', $listing->maker_id)
        )->find($tagId);
        if (! $tag) {
            return [];
        }
        $card = app(MakerVideoCatalog::class)->resolve($listing->maker, $tag->product_key);

        return ($card['available'] ?? false) && ($card['listing_id'] ?? null) === $listing->id ? ['video_tag_id' => $tag->id] : [];
    }

    public function forTags(Maker $maker, array $tagIds): array
    {
        return $this->totals($maker, $tagIds, true);
    }

    public function summary(Maker $maker, array $tagIds): array
    {
        return $this->totals($maker, $tagIds, false)[0] ?? ['orders' => 0, 'net_merchandise_cents' => 0];
    }

    private function totals(Maker $maker, array $tagIds, bool $byTag): array
    {
        $lines = OrderLine::where('maker_id', $maker->id)->whereIn('video_tag_id', $tagIds)
            ->whereHas('order', fn ($q) => $q->where('test_mode', false)->where('review_required', false))
            ->whereColumn('refunded_quantity', '<', 'quantity')->orderBy('order_id')
            ->select(['video_tag_id', 'order_id', 'quantity', 'refunded_quantity', 'unit_price_cents', 'discount_cents'])->cursor();
        $totals = [];
        $previousOrder = [];
        foreach ($lines as $line) {
            $bucket = $byTag ? $line->video_tag_id : 0;
            $totals[$bucket] ??= ['orders' => 0, 'net_merchandise_cents' => 0];
            if (($previousOrder[$bucket] ?? null) !== $line->order_id) {
                $totals[$bucket]['orders']++;
                $previousOrder[$bucket] = $line->order_id;
            }
            $net = $line->unit_price_cents * $line->quantity - $line->discount_cents;
            $totals[$bucket]['net_merchandise_cents'] += $net - intdiv($net * $line->refunded_quantity, $line->quantity);
        }

        return $totals;
    }
}
