<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    headline="Sales tax reports"
    subheadline="Read-only state summaries and delivery-address detail for reconciliation."
    :app-navigation="$appNavigation"
    :page-subnav="[]"
    :page-actions="[]"
>
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-5 sm:px-6">
        @if (! $authorized || ! $reportingEnabled)
            <div class="border-l-4 border-amber-500 bg-amber-50 p-4 text-sm text-amber-950">Reporting is not available for this store yet.</div>
        @else
            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[.14em] text-emerald-700">Better-reports style presets</p>
                        <h2 class="mt-1 text-lg font-semibold text-zinc-950">State Sales Tax Summary + State Sales Tax Detail</h2>
                        <p class="mt-1 text-sm text-zinc-600">The summary groups Shopify-imported orders by delivery state. The detail keeps the address needed to verify a county or local jurisdiction.</p>
                    </div>
                    <form class="grid gap-2 sm:grid-cols-4" method="GET">
                        <label class="text-xs font-medium text-zinc-700">From<input class="mt-1 w-full rounded-md border-zinc-300 text-sm" name="date_from" type="date" value="{{ request('date_from') }}"></label>
                        <label class="text-xs font-medium text-zinc-700">To<input class="mt-1 w-full rounded-md border-zinc-300 text-sm" name="date_to" type="date" value="{{ request('date_to') }}"></label>
                        <label class="text-xs font-medium text-zinc-700">State<input class="mt-1 w-full rounded-md border-zinc-300 text-sm uppercase" maxlength="16" name="state" placeholder="SC" value="{{ request('state') }}"></label>
                        <button class="self-end rounded-md bg-zinc-950 px-4 py-2 text-sm font-semibold text-white" type="submit">Run reports</button>
                    </form>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-zinc-200 bg-white p-4"><div class="text-xs font-medium uppercase text-zinc-500">Orders</div><div class="mt-1 text-2xl font-semibold">{{ number_format((int) ($report['totals']['orders'] ?? 0)) }}</div></div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4"><div class="text-xs font-medium uppercase text-zinc-500">Taxable sales proxy</div><div class="mt-1 text-2xl font-semibold">${{ number_format((float) ($report['totals']['taxable_sales_proxy'] ?? 0), 2) }}</div></div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4"><div class="text-xs font-medium uppercase text-zinc-500">Tax collected</div><div class="mt-1 text-2xl font-semibold">${{ number_format((float) ($report['totals']['tax_collected'] ?? 0), 2) }}</div></div>
            </div>

            <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
                <div class="border-b border-zinc-200 px-4 py-3"><h2 class="font-semibold text-zinc-950">State Sales Tax Summary</h2></div>
                <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500"><tr><th class="px-4 py-3">State</th><th class="px-4 py-3 text-right">Orders</th><th class="px-4 py-3 text-right">Taxable sales proxy</th><th class="px-4 py-3 text-right">Tax collected</th><th class="px-4 py-3 text-right">Refunds</th></tr></thead><tbody class="divide-y divide-zinc-100">@forelse($report['summary'] as $row)<tr><td class="px-4 py-3 font-medium">{{ $row['state'] }}</td><td class="px-4 py-3 text-right">{{ number_format($row['orders']) }}</td><td class="px-4 py-3 text-right">${{ number_format($row['taxable_sales_proxy'], 2) }}</td><td class="px-4 py-3 text-right">${{ number_format($row['tax_collected'], 2) }}</td><td class="px-4 py-3 text-right">${{ number_format($row['refunds'], 2) }}</td></tr>@empty<tr><td class="px-4 py-6 text-zinc-500" colspan="5">No imported Shopify orders matched this reporting window.</td></tr>@endforelse</tbody></table></div>
            </section>

            <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
                <div class="border-b border-zinc-200 px-4 py-3"><h2 class="font-semibold text-zinc-950">State Sales Tax Detail</h2><p class="mt-1 text-sm text-zinc-600">Use this delivery address to verify county and municipality before filing. Everbranch does not infer a tax jurisdiction.</p></div>
                <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500"><tr><th class="px-4 py-3">Order</th><th class="px-4 py-3">Date</th><th class="px-4 py-3">Delivery address</th><th class="px-4 py-3">State</th><th class="px-4 py-3 text-right">Taxable sales proxy</th><th class="px-4 py-3 text-right">Tax collected</th></tr></thead><tbody class="divide-y divide-zinc-100">@forelse($report['details'] as $row)<tr><td class="whitespace-nowrap px-4 py-3 font-medium">{{ $row['order'] }}</td><td class="whitespace-nowrap px-4 py-3">{{ $row['ordered_at'] }}</td><td class="px-4 py-3">{{ $row['address'] }}</td><td class="px-4 py-3">{{ $row['state'] }}</td><td class="px-4 py-3 text-right">${{ number_format($row['taxable_sales_proxy'], 2) }}</td><td class="px-4 py-3 text-right">${{ number_format($row['tax_collected'], 2) }}</td></tr>@empty<tr><td class="px-4 py-6 text-zinc-500" colspan="6">No imported Shopify orders matched this reporting window.</td></tr>@endforelse</tbody></table></div>
            </section>

            <aside class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950"><ul class="list-disc space-y-1 pl-5">@foreach($report['data_notes'] as $note)<li>{{ $note }}</li>@endforeach</ul></aside>
        @endif
    </div>
</x-shopify-embedded-shell>
