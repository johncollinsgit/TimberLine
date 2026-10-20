<x-layouts::app.sidebar :title="$order->number">
    @php($fulfillmentAddress = $order->shipping_address ?: ($order->fulfillment_method === 'local_delivery' ? data_get($order->service_request, 'checkout.tax_address', data_get($order->service_request, 'delivery_address')) : null))
    <flux:main>
        <div class="mx-auto max-w-6xl space-y-5 pb-10">
            @include('managed-website.catalog.nav')
            @if($errors->any())<div class="border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="alert">{{ $errors->first() }}</div>@endif
            @if(session('status'))<div class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950" role="status">{{ session('status') }}</div>@endif
            <x-ui.operational-header :title="$order->number" :description="'Native Website order · '.$order->created_at->format('M j, Y g:i A')" :back="['href' => route('managed-website.orders.index'), 'label' => 'Orders']">
                <x-ui.status-badge :tone="$order->order_status === 'draft' ? 'warning' : 'neutral'">{{ str($order->order_status)->headline() }}</x-ui.status-badge>
                <x-ui.status-badge :tone="$order->payment_status === 'paid' ? 'success' : 'neutral'">{{ str($order->payment_status)->headline() }}</x-ui.status-badge>
                <x-ui.status-badge :tone="$order->fulfillment_status === 'fulfilled' ? 'success' : 'warning'">{{ str($order->fulfillment_status)->headline() }}</x-ui.status-badge>
            </x-ui.operational-header>
            @if(in_array($order->source,['staff_quote','staff_draft_invoice'],true) && app(\App\Services\ManagedWebsite\WebsiteQuoteOrderService::class)->enabledFor($tenant))<a class="fb-btn fb-btn-secondary" href="{{ route('managed-website.invoices.show',$order) }}">Open quote and invoice details</a>@endif

            @if($order->order_status === 'draft')<div class="border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-5 text-amber-950"><strong>Draft only.</strong> This order has not charged a customer, reserved inventory, created a label, or sent a message.</div>@endif
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1.55fr)_minmax(280px,.75fr)]">
                <div class="space-y-5">
                    <section class="overflow-hidden border border-zinc-200 bg-white"><x-ui.operational-toolbar><h2 class="text-sm font-semibold text-zinc-950">Items</h2><span class="text-xs text-zinc-500">{{ $order->lines->sum('quantity') }} item{{ $order->lines->sum('quantity') === 1 ? '' : 's' }}</span></x-ui.operational-toolbar><div class="divide-y divide-zinc-100">@foreach($order->lines as $line)<div class="flex items-start justify-between gap-4 px-4 py-3"><div><strong class="text-sm font-medium text-zinc-950">{{ $line->title }}</strong><p class="mt-0.5 text-xs text-zinc-500">{{ data_get($line->snapshot, 'variant_title') }}@if(data_get($line->snapshot, 'sku')) · {{ data_get($line->snapshot, 'sku') }}@endif</p></div><div class="text-right text-sm text-zinc-700">{{ $line->quantity }} × ${{ number_format($line->unit_price_cents / 100, 2) }}<strong class="mt-0.5 block font-medium text-zinc-950">${{ number_format($line->line_total_cents / 100, 2) }}</strong></div></div>@endforeach</div><div class="space-y-2 border-t border-zinc-200 px-4 py-3 text-sm text-zinc-700"><div class="flex justify-between"><span>Subtotal</span><span>${{ number_format($order->subtotal_cents / 100, 2) }}</span></div>@if($order->discount_cents > 0)<div class="flex justify-between"><span>Discount</span><span>−${{ number_format($order->discount_cents / 100, 2) }}</span></div>@endif<div class="flex justify-between"><span>Shipping</span><span>${{ number_format($order->shipping_cents / 100, 2) }}</span></div><div class="flex justify-between"><span>Tax</span><span>${{ number_format($order->tax_cents / 100, 2) }}</span></div><div class="flex justify-between border-t border-zinc-200 pt-2 font-semibold text-zinc-950"><span>Total</span><span>${{ number_format($order->total_cents / 100, 2) }}</span></div></div></section>
                    <section class="overflow-hidden border border-zinc-200 bg-white"><x-ui.operational-toolbar><h2 class="text-sm font-semibold text-zinc-950">Timeline</h2><span class="text-xs text-zinc-500">Staff-visible history</span></x-ui.operational-toolbar><ol class="divide-y divide-zinc-100">@forelse($order->events as $event)<li class="px-4 py-3"><p class="text-sm text-zinc-800">{{ $event->message }}</p><p class="mt-1 text-xs text-zinc-500">{{ $event->created_at->format('M j, Y g:i A') }} · {{ str($event->event_type)->headline() }}</p></li>@empty<li class="px-4 py-5 text-sm text-zinc-500">New operational events will appear here.</li>@endforelse</ol></section>
                </div>
                <aside class="space-y-5">
                    <section class="border border-zinc-200 bg-white p-4"><h2 class="text-sm font-semibold text-zinc-950">Customer</h2><p class="mt-3 text-sm font-medium text-zinc-900">{{ data_get($order->customer_snapshot, 'name', 'Website shopper') }}</p><p class="mt-1 text-sm text-zinc-600">{{ data_get($order->customer_snapshot, 'email', 'No email') }}</p><p class="text-sm text-zinc-600">{{ data_get($order->customer_snapshot, 'phone') }}</p>@if($order->website_customer_id)<a class="mt-3 inline-block text-sm font-medium text-emerald-800 hover:underline" href="{{ route('managed-website.customers.show', $order->website_customer_id) }}">Open customer</a>@endif</section>
                    @include('managed-website.commerce.shipment-actions')
                    @include('managed-website.commerce.payment-actions')
                    @include('managed-website.commerce.notification-actions')
                </aside>
            </div>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
