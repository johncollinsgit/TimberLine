{{ $update['brand'] }}
{{ $update['heading'] }}
Order {{ $update['order_number'] }}

{{ $update['message'] }}
@if($update['kind'] === 'confirmed')
@foreach($update['items'] as $item)
{{ $item['quantity'] }} x {{ $item['title'] }}: ${{ number_format($item['line_total_cents'] / 100, 2) }}
@endforeach
Subtotal: ${{ number_format($update['subtotal_cents'] / 100, 2) }}
Shipping: ${{ number_format($update['shipping_cents'] / 100, 2) }}
Tax: ${{ number_format($update['tax_cents'] / 100, 2) }}
Total paid: ${{ number_format($update['total_cents'] / 100, 2) }} {{ strtoupper($update['currency']) }}
@endif
@if($update['tracking_number'])
{{ $update['carrier'] }} tracking: {{ $update['tracking_number'] }}
@endif

View your order and shipping updates:
{!! $update['order_url'] !!}

Keep this private order link secure. This is a transactional update about your order.
