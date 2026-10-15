<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $update['subject'] }}</title></head>
<body style="font-family:Arial,sans-serif;color:#18263a;line-height:1.6;max-width:620px;margin:32px auto;padding:0 20px">
    <p>{{ $update['brand'] }}</p>
    <h1 style="font-size:25px">{{ $update['heading'] }}</h1>
    <p>Order {{ $update['order_number'] }}</p>
    <p>{{ $update['message'] }}</p>
    @if($update['kind'] === 'confirmed')
        <ul>@foreach($update['items'] as $item)<li>{{ $item['quantity'] }} × {{ $item['title'] }} — ${{ number_format($item['line_total_cents'] / 100, 2) }}</li>@endforeach</ul>
        <p>Subtotal: ${{ number_format($update['subtotal_cents'] / 100, 2) }}<br>Shipping: ${{ number_format($update['shipping_cents'] / 100, 2) }}<br>Tax: ${{ number_format($update['tax_cents'] / 100, 2) }}<br><strong>Total paid: ${{ number_format($update['total_cents'] / 100, 2) }} {{ strtoupper($update['currency']) }}</strong></p>
    @endif
    @if($update['tracking_number'])<p>{{ $update['carrier'] }} tracking: {{ $update['tracking_number'] }}</p>@endif
    <p><a href="{{ $update['order_url'] }}" rel="noreferrer">View your order and shipping updates</a></p>
    <p style="font-size:12px;color:#536273">Keep this private order link secure. This is a transactional update about your order.</p>
</body></html>
