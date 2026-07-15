@php $embeddedUrl = static fn (string $url): string => app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class)->append($url, request()); @endphp
<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-subnav="$pageSubnav ?? []"
    :page-actions="$pageActions"
>
    @if ($authorized && is_array($payload))
        @php
            $money = static fn (float|int|string|null $value): string => '$'.number_format((float) $value, 2);
            $metrics = $payload['metrics'];
            $attention = $payload['attention'];
        @endphp

        <div class="space-y-6">
            <section class="fb-page-surface fb-page-surface--subtle p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="fb-kpi-label">Decision center</div>
                        <h2 class="mt-2 text-3xl font-semibold text-zinc-950">What needs attention</h2>
                        <p class="mt-2 max-w-3xl text-sm text-zinc-600">
                            Every count and dollar value below is restricted server-side to the wholesale store or a confirmed legacy wholesale classification.
                        </p>
                    </div>
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        {{ $payload['scope_label'] }} · as of {{ $payload['as_of']->format('M j, Y g:i A') }}
                    </div>
                </div>

                <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ([
                        ['Suggestions', $attention['suggestions'] ?? 0, 'shopify.app.wholesale.suggestions', 'amber'],
                        ['Follow-ups due', $attention['follow_ups_due'] ?? 0, 'shopify.app.wholesale.follow-ups', 'rose'],
                        ['Applications', $attention['applications'] ?? 0, 'shopify.app.wholesale.applications', 'amber'],
                        ['Due for reorder', $attention['due_for_reorder'], 'shopify.app.wholesale.customers', 'sky'],
                        ['At risk', $attention['at_risk'], 'shopify.app.wholesale.customers', 'rose'],
                        ['Lapsed', $attention['lapsed'], 'shopify.app.wholesale.customers', 'zinc'],
                        ['Classification review', $attention['ambiguous_legacy'], 'shopify.app.wholesale.orders', 'violet'],
                    ] as [$label, $value, $routeName, $tone])
                        <a href="{{ route($routeName, ['store_key' => 'wholesale'], false) }}" class="rounded-2xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-400">
                            <div class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">{{ $label }}</div>
                            <div class="mt-2 text-3xl font-semibold text-zinc-950">{{ number_format($value) }}</div>
                            <div class="mt-2 text-xs font-semibold text-zinc-600">Review →</div>
                        </a>
                    @endforeach
                </div>
            </section>

            <section class="fb-page-surface p-6">
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <div class="fb-kpi-label">Wholesale metrics</div>
                        <h2 class="mt-2 text-xl font-semibold text-zinc-950">Qualified performance</h2>
                    </div>
                    <div class="text-xs text-zinc-500">America/New_York reporting dates</div>
                </div>
                <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ([
                        ['Revenue this month', $money($metrics['revenue_month'])],
                        ['Revenue this year', $money($metrics['revenue_year'])],
                        ['Trailing 12 months', $money($metrics['revenue_trailing_12'])],
                        ['Wholesale orders', number_format($metrics['order_count'])],
                        ['Active customers', number_format($metrics['active_customers'])],
                        ['New this month', number_format($metrics['new_customers'])],
                        ['Repeat-order rate', number_format($metrics['repeat_order_rate'], 1).'%'],
                        ['Average order value', $money($metrics['average_order_value'])],
                    ] as [$label, $value])
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-500">{{ $label }}</div>
                            <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-2">
                <section class="fb-page-surface overflow-hidden">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4">
                        <h2 class="font-semibold text-zinc-950">Priority accounts</h2>
                        <a href="{{ $embeddedUrl(route('shopify.app.wholesale.customers', [], false)) }}" class="text-xs font-semibold text-zinc-600">View customers →</a>
                    </div>
                    @forelse ($payload['customers'] as $customer)
                        <a href="{{ $embeddedUrl(route('shopify.app.wholesale.customers.show', ['accountKey' => $customer['public_key']], false)) }}" class="flex items-center justify-between gap-4 border-b border-zinc-100 px-6 py-4 last:border-0 hover:bg-zinc-50">
                            <div class="min-w-0">
                                <div class="truncate font-semibold text-zinc-950">{{ $customer['company'] }}</div>
                                <div class="mt-1 text-sm text-zinc-600">{{ $customer['order_count'] }} orders · last {{ optional($customer['last_order_at'])->format('M j, Y') ?: 'unknown' }}</div>
                            </div>
                            <div class="text-right">
                                <div class="font-semibold text-zinc-950">{{ $money($customer['lifetime_revenue']) }}</div>
                                <div class="mt-1 text-xs font-semibold uppercase text-zinc-500">{{ str_replace('_', ' ', $customer['timing_state']) }}</div>
                            </div>
                        </a>
                    @empty
                        <div class="px-6 py-10 text-sm text-zinc-600">No qualified wholesale customers have been imported yet.</div>
                    @endforelse
                </section>

                <section class="fb-page-surface overflow-hidden">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4">
                        <h2 class="font-semibold text-zinc-950">Recent wholesale orders</h2>
                        <a href="{{ $embeddedUrl(route('shopify.app.wholesale.orders', [], false)) }}" class="text-xs font-semibold text-zinc-600">View orders →</a>
                    </div>
                    @forelse ($payload['recent_orders'] as $order)
                        <div class="flex items-center justify-between gap-4 border-b border-zinc-100 px-6 py-4 last:border-0">
                            <div>
                                <div class="font-semibold text-zinc-950">{{ $order->display_name }}</div>
                                <div class="mt-1 text-sm text-zinc-600">{{ $order->order_number ?: $order->shopify_name ?: 'Order' }} · {{ optional($order->ordered_at)->format('M j, Y') ?: 'Date unavailable' }}</div>
                            </div>
                            <div class="text-right">
                                <div class="font-semibold text-zinc-950">{{ $money(max(0, (float) $order->total_price - (float) $order->refund_total)) }}</div>
                                <div class="mt-1 text-xs uppercase text-zinc-500">{{ $order->shopify_store_key ?: $order->shopify_store ?: 'confirmed legacy' }}</div>
                            </div>
                        </div>
                    @empty
                        <div class="px-6 py-10 text-sm text-zinc-600">No qualified wholesale orders have been imported yet.</div>
                    @endforelse
                </section>
            </div>
        </div>
    @endif
</x-shopify-embedded-shell>
