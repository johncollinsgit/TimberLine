@php
    $brand = (string) ($settings['brand_name'] ?? 'Modern Forestry');
    $headline = (string) ($settings['headline'] ?? 'Your bag is still waiting');
    $body = (string) ($settings['body'] ?? 'The candles you picked are still in your bag if you want to come back and finish checkout.');
    $ctaLabel = (string) ($settings['cta_label'] ?? 'Finish checkout');
    $ctaUrl = (string) ($settings['cta_url'] ?? 'https://theforestrystudio.com/cart');
    $items = collect((array) ($snapshot->items ?? []));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $headline }}</title>
</head>
<body style="margin:0; padding:0; background:#f6f1e7; color:#1d1b18; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
    <div style="max-width:640px; margin:0 auto; padding:32px 20px;">
        <div style="background:#ffffff; border-radius:24px; padding:28px; box-shadow:0 18px 40px rgba(26,24,20,0.08);">
            <div style="font-size:13px; letter-spacing:0.18em; text-transform:uppercase; color:#46604c; font-weight:700;">{{ $brand }}</div>
            <h1 style="margin:12px 0 12px; font-size:32px; line-height:1.1;">{{ $headline }}</h1>
            <p style="margin:0 0 18px; font-size:16px; line-height:1.6; color:#5f5a54;">{{ $body }}</p>

            @if($snapshot->subtotal_amount)
                <p style="margin:0 0 20px; font-size:15px; color:#46604c; font-weight:600;">
                    Current bag: {{ number_format((float) $snapshot->subtotal_amount, 2) }} {{ $snapshot->currency_code ?: 'USD' }} across {{ $snapshot->item_count }} item{{ $snapshot->item_count === 1 ? '' : 's' }}.
                </p>
            @endif

            @if($items->isNotEmpty())
                <div style="margin:0 0 24px;">
                    @foreach($items->take(4) as $item)
                        <div style="padding:14px 0; border-top:1px solid #ece5d8;">
                            <div style="font-size:16px; font-weight:600;">{{ data_get($item, 'productTitle', 'Candle') }}</div>
                            <div style="font-size:14px; color:#7b736a;">
                                {{ data_get($item, 'variantTitle', 'Standard') }} · Qty {{ (int) data_get($item, 'quantity', 1) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <a href="{{ $ctaUrl }}" style="display:inline-block; padding:14px 24px; background:#2f5b3e; color:#ffffff; text-decoration:none; border-radius:999px; font-weight:700;">{{ $ctaLabel }}</a>
        </div>
    </div>
</body>
</html>
