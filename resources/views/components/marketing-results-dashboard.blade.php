@props(['results'])
@php
    $results = is_array($results ?? null) ? $results : [];
    $money = static fn (int $cents, string $currency): string => $currency.' '.number_format($cents / 100, 2);
    $micros = static fn (int $value): string => 'USD '.number_format($value / 1000000, 2);
@endphp

<div class="space-y-5" data-marketing-results>
    <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-zinc-200 pb-4">
        <label class="text-sm font-medium text-zinc-700">From
            <input type="date" name="date_from" value="{{ $results['date_from'] ?? '' }}" class="mt-1 block rounded-md border-zinc-300 text-sm">
        </label>
        <label class="text-sm font-medium text-zinc-700">To
            <input type="date" name="date_to" value="{{ $results['date_to'] ?? '' }}" class="mt-1 block rounded-md border-zinc-300 text-sm">
        </label>
        <button class="rounded-md bg-zinc-900 px-4 py-2 text-sm font-semibold text-white">Update</button>
        <span class="text-xs text-zinc-500">{{ data_get($results, 'attribution.label') }} · {{ data_get($results, 'attribution.model') }} · {{ data_get($results, 'attribution.window_days') }}-day window</span>
    </form>

    @if(!($results['has_sales_source'] ?? false))
        <div class="border-l-4 border-amber-500 bg-amber-50 p-4 text-sm text-amber-950">
            <strong>Connect a sales source to measure revenue.</strong>
            Orders and payments must be connected before Everbranch can calculate attributed results.
        </div>
    @else
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border border-zinc-200 bg-white p-4"><div class="text-xs font-medium text-zinc-500">Attributed orders</div><div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($results['attributed_order_count'] ?? 0)) }}</div></div>
            <div class="border border-zinc-200 bg-white p-4"><div class="text-xs font-medium text-zinc-500">Conversion rate</div><div class="mt-2 text-2xl font-semibold text-zinc-950">{{ is_numeric($results['conversion_rate'] ?? null) ? number_format((float) $results['conversion_rate'], 2).'%' : 'Not enough data' }}</div></div>
            <div class="border border-zinc-200 bg-white p-4"><div class="text-xs font-medium text-zinc-500">Messages measured</div><div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($results['delivery_count'] ?? 0)) }}</div></div>
        </div>

        @forelse((array) ($results['currencies'] ?? []) as $row)
            <section class="border border-zinc-200 bg-white p-5">
                <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-base font-semibold text-zinc-950">{{ $row['currency'] }} results</h2><span class="text-xs text-zinc-500">Everbranch-attributed</span></div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <div><div class="text-xs text-zinc-500">Gross revenue</div><div class="mt-1 text-lg font-semibold">{{ $money((int) $row['attributed_gross_cents'], $row['currency']) }}</div></div>
                    <div><div class="text-xs text-zinc-500">Net after refunds</div><div class="mt-1 text-lg font-semibold">{{ $money((int) $row['attributed_net_cents'], $row['currency']) }}</div></div>
                    <div><div class="text-xs text-zinc-500">Net marketing return</div><div class="mt-1 text-lg font-semibold">{{ $money((int) $row['net_marketing_return_cents'], $row['currency']) }}</div></div>
                    <div><div class="text-xs text-zinc-500">ROAS</div><div class="mt-1 text-lg font-semibold">{{ $row['roas'] !== null ? number_format((float) $row['roas'], 2).'x' : 'Not available' }}</div></div>
                    <div><div class="text-xs text-zinc-500">Your messaging spend</div><div class="mt-1 text-lg font-semibold">{{ ($row['cost_currency_compatible'] ?? false) ? $micros((int) $row['buyer_spend_micros']) : 'Kept in USD' }}</div></div>
                    <div><div class="text-xs text-zinc-500">Delivery provider cost</div><div class="mt-1 text-lg font-semibold">{{ ($row['cost_currency_compatible'] ?? false) ? $micros((int) $row['provider_cost_micros']) : 'Kept in USD' }}</div></div>
                </div>
                <p class="mt-4 text-xs text-zinc-500">{{ $row['direct_orders'] }} direct · {{ $row['assisted_orders'] }} assisted · refunds {{ $money((int) $row['refund_cents'], $row['currency']) }}</p>
            </section>
        @empty
            <div class="border border-zinc-200 bg-white p-5 text-sm text-zinc-600">No Everbranch-attributed orders were found in this date range.</div>
        @endforelse

        <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-4">
            @foreach(['by_channel' => 'Channel', 'by_campaign' => 'Campaign', 'by_module' => 'Module', 'by_store' => 'Store or channel'] as $key => $label)
                <section class="border border-zinc-200 bg-white p-4">
                    <h3 class="text-sm font-semibold text-zinc-950">By {{ strtolower($label) }}</h3>
                    <div class="mt-3 divide-y divide-zinc-100">
                        @forelse((array) ($results[$key] ?? []) as $item)
                            <div class="flex items-center justify-between gap-3 py-2 text-sm"><span>{{ $item['label'] }}</span><span class="text-right font-medium">{{ $money((int) $item['net_revenue_cents'], $item['currency']) }}<small class="block font-normal text-zinc-500">{{ $item['orders'] }} orders</small></span></div>
                        @empty
                            <p class="py-2 text-sm text-zinc-500">No attributed activity.</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
