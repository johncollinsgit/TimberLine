<section class="sn-wrap sn-order">
    <span class="sn-eyebrow">Thank you for your order</span>
    <h1 class="sn-title">Order <span>{{ $order->number }}</span></h1>
    <p class="sn-copy">Your payment is {{ str($order->payment_status)->replace('_', ' ')->lower() }}. We will share fulfillment updates as your order moves forward.</p>
    <section class="sn-summary sn-order__summary" aria-label="Order summary">
        @foreach($order->lines as $line)
            <div class="sn-summary-row sn-order__item">
                <div class="sn-order__details">
                    <strong>{{ $line->title }}</strong>
                    <span>{{ $line->quantity }} × ${{ number_format($line->unit_price_cents / 100, 2) }}</span>
                </div>
                <strong class="sn-order__amount">${{ number_format($line->line_total_cents / 100, 2) }}</strong>
            </div>
        @endforeach
        @if($order->tax_cents > 0)
            <div class="sn-summary-row sn-order__charge">
                <span>Tax</span>
                <span class="sn-order__amount">${{ number_format($order->tax_cents / 100, 2) }}</span>
            </div>
        @endif
        @if($order->shipping_cents > 0)
            <div class="sn-summary-row sn-order__charge">
                <span>Shipping</span>
                <span class="sn-order__amount">${{ number_format($order->shipping_cents / 100, 2) }}</span>
            </div>
        @endif
        <div class="sn-summary-row sn-order__total">
            <strong>Total</strong>
            <strong class="sn-order__amount">${{ number_format($order->total_cents / 100, 2) }}</strong>
        </div>
    </section>
    <div class="sn-order__actions">
        <a class="sn-btn" href="{{ route('managed-website.store.index') }}">Continue shopping</a>
    </div>
    @include('managed-website.shipment-progress')
</section>
