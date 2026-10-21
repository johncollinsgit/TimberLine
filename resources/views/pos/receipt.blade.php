@extends('pos.layout')
@section('title', 'Simulation receipt')
@section('content')
    @php($money = fn ($cents) => '$'.number_format($cents / 100, 2))
    <div class="receipt">
        <div class="actions no-print" style="margin-bottom:1rem"><a class="button secondary" href="{{ route('pos.show', ['attempt' => $attempt->id, 'tenant' => $tenant->slug]) }}">Back to sale</a><button type="button" onclick="window.print()">Print simulation receipt</button></div>
        <article class="panel">
            <header><div class="eyebrow">Simulation receipt</div><h1>{{ $tenant->name }}</h1><p>{{ $register->name }}<br>{{ $order->number }}<br>{{ ($order->paid_at ?? $attempt->created_at)->format('M j, Y H:i') }}</p><strong>Practice transaction · No money moved</strong></header>
            <table style="margin-top:1rem"><thead><tr><th scope="col">Item</th><th scope="col" class="number">Qty</th><th scope="col" class="number">Total</th></tr></thead><tbody>
                @foreach ($order->lines as $line)<tr><td><strong>{{ $line->title }}</strong><span class="sub">{{ $money($line->unit_price_cents) }} each</span></td><td class="number">{{ $line->quantity }}</td><td class="number">{{ $money($line->line_total_cents) }}</td></tr>@endforeach
            </tbody></table>
            <dl class="totals" style="margin-top:1rem"><div><dt>Subtotal</dt><dd>{{ $money($order->subtotal_cents) }}</dd></div><div><dt>Simulation tax</dt><dd>{{ $money($order->tax_cents) }}</dd></div><div class="total"><dt>Verified simulated total</dt><dd>{{ $money($order->total_cents) }} USD</dd></div>@if ($refundedCents > 0)<div><dt>Verified simulated refunds</dt><dd>{{ $money($refundedCents) }}</dd></div>@endif</dl>
            <footer><p>Simulated smart reader · Practice sale #{{ $attempt->id }}</p><p class="muted compact">This records a local practice sale. It is not evidence of a card charge, payment settlement or a configured physical reader.</p></footer>
        </article>
    </div>
@endsection
