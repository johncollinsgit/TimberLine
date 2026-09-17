@php
    $support = config('everbranch.support_email', 'john@evergrovesoftware.com');
    $receiptUrl = $receipt->receipt_url ?: $receipt->hosted_invoice_url;
    $lines = collect((array) $receipt->billingOrder->line_items)->whereIn('payment_timing', ['due_on_acceptance', 'recurring_current']);
    $itemizedTotal = $lines->sum(fn ($line) => (int) ($line['amount_cents'] ?? 0) * (int) ($line['quantity'] ?? 1));
@endphp
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Payment confirmed · Everbranch</title></head>
<body style="margin:0;background:#f4f7f6;color:#0f1c1f;font-family:Arial,Helvetica,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Thank you, {{ $agreement->tenant->name }}. Your payment of {{ strtoupper($receipt->currency) }} {{ number_format($receipt->total_amount_cents / 100, 2) }} is confirmed.</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7f6;"><tr><td align="center" style="padding:40px 16px;">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;">
<tr><td style="padding:0 0 28px;"><table role="presentation" cellspacing="0" cellpadding="0"><tr><td><img src="{{ asset('brand/everbranch-mark.png') }}" width="42" height="42" alt="" style="display:block;"></td><td style="padding-left:12px;font-size:25px;font-weight:700;letter-spacing:-1px;color:#123c43;">Everbranch</td></tr></table></td></tr>
<tr><td style="padding:36px 28px;background:#ffffff;border:1px solid #dbe4e3;border-top:4px solid #123c43;border-radius:12px;">
<p style="margin:0 0 16px;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#1e5a63;">Payment confirmed</p>
<h1 style="margin:0 0 16px;font-size:30px;line-height:1.2;letter-spacing:-1px;">Thank you for choosing Everbranch.</h1>
<p style="margin:0 0 28px;font-size:16px;line-height:1.7;color:#526164;">{{ $agreement->tenant->name }}, your payment is complete and your signed agreement is on file. We’re glad to be part of what comes next for your business.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7f6;border-radius:8px;"><tr><td style="padding:24px;">
<p style="margin:0 0 6px;font-size:12px;color:#526164;">Amount paid</p>
<p style="margin:0;font-size:40px;line-height:1.2;font-weight:700;letter-spacing:-1px;">${{ number_format($receipt->total_amount_cents / 100, 2) }} <span style="font-size:12px;font-weight:400;letter-spacing:0;">{{ strtoupper($receipt->currency) }}</span></p>
<p style="margin:12px 0 0;font-size:12px;line-height:1.7;color:#526164;">{{ $receipt->paid_at->format('M j, Y · H:i') }} UTC
@if($receipt->invoice_number)<br>Receipt {{ $receipt->invoice_number }}@endif</p>
</td></tr></table>
@if($itemizedTotal === (int) $receipt->subtotal_amount_cents)
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:20px 0;font-size:14px;line-height:1.5;">
@foreach($lines as $line)<tr><td style="padding:10px 12px 10px 0;border-bottom:1px solid #e5ebea;color:#526164;">{{ $line['label'] }}</td><td align="right" style="padding:10px 0;border-bottom:1px solid #e5ebea;white-space:nowrap;">${{ number_format(($line['amount_cents'] ?? 0) * ($line['quantity'] ?? 1) / 100, 2) }}</td></tr>@endforeach
@if($receipt->tax_amount_cents > 0)<tr><td style="padding:10px 0;color:#526164;">Tax</td><td align="right" style="padding:10px 0;">${{ number_format($receipt->tax_amount_cents / 100, 2) }}</td></tr>@endif
</table>
@endif
@if($receiptUrl)<table role="presentation" cellspacing="0" cellpadding="0" style="margin:28px 0 18px;"><tr><td bgcolor="#123c43" style="border-radius:7px;"><a href="{{ $receiptUrl }}" style="display:inline-block;padding:16px 24px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;">View your payment receipt &rarr;</a></td></tr></table>@endif
<p style="margin:0;font-size:14px;line-height:1.7;"><a href="{{ $proposalUrl }}" style="color:#123c43;text-decoration:underline;">View your signed agreement</a></p>
<p style="margin:12px 0 0;font-size:12px;line-height:1.7;color:#657577;">Your agreement link opens directly, with no password. Please keep this private link for your records.</p>
<p style="margin:28px 0 0;padding-top:24px;border-top:1px solid #dbe4e3;font-size:14px;line-height:1.7;color:#526164;">Questions or ready for the next step? <a href="mailto:{{ $support }}" style="color:#123c43;text-decoration:underline;">Contact your Everbranch team</a>.</p>
</td></tr><tr><td style="padding:24px 8px;font-size:12px;line-height:1.7;color:#657577;">Everbranch by Evergrove Software<br>This confirms the payment above. Future service billing follows your signed agreement.</td></tr>
</table></td></tr></table></body></html>
