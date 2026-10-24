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

        if ($this->hasLivePos()) {
            $pos = \Illuminate\Support\Facades\DB::table('pos_live_sales')->where('runtime_id', config('pos_live.runtime_id'))
                ->where('tenant_id', $tenantId)->where('commerce_source', 'native')->where('state', 'succeeded')
                ->whereBetween('created_at', [$startsAt, $endsAt])->selectRaw('COUNT(*) as n, COALESCE(SUM(amount_minor),0) as gross, MAX(created_at) as latest')->first();
            if ($pos->n) {
                $channels->push(['key' => 'everbranch_pos', 'label' => 'Everbranch POS', 'order_count' => (int) $pos->n,
                    'revenue_cents' => (int) $pos->gross, 'latest_order_at' => $pos->latest]);
            }
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

    /** Read-only provider evidence for Trajectory; never an additional accounting ledger. */
    public function forTrajectory(Tenant|int $tenant, CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : $tenant;
        $summary = $this->forTenant($tenantId, $startsAt, $endsAt);
        $squareQuery = \App\Models\SquareOrder::query()->forTenantId($tenantId);
        if ($this->hasLivePos()) {
            $squareQuery->whereNotExists(function ($q) use ($tenantId): void {
                $q->selectRaw('1')->from('pos_live_sales as pos')->where('pos.runtime_id', config('pos_live.runtime_id'))
                    ->where('pos.tenant_id', $tenantId)->where('pos.state', 'succeeded')
                    ->whereColumn('pos.provider_order_id', 'square_orders.square_order_id');
            })->whereNotExists(function ($q) use ($tenantId): void {
                $q->selectRaw('1')->from('square_payments as sp')->join('payment_core_operations as op', 'op.provider_id', '=', 'sp.square_payment_id')
                    ->join('pos_live_sales as pos', 'pos.attempt_id', '=', 'op.id')->where('pos.runtime_id', config('pos_live.runtime_id'))
                    ->where('pos.tenant_id', $tenantId)->where('op.tenant_id', $tenantId)->where('sp.tenant_id', $tenantId)
                    ->where('op.kind', 'payment')->where('pos.state', 'succeeded')->whereColumn('sp.square_order_id', 'square_orders.square_order_id');
            });
        }
        $square = $squareQuery
            ->whereIn('state', ['COMPLETED', 'completed'])->where('total_money_currency', 'USD')->whereBetween('closed_at', [$startsAt, $endsAt])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total_money_amount), 0) as gross_cents, MAX(closed_at) as observed_end')->first();
        if ((int) $square->getAttribute('order_count') > 0) {
            $channels = collect($summary['channels'])->reject(fn ($c) => $c['key'] === 'legacy_square')->values();
            $channels->push(['key' => 'square_provider', 'label' => 'Square completed orders', 'order_count' => (int) $square->getAttribute('order_count'), 'revenue_cents' => (int) $square->getAttribute('gross_cents'), 'latest_order_at' => $square->getAttribute('observed_end')]);
            $summary = [...$summary, 'channels' => $channels->all(), 'revenue_cents' => $channels->sum('revenue_cents'), 'order_count' => $channels->sum('order_count'), 'channel_count' => $channels->count()];
        }

        return [...$summary, 'basis' => 'Gross operational order amounts; refunds, taxes, processing fees and payouts require reconciliation.'];
    }

    private function hasLivePos(): bool
    {
        return is_string(config('pos_live.runtime_id')) && config('pos_live.runtime_id') !== '' && Schema::hasTable('pos_live_sales');
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
        $summary = WebsiteOrder::query()->withoutPosSimulation()
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
