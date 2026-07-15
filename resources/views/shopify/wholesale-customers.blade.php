@php $embeddedUrl = static fn (string $url): string => app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class)->append($url, request()); @endphp
<x-shopify-embedded-shell :authorized="$authorized" :shopify-api-key="$shopifyApiKey" :shop-domain="$shopDomain" :host="$host" :store-label="$storeLabel" :headline="$headline" :subheadline="$subheadline" :app-navigation="$appNavigation" :page-subnav="$pageSubnav ?? []" :page-actions="$pageActions">
    @if ($authorized)
        <section class="fb-page-surface overflow-hidden">
            <form method="GET" action="{{ $embeddedUrl(route('shopify.app.wholesale.customers', [], false)) }}" class="grid gap-3 border-b border-zinc-200 p-4 md:grid-cols-[minmax(0,1fr)_220px_auto]">
                <input name="search" value="{{ $search }}" placeholder="Search company, buyer, email, or phone" class="rounded-xl border border-zinc-300 px-4 py-2.5 text-sm">
                <select name="filter" class="rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-sm"><option value="all">All qualified accounts</option>@foreach(['active' => 'Active', 'new' => 'New this month', 'repeat' => 'Repeat', 'high_value' => 'High value', 'due' => 'Due for reorder', 'at_risk' => 'At risk', 'lapsed' => 'Lapsed', 'first_order_only' => 'First order only'] as $value => $label)<option value="{{ $value }}" @selected($filter === $value)>{{ $label }}</option>@endforeach</select>
                <button class="rounded-full bg-zinc-950 px-5 py-2.5 text-sm font-semibold text-white">Apply</button>
            </form>
            <div class="border-b border-zinc-200 px-6 py-4 text-sm text-zinc-600">
                {{ number_format($customers->count()) }} accounts backed by qualified wholesale order evidence.
            </div>
            @forelse ($customers as $customer)
                <a href="{{ $embeddedUrl(route('shopify.app.wholesale.customers.show', ['accountKey' => $customer['public_key']], false)) }}" class="grid gap-3 border-b border-zinc-100 px-6 py-4 last:border-0 hover:bg-zinc-50 md:grid-cols-[minmax(0,1fr)_110px_130px_125px_125px_110px] md:items-center">
                    <div class="min-w-0">
                        <div class="truncate font-semibold text-zinc-950">{{ $customer['company'] }}</div>
                        <div class="mt-1 truncate text-sm text-zinc-600">{{ $customer['primary_buyer'] ?: 'Buyer not recorded' }} · {{ $customer['email'] ?: 'Email not recorded' }}</div>
                    </div>
                    <div><div class="text-xs uppercase text-zinc-500">Orders</div><div class="font-semibold">{{ $customer['order_count'] }}</div></div>
                    <div><div class="text-xs uppercase text-zinc-500">Lifetime</div><div class="font-semibold">${{ number_format($customer['lifetime_revenue'], 2) }}</div></div>
                    <div><div class="text-xs uppercase text-zinc-500">Trailing 12</div><div class="font-semibold">${{ number_format($customer['trailing_12_revenue'], 2) }}</div></div>
                    <div><div class="text-xs uppercase text-zinc-500">Predicted reorder</div><div class="font-semibold">{{ optional($customer['predicted_reorder_at'])->format('M j') ?: '—' }}</div></div>
                    <div><div class="text-xs uppercase text-zinc-500">Timing</div><div class="font-semibold capitalize">{{ str_replace('_', ' ', $customer['timing_state']) }}</div></div>
                </a>
            @empty
                <div class="px-6 py-12 text-sm text-zinc-600">No qualified wholesale customers are available.</div>
            @endforelse
        </section>
    @endif
</x-shopify-embedded-shell>
