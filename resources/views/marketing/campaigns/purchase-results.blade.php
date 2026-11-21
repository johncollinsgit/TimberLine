<section class="rounded-2xl border bg-white p-5 space-y-4 dark:bg-zinc-900" aria-label="Campaign effectiveness">
    <div><h2 class="text-xl font-semibold">Campaign effectiveness</h2><p class="mt-1 text-sm text-zinc-500">Recorded message activity and purchases within seven days of the latest qualifying text. Attribution is correlation; it does not prove the text caused a purchase.</p></div>
    <dl class="grid grid-cols-2 gap-4 md:grid-cols-4">
        @foreach(['accepted_sends' => 'Unique accepted sends', 'delivered' => 'Confirmed deliveries', 'sent_only' => 'Sent, delivery unconfirmed', 'failed' => 'Failed', 'clicked_customers' => 'Customers with recorded clicks', 'replies' => 'Customers who replied', 'opt_outs' => 'Recorded opt-outs', 'attributed_buyers' => 'Attributed buyers'] as $key => $label)
            <div><dt class="text-sm text-zinc-500">{{ $label }}</dt><dd class="mt-1 text-2xl font-semibold">{{ $results[$key] ?? 0 }}</dd></div>
        @endforeach
        <div><dt class="text-sm text-zinc-500">Attributed net sales</dt><dd class="mt-1 text-2xl font-semibold">{{ $results['net_sales_minor'] === null ? 'Needs review' : '$'.number_format($results['net_sales_minor']/100, 2) }}</dd><p class="text-xs text-zinc-500">Excludes tax and tips; reflects recorded returns.</p></div>
        <div><dt class="text-sm text-zinc-500">Purchase Candle Cash issued</dt><dd class="mt-1 text-2xl font-semibold">${{ number_format(($results['reward_minor'] ?? 0)/100, 2) }}</dd></div>
        <div><dt class="text-sm text-zinc-500">Modeled conversion rate</dt><dd class="mt-1 text-2xl font-semibold">{{ $results['modeled_conversion_rate'] === null ? '—' : $results['modeled_conversion_rate'].'%' }}</dd><p class="text-xs text-zinc-500">Attributed buyers / unique accepted sends.</p></div>
    </dl>
    <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="py-2">Customer</th><th>Purchase date</th><th>Attribution evidence</th><th>Net sales</th></tr></thead><tbody>
        @forelse($results['purchases'] as $purchase)
            <tr class="border-t"><td class="py-3"><a class="underline" href="{{ $purchase['customer_url'] }}">{{ $purchase['customer_name'] ?: 'Customer' }}</a></td><td>{{ substr($purchase['occurred_at'] ?? '', 0, 10) }}</td><td>{{ ['sent_only_modeled' => 'Modeled · sent, delivery unconfirmed', 'delivered_last_touch' => 'Last text · delivery confirmed', 'clicked_last_touch' => 'Last text · recorded click'][$purchase['basis']] ?? 'Recorded campaign attribution' }}</td><td>{{ $purchase['net_sales_minor'] === null ? 'Unknown' : '$'.number_format($purchase['net_sales_minor']/100, 2) }}</td></tr>
        @empty<tr class="border-t"><td class="py-3" colspan="4">No attributed purchases recorded yet.</td></tr>@endforelse
    </tbody></table></div>
</section>
