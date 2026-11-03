<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $document === 'invoice' ? 'Order invoice' : 'Packing slip' }} · {{ $order->number }}</title>
    <link rel="stylesheet" href="{{ asset('css/website-order-print.css') }}">
</head>
<body>
    <nav class="print-controls" aria-label="Print controls">
        <a href="{{ route('managed-website.orders.show', $order) }}">Back to order</a>
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </nav>
    <main class="print-document">
        <header><p>{{ $tenant->name }}</p><h1>{{ $document === 'invoice' ? 'Order invoice' : 'Packing slip' }}</h1><p><strong>{{ $order->number }}</strong> · {{ $order->created_at->format('M j, Y') }}</p></header>
        @if($order->order_status === 'draft')<p class="print-notice">Draft order. No payment has been collected for this draft.</p>@endif
        @if($order->order_status === 'cancelled')<p class="print-notice">Cancelled order.</p>@endif
        @if($document === 'invoice')<p>Payment: {{ str($order->payment_status)->headline() }} · Currency: {{ strtoupper($order->currency) }}</p>@endif
        <section class="print-addresses">
            <div><h2>Customer</h2><p>{{ data_get($order->customer_snapshot, 'name') }}<br>{{ data_get($order->customer_snapshot, 'email') }}</p></div>
            <div><h2>{{ $order->fulfillment_method === 'ship' ? 'Ship to' : 'Fulfillment' }}</h2>
                @if($order->shipping_address)<address>{{ data_get($order->shipping_address, 'name') }}<br>{{ data_get($order->shipping_address, 'street1') }}@if(data_get($order->shipping_address, 'street2'))<br>{{ data_get($order->shipping_address, 'street2') }}@endif<br>{{ data_get($order->shipping_address, 'city') }}, {{ data_get($order->shipping_address, 'state') }} {{ data_get($order->shipping_address, 'zip') }}<br>{{ data_get($order->shipping_address, 'country') }}</address>
                @else<p>{{ str($order->fulfillment_method)->headline() }}</p>@endif
            </div>
        </section>
        <table><thead><tr><th>Item</th><th>SKU</th><th class="number">Quantity</th>@if($document === 'invoice')<th class="number">Unit price</th><th class="number">Amount</th>@endif</tr></thead><tbody>
            @foreach($order->lines->filter(fn ($line) => $document === 'invoice' || $line->product_type === 'physical') as $line)
                <tr><td>{{ $line->title }}<span class="variant">{{ data_get($line->snapshot, 'variant_title') }}</span></td><td>{{ data_get($line->snapshot, 'sku', '—') }}</td><td class="number">{{ $line->quantity }}</td>@if($document === 'invoice')<td class="number">{{ number_format($line->unit_price_cents / 100, 2) }}</td><td class="number">{{ number_format($line->line_total_cents / 100, 2) }}</td>@endif</tr>
            @endforeach
        </tbody></table>
        @if($document === 'invoice')
            <dl class="print-totals"><div><dt>Subtotal</dt><dd>{{ number_format($order->subtotal_cents / 100, 2) }}</dd></div>@if($order->discount_cents)<div><dt>Discount</dt><dd>−{{ number_format($order->discount_cents / 100, 2) }}</dd></div>@endif<div><dt>Shipping</dt><dd>{{ number_format($order->shipping_cents / 100, 2) }}</dd></div><div><dt>Tax</dt><dd>{{ number_format($order->tax_cents / 100, 2) }}</dd></div><div class="total"><dt>Order total ({{ strtoupper($order->currency) }})</dt><dd>{{ number_format($order->total_cents / 100, 2) }}</dd></div>@if($order->refunded_cents)<div><dt>Refunded</dt><dd>{{ number_format($order->refunded_cents / 100, 2) }}</dd></div>@endif</dl>
            <p class="print-footer">This document records your order and payment status.</p>
        @else
            <p class="print-footer">Order contents. Verify shipment quantities before packing; this slip does not confirm dispatch.</p>
        @endif
    </main>
</body>
</html>
