<x-layouts::app.sidebar title="Sales channels">
    <flux:main>
        <div class="mx-auto max-w-[1280px] space-y-6 pb-10">
            <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[.15em] text-emerald-800">Reporting</p>
                    <h1 class="mt-1 text-3xl font-bold tracking-tight text-zinc-950">Sales channels</h1>
                    <p class="mt-1 max-w-3xl text-sm text-zinc-600">A read-only view of confirmed sales by source. Each channel keeps its own orders, customers, checkout, and operational workflows.</p>
                </div>
                <form method="GET" class="flex items-center gap-2">
                    <label class="sr-only" for="sales-channel-range">Date range</label>
                    <select id="sales-channel-range" name="range" onchange="this.form.submit()" class="rounded-lg border-zinc-300 text-sm font-semibold text-zinc-800">
                        @foreach($range['options'] as $key => $label)<option value="{{ $key }}" @selected($range['key'] === $key)>{{ $label }}</option>@endforeach
                    </select>
                </form>
            </header>

            <section class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[.14em] text-zinc-500">Confirmed revenue</p><p class="mt-2 text-3xl font-bold tracking-tight text-zinc-950">${{ number_format($summary['revenue_cents'] / 100, 2) }}</p><p class="mt-1 text-sm text-zinc-600">{{ $range['label'] }}</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[.14em] text-zinc-500">Orders</p><p class="mt-2 text-3xl font-bold tracking-tight text-zinc-950">{{ number_format($summary['order_count']) }}</p><p class="mt-1 text-sm text-zinc-600">Confirmed in this range</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[.14em] text-zinc-500">Active channels</p><p class="mt-2 text-3xl font-bold tracking-tight text-zinc-950">{{ number_format($summary['channel_count']) }}</p><p class="mt-1 text-sm text-zinc-600">Sources with confirmed sales</p></div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
                <div class="border-b border-zinc-200 px-5 py-4"><h2 class="text-sm font-bold text-zinc-950">Channel performance</h2><p class="mt-1 text-sm text-zinc-600">Website sales appear after payment confirmation. Existing sources remain unchanged.</p></div>
                <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500"><tr><th class="px-5 py-3">Channel</th><th class="px-5 py-3 text-right">Orders</th><th class="px-5 py-3 text-right">Confirmed revenue</th><th class="px-5 py-3 text-right">Latest sale</th></tr></thead><tbody class="divide-y divide-zinc-100">@forelse($summary['channels'] as $channel)<tr><td class="px-5 py-4 font-semibold text-zinc-950">{{ $channel['label'] }}@if($channel['key'] === 'everbranch_website')<span class="ml-2 rounded-full bg-emerald-100 px-2 py-1 text-xs font-bold text-emerald-900">Native</span>@endif</td><td class="px-5 py-4 text-right text-zinc-700">{{ number_format($channel['order_count']) }}</td><td class="px-5 py-4 text-right font-semibold text-zinc-950">${{ number_format($channel['revenue_cents'] / 100, 2) }}</td><td class="px-5 py-4 text-right text-zinc-600">{{ $channel['latest_order_at'] ? \Illuminate\Support\Carbon::parse($channel['latest_order_at'])->format('M j, Y') : '—' }}</td></tr>@empty<tr><td colspan="4" class="px-5 py-14 text-center text-sm text-zinc-500">No confirmed sales in this range. New channels will appear here once their source of truth confirms a sale.</td></tr>@endforelse</tbody></table></div>
            </section>

            <p class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950"><strong>Data boundary:</strong> this page normalizes summaries only. It does not merge customers, duplicate orders, alter Shopify checkout, or trigger marketing.</p>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
