<?php

namespace App\Services\Reporting;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\WebsiteOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Read-only normalization for sales channels.
 *
 * Source systems keep owning their own records. In particular, Website
 * Commerce remains in website_orders and is never copied into the legacy
 * orders table (which backs existing Shopify/operations workflows).
 */
class SalesChannelSummaryService
{
    /**
     * @return array{channels:list<array{key:string,label:string,order_count:int,revenue_cents:int,latest_order_at:?string}>,order_count:int,revenue_cents:int,channel_count:int,has_website_channel:bool}
     */
    public function forTenant(Tenant|int $tenant, CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : $tenant;
        $channels = collect();

        if (Schema::hasTable('orders')) {
            $channels = $channels->concat($this->legacyChannels($tenantId, $startsAt, $endsAt));
        }

        if (Schema::hasTable('website_orders')) {
            $channels = $channels->concat($this->websiteChannel($tenantId, $startsAt, $endsAt));
        }

        $channels = $channels
            ->filter(fn (array $channel): bool => $channel['order_count'] > 0)
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            'channels' => $channels->all(),
            'order_count' => (int) $channels->sum('order_count'),
            'revenue_cents' => (int) $channels->sum('revenue_cents'),
            'channel_count' => $channels->count(),
            'has_website_channel' => $channels->contains(fn (array $channel): bool => $channel['key'] === 'everbranch_website'),
        ];
    }

    /** @return Collection<int,array{key:string,label:string,order_count:int,revenue_cents:int,latest_order_at:?string}> */
    private function legacyChannels(int $tenantId, CarbonInterface $startsAt, CarbonInterface $endsAt): Collection
    {
        return Order::query()
            ->forTenantId($tenantId)
            ->whereBetween('ordered_at', [$startsAt, $endsAt])
            ->selectRaw("COALESCE(NULLIF(source, ''), 'legacy') as sales_channel")
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(SUM(total_price), 0) as revenue')
            ->selectRaw('MAX(ordered_at) as latest_order_at')
            ->groupBy('sales_channel')
            ->get()
            ->map(function (Order $order): array {
                $key = 'legacy_'.Str::slug((string) $order->getAttribute('sales_channel'));

                return [
                    'key' => $key,
                    'label' => $this->legacyLabel((string) $order->getAttribute('sales_channel')),
                    'order_count' => (int) $order->getAttribute('order_count'),
                    'revenue_cents' => (int) round(((float) $order->getAttribute('revenue')) * 100),
                    'latest_order_at' => $order->getAttribute('latest_order_at'),
                ];
            });
    }

    /** @return Collection<int,array{key:string,label:string,order_count:int,revenue_cents:int,latest_order_at:?string}> */
    private function websiteChannel(int $tenantId, CarbonInterface $startsAt, CarbonInterface $endsAt): Collection
    {
        $summary = WebsiteOrder::query()
            ->forTenantId($tenantId)
            ->where('payment_status', 'paid')
            ->whereBetween('paid_at', [$startsAt, $endsAt])
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as revenue_cents')
            ->selectRaw('MAX(paid_at) as latest_order_at')
            ->first();

        if (! $summary || (int) $summary->getAttribute('order_count') === 0) {
            return collect();
        }

        return collect([[
            'key' => 'everbranch_website',
            'label' => 'Everbranch Website',
            'order_count' => (int) $summary->getAttribute('order_count'),
            'revenue_cents' => (int) $summary->getAttribute('revenue_cents'),
            'latest_order_at' => $summary->getAttribute('latest_order_at'),
        ]]);
    }

    private function legacyLabel(string $source): string
    {
        return match (strtolower($source)) {
            'shopify' => 'Shopify',
            'square' => 'Square',
            'manual' => 'Manual sales',
            'wholesale' => 'Wholesale',
            'retail' => 'Retail',
            'legacy' => 'Existing sales',
            default => Str::headline($source),
        };
    }
}
