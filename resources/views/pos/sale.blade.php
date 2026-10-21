@extends('pos.layout')
@section('title', 'Practice sale #'.$attempt->id)
@section('content')
    @php
        $money = fn ($cents) => '$'.number_format($cents / 100, 2);
        $labels = ['creating' => 'Preparing reader', 'awaiting_reader' => 'Waiting for simulated reader', 'processing' => 'Reader processing', 'unknown' => 'Outcome needs confirmation', 'review' => 'Needs review', 'succeeded' => 'Simulated sale verified', 'failed' => 'Simulated payment declined', 'cancelled' => 'Sale cancelled'];
        $settledRefunds = (int) $refunds->where('status', 'succeeded')->sum('amount_cents');
        $uncertainRefund = $refunds->contains(fn ($refund) => in_array($refund->status, ['creating', 'pending', 'unknown', 'review'], true));
        $remaining = max(0, $order->total_cents - $settledRefunds);
        $unresolved = in_array($attempt->status, ['creating', 'awaiting_reader', 'processing', 'unknown', 'review'], true);
        $readerControls = $tender['kind'] === 'card' && in_array($attempt->status, ['awaiting_reader', 'processing', 'unknown'], true);
    @endphp
    <div class="page-heading">
        <div><a href="{{ route('pos.register', ['register' => $register->id, 'tenant' => $tenant->slug]) }}">← {{ $register->name }}</a><h1>Practice sale #{{ $attempt->id }}</h1><p class="muted">{{ $order->number }} · {{ $attempt->created_at->format('M j, Y H:i') }}</p></div>
        <span class="status {{ $attempt->status }}">{{ $labels[$attempt->status] ?? 'Needs review' }}</span>
    </div>
    @if (in_array($attempt->status, ['unknown', 'review', 'processing', 'creating'], true))
        <div class="notice" role="status"><strong>The payment is not confirmed.</strong><p>Keep this sale open and check its existing reader result. Do not create a replacement sale. Its stock remains reserved until a verified outcome is recorded.</p></div>
    @elseif ($attempt->status === 'succeeded')
        <div class="notice" role="status"><strong>Practice sale completed.</strong><p>The simulated payment was verified and stock was committed once. No money moved.</p></div>
    @elseif (in_array($attempt->status, ['failed', 'cancelled'], true))
        <div class="notice" role="status"><strong>No completed sale.</strong><p>This sale is {{ $attempt->status === 'failed' ? 'declined' : 'cancelled' }}. Return to the register when you are ready to start another practice sale.</p></div>
    @endif
    <div class="grid">
        <section class="panel" aria-labelledby="sale-heading">
            <h2 id="sale-heading">Frozen sale details</h2>
            <table><thead><tr><th scope="col">Item</th><th scope="col" class="number">Qty</th><th scope="col" class="number">Total</th></tr></thead><tbody>
                @foreach ($order->lines as $line)
                    <tr><td><strong>{{ $line->title }}</strong><span class="sub">{{ $money($line->unit_price_cents) }} each</span></td><td class="number">{{ $line->quantity }}</td><td class="number">{{ $money($line->line_total_cents) }}</td></tr>
                @endforeach
            </tbody></table>
            <dl class="totals" style="margin-top:1rem"><div><dt>Subtotal</dt><dd>{{ $money($order->subtotal_cents) }}</dd></div><div><dt>Simulation tax</dt><dd>{{ $money($order->tax_cents) }}</dd></div><div class="total"><dt>Total USD</dt><dd>{{ $money($order->total_cents) }}</dd></div></dl>
            <p class="muted compact">These are the server-confirmed prices for this sale. Changing the catalog does not change this payment.</p>
            <p class="muted compact">Customer at sale: <strong>{{ data_get($order->customer_snapshot, 'name', 'Guest') }}</strong></p>
            @if($order->website_customer_id)<p class="no-print compact"><a href="{{ route('pos.customers.history', ['register' => $register->id, 'customer' => $order->website_customer_id, 'tenant' => $tenant->slug]) }}">View private practice history</a></p>@endif
            <dl class="totals"><div><dt>Practice payment method</dt><dd>{{ $tender['kind'] === 'cash' ? 'Cash rehearsal' : 'Card rehearsal' }}</dd></div>@if($tender['kind'] === 'cash')<div><dt>Cash received (simulation)</dt><dd>{{ $money($tender['received_cents']) }}</dd></div><div><dt>Change due (simulation)</dt><dd>{{ $money($tender['change_cents']) }}</dd></div>@endif</dl>
        </section>
        <aside class="stack">
            <section class="panel" aria-labelledby="reader-heading">
                <h2 id="reader-heading">{{ $tender['kind'] === 'cash' ? 'Cash rehearsal' : 'Simulated smart reader' }}</h2>
                @if ($tender['kind'] === 'cash' && $attempt->status === 'succeeded')
                    <p class="muted">The cash rehearsal was recorded and stock committed once. The cash received and change above are frozen server values.</p>
                    <a class="button full" href="{{ route('pos.receipt', ['attempt' => $attempt->id, 'tenant' => $tenant->slug]) }}">View simulation receipt</a>
                @elseif ($readerControls)
                    <p class="muted">The total is ready. Choose a practice reader outcome.</p>
                    <div class="actions">
                        @foreach (['approved' => 'Approve simulation', 'declined' => 'Decline simulation', 'unknown' => 'Simulate lost response'] as $outcome => $label)
                            <form method="POST" action="{{ route('pos.simulate', ['attempt' => $attempt->id, 'tenant' => $tenant->slug]) }}">@csrf<input type="hidden" name="outcome" value="{{ $outcome }}"><button type="submit" class="{{ $outcome === 'approved' ? '' : 'secondary' }}">{{ $label }}</button></form>
                        @endforeach
                    </div>
                @elseif ($attempt->status === 'succeeded')
                    <p class="muted">Verified simulated payment. Reopening this page does not collect another payment.</p>
                    <a class="button full" href="{{ route('pos.receipt', ['attempt' => $attempt->id, 'tenant' => $tenant->slug]) }}">View simulation receipt</a>
                @else
                    <p class="muted">{{ $attempt->status === 'review' ? 'Review the saved attempt and restore its original reader binding before continuing.' : 'No reader outcome is needed for this state.' }}</p>
                @endif
                @if ($unresolved)
                    <div class="actions">
                        <form method="POST" action="{{ route('pos.reconcile', ['attempt' => $attempt->id, 'tenant' => $tenant->slug]) }}">@csrf<button class="secondary" type="submit">Check existing payment</button></form>
                        <form method="POST" action="{{ route('pos.cancel', ['attempt' => $attempt->id, 'tenant' => $tenant->slug]) }}">@csrf<button class="secondary" type="submit">Cancel existing sale</button></form>
                    </div>
                @endif
                <p class="muted compact">{{ $tender['kind'] === 'cash' ? 'No cash drawer is controlled and no real cash is recorded by this rehearsal.' : 'A simulated reader is used here. No physical terminal or payment account is connected.' }}</p>
            </section>
            @if ($attempt->status === 'succeeded')
                <section class="panel" aria-labelledby="refund-heading">
                    <h2 id="refund-heading">Refunds</h2>
                    <dl class="totals"><div><dt>Verified simulated refunds</dt><dd>{{ $money($settledRefunds) }}</dd></div><div><dt>Remaining before pending refunds</dt><dd>{{ $money($remaining) }}</dd></div></dl>
                    @if ($uncertainRefund)<p class="notice compact">A refund needs confirmation. Check it below before requesting another.</p>@endif
                    @if ($canRefund)
                        @if ($remaining > 0 && !$uncertainRefund)
                            <form method="POST" action="{{ route('pos.refund', ['attempt' => $attempt->id, 'tenant' => $tenant->slug]) }}" data-refund-form>
                                @csrf<input type="hidden" name="request_id" value="{{ $refundRequestId }}" data-refund-request data-replay="{{ old('request_id') ? 'true' : 'false' }}">
                                <div class="field"><label for="refund-amount">Refund amount (USD)</label><input id="refund-amount" type="text" name="amount" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" value="{{ old('amount', number_format($remaining / 100, 2, '.', '')) }}" required></div>
                                <div class="field"><label for="refund-reason">Reason</label><input id="refund-reason" type="text" name="reason" value="{{ old('reason') }}" minlength="10" maxlength="1000" placeholder="Why is this practice refund needed?" required></div>
                                <button type="submit" class="secondary full" disabled>Request simulated refund</button>
                            </form>
                        @endif
                        <p class="muted compact">Manager action. Refunds use the original {{ $tender['kind'] === 'cash' ? 'cash rehearsal' : 'simulated card payment' }} and do not restock items.</p>
                    @else
                        <p class="muted compact">A manager must authorize refunds.</p>
                    @endif
                </section>
            @endif
        </aside>
    </div>
    @if ($refunds->isNotEmpty())
        <section class="panel" style="margin-top:1.25rem" aria-labelledby="refund-history-heading"><h2 id="refund-history-heading">Refund history</h2>
            @foreach ($refunds as $refund)
                <div id="refund-{{ $refund->id }}" style="padding:.8rem 0; border-bottom:1px solid #e5ebe5"><strong>Practice refund #{{ $refund->id }} · {{ $money($refund->amount_cents) }}</strong> <span class="status {{ $refund->status }}">{{ ucfirst($refund->status) }}</span>
                    @if ($canRefund)
                        <p class="muted compact">{{ $refund->reason }}</p>
                        @if (in_array($refund->status, ['creating', 'pending', 'unknown', 'review'], true))
                            <div class="actions"><form method="POST" action="{{ route('pos.refund.reconcile', ['refund' => $refund->id, 'tenant' => $tenant->slug]) }}">@csrf<button class="secondary" type="submit">Check existing refund</button></form>
                                @if ($refund->status !== 'review')
                                    @foreach (['approved' => 'Approve refund simulation', 'declined' => 'Fail refund simulation'] as $outcome => $label)<form method="POST" action="{{ route('pos.refund.simulate', ['refund' => $refund->id, 'tenant' => $tenant->slug]) }}">@csrf<input type="hidden" name="outcome" value="{{ $outcome }}"><button class="secondary" type="submit">{{ $label }}</button></form>@endforeach
                                @endif
                            </div>
                        @endif
                    @endif
                </div>
            @endforeach
        </section>
    @endif
@endsection
@section('scripts')
@include('pos.request-key-script')
<script>
(() => {
    const input = document.querySelector('[data-refund-request]');
    if (!input) return;
    PosRequestKeys.bind({ form: input.closest('form'), input, scope: @json('everbranch-pos-refund:'.$tenant->id.':'.$attempt->id),
        freshKey: @json($freshRefundRequestId), terminalStatuses: ['succeeded', 'failed'], label: 'simulated refund', nextLabel: 'Prepare another simulated refund',
        records: @json($refundRequestRecords)
    });
})();
</script>
@endsection
