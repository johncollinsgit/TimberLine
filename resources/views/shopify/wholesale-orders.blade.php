<x-shopify-embedded-shell :authorized="$authorized" :shopify-api-key="$shopifyApiKey" :shop-domain="$shopDomain" :host="$host" :store-label="$storeLabel" :headline="$headline" :subheadline="$subheadline" :app-navigation="$appNavigation" :page-subnav="$pageSubnav ?? []" :page-actions="$pageActions">
    @if ($authorized)
        <section class="fb-page-surface overflow-hidden">
            <div class="border-b border-emerald-200 bg-emerald-50 px-6 py-4 text-sm text-emerald-800">Retail-only and ambiguous legacy orders are excluded server-side.</div>
            @forelse ($orders as $order)
                <div class="grid gap-3 border-b border-zinc-100 px-6 py-4 last:border-0 md:grid-cols-[minmax(0,1fr)_120px_130px_120px] md:items-center">
                    <div><div class="font-semibold text-zinc-950">{{ $order->display_name }}</div><div class="mt-1 text-sm text-zinc-600">{{ $order->order_number ?: $order->shopify_name ?: 'Order' }} · {{ optional($order->ordered_at)->format('M j, Y') ?: 'Date unavailable' }}</div></div>
                    <div><div class="text-xs uppercase text-zinc-500">Source</div><div class="font-semibold uppercase">{{ $order->shopify_store_key ?: $order->shopify_store ?: 'legacy' }}</div></div>
                    <div><div class="text-xs uppercase text-zinc-500">Status</div><div class="font-semibold capitalize">{{ str_replace('_', ' ', $order->status) }}</div></div>
                    <div><div class="text-xs uppercase text-zinc-500">Revenue</div><div class="font-semibold">${{ number_format(max(0, (float) $order->total_price - (float) $order->refund_total), 2) }}</div></div>
                </div>
            @empty
                <div class="px-6 py-12 text-sm text-zinc-600">No qualified wholesale orders are available.</div>
            @endforelse
        </section>
    @endif
</x-shopify-embedded-shell>
