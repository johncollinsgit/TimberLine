<x-shopify-embedded-shell :authorized="$authorized" :shopify-api-key="$shopifyApiKey" :shop-domain="$shopDomain" :host="$host" :store-label="$storeLabel" :headline="$headline" :subheadline="$subheadline" :app-navigation="$appNavigation" :page-subnav="$pageSubnav ?? []" :page-actions="$pageActions">
    @if ($authorized && is_array($customer))
        <div class="space-y-6">
            <section class="fb-page-surface p-6">
                <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
                    <div>
                        <div class="text-sm text-zinc-600">{{ $customer['primary_buyer'] ?: 'Buyer not recorded' }}</div>
                        <div class="mt-2 flex flex-wrap gap-2 text-sm text-zinc-700">
                            @if ($customer['email']) <span>{{ $customer['email'] }}</span> @endif
                            @if ($customer['phone']) <span>· {{ $customer['phone'] }}</span> @endif
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($customer['source_stores'] as $source)
                                <span class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs font-semibold uppercase text-zinc-600">{{ $source }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-5 py-4">
                        <div class="text-xs font-semibold uppercase text-zinc-500">Recommended attention</div>
                        <div class="mt-2 text-lg font-semibold capitalize text-zinc-950">{{ str_replace('_', ' ', $customer['timing_state']) }}</div>
                        <div class="mt-1 text-sm text-zinc-600">Risk: {{ $customer['risk_level'] }}</div>
                    </div>
                </div>
            </section>

            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['Lifetime revenue', '$'.number_format($customer['lifetime_revenue'], 2)],
                    ['Trailing 12 months', '$'.number_format($customer['trailing_12_revenue'], 2)],
                    ['Revenue this year', '$'.number_format($customer['revenue_this_year'], 2)],
                    ['Prior trailing 12 months', '$'.number_format($customer['prior_trailing_12_revenue'], 2)],
                    ['Revenue change', $customer['revenue_change_percent'] !== null ? $customer['revenue_change_percent'].'%' : 'Not enough history'],
                    ['Wholesale orders', number_format($customer['order_count'])],
                    ['Average order value', '$'.number_format($customer['average_order_value'], 2)],
                    ['First wholesale order', optional($customer['first_order_at'])->format('M j, Y') ?: '—'],
                    ['Most recent order', optional($customer['last_order_at'])->format('M j, Y') ?: '—'],
                    ['Median reorder interval', $customer['median_reorder_days'] ? $customer['median_reorder_days'].' days' : 'Not enough history'],
                    ['Average reorder interval', $customer['average_reorder_days'] ? $customer['average_reorder_days'].' days' : 'Not enough history'],
                    ['Predicted reorder', optional($customer['predicted_reorder_at'])->format('M j, Y') ?: '—'],
                    ['Timing variance', $customer['days_relative_to_reorder'] !== null ? abs($customer['days_relative_to_reorder']).' days '.($customer['days_relative_to_reorder'] >= 0 ? 'late' : 'early') : '—'],
                    ['Days since last order', $customer['days_since_last_order'] ?? '—'],
                    ['Wholesale refunds', '$'.number_format($customer['refund_total'], 2)],
                ] as [$label, $value])
                    <div class="fb-page-surface p-4"><div class="text-xs font-semibold uppercase text-zinc-500">{{ $label }}</div><div class="mt-2 text-lg font-semibold text-zinc-950">{{ $value }}</div></div>
                @endforeach
            </section>

            <section class="grid gap-6 lg:grid-cols-2">
                @foreach ([['Most purchased products', $customer['products']], ['Most purchased scents', $customer['scents']]] as [$heading, $rows])
                    <div class="fb-page-surface overflow-hidden"><div class="border-b border-zinc-200 px-6 py-4"><h2 class="font-semibold text-zinc-950">{{ $heading }}</h2><p class="mt-1 text-xs text-zinc-500">Calculated only from this account's qualified wholesale order lines.</p></div>@forelse($rows->take(10) as $row)<div class="grid grid-cols-[minmax(0,1fr)_80px_110px] gap-3 border-b border-zinc-100 px-6 py-3 last:border-0"><div class="font-medium text-zinc-950">{{ $row['label'] }}</div><div class="text-sm text-zinc-600">{{ $row['units'] }} units</div><div class="text-right text-sm font-semibold">${{ number_format($row['revenue'], 2) }}</div></div>@empty<div class="px-6 py-8 text-sm text-zinc-600">No classified wholesale line items yet.</div>@endforelse</div>
                @endforeach
            </section>

            <section class="fb-page-surface overflow-hidden">
                <div class="border-b border-zinc-200 px-6 py-4"><h2 class="font-semibold text-zinc-950">Wholesale order history</h2></div>
                @foreach ($customer['orders'] as $order)
                    <div class="grid gap-3 border-b border-zinc-100 px-6 py-4 last:border-0 md:grid-cols-[minmax(0,1fr)_140px_140px]">
                        <div><div class="font-semibold text-zinc-950">{{ $order->order_number ?: $order->shopify_name ?: 'Order' }}</div><div class="mt-1 text-sm text-zinc-600">{{ optional($order->ordered_at)->format('M j, Y') ?: 'Date unavailable' }} · source: {{ $order->shopify_store_key ?: $order->shopify_store ?: 'confirmed legacy' }}</div></div>
                        <div><div class="text-xs uppercase text-zinc-500">Status</div><div class="font-semibold capitalize">{{ str_replace('_', ' ', $order->status) }}</div></div>
                        <div><div class="text-xs uppercase text-zinc-500">Final revenue</div><div class="font-semibold">${{ number_format(max(0, (float) $order->total_price - (float) $order->refund_total), 2) }}</div></div>
                    </div>
                @endforeach
            </section>

            <section class="grid gap-6 lg:grid-cols-2">
                <div class="fb-page-surface overflow-hidden"><div class="border-b border-zinc-200 px-6 py-4"><h2 class="font-semibold text-zinc-950">Suggestions and decisions</h2></div>@forelse($customer['suggestions'] as $suggestion)<div class="border-b border-zinc-100 px-6 py-4 last:border-0"><div class="flex justify-between gap-3"><div class="font-semibold text-zinc-950">{{ $suggestion->title }}</div><div class="text-xs font-semibold uppercase text-zinc-500">{{ $suggestion->status }}</div></div><p class="mt-2 text-sm text-zinc-600">{{ $suggestion->reason }}</p><div class="mt-2 text-xs text-zinc-500">{{ $suggestion->confidence }}% confidence · {{ $suggestion->decisions->count() }} recorded decision(s)</div></div>@empty<div class="px-6 py-8 text-sm text-zinc-600">No suggestions for this account.</div>@endforelse</div>
                <div class="fb-page-surface overflow-hidden"><div class="border-b border-zinc-200 px-6 py-4"><h2 class="font-semibold text-zinc-950">Follow-up history</h2></div>@forelse($customer['follow_ups'] as $followUp)<div class="border-b border-zinc-100 px-6 py-4 last:border-0"><div class="flex justify-between gap-3"><div class="font-semibold text-zinc-950">{{ $followUp->title }}</div><div class="text-xs font-semibold uppercase text-zinc-500">{{ str_replace('_', ' ', $followUp->status) }}</div></div><div class="mt-2 text-sm text-zinc-600">{{ $followUp->due_at ? 'Due '.$followUp->due_at->format('M j, Y g:i A') : 'No due date' }}{{ $followUp->outcome ? ' · '.$followUp->outcome : '' }}</div></div>@empty<div class="px-6 py-8 text-sm text-zinc-600">No follow-ups recorded.</div>@endforelse</div>
            </section>

            <section class="fb-page-surface overflow-hidden"><div class="border-b border-zinc-200 px-6 py-4"><h2 class="font-semibold text-zinc-950">Relationship timeline</h2></div>@foreach($customer['timeline'] as $event)<div class="grid gap-2 border-b border-zinc-100 px-6 py-4 last:border-0 sm:grid-cols-[150px_150px_minmax(0,1fr)]"><div class="text-sm text-zinc-500">{{ optional($event['at'])->format('M j, Y g:i A') ?: 'Date unavailable' }}</div><div class="text-xs font-semibold uppercase text-zinc-500">{{ str_replace('_', ' ', $event['type']) }}</div><div class="text-sm text-zinc-800">{{ $event['summary'] }}</div></div>@endforeach</section>
        </div>
    @endif
</x-shopify-embedded-shell>
