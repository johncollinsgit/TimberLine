<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Everbranch payment receipt</title></head><body style="background:#f4f6f4;color:#183529;font-family:Arial,sans-serif;padding:24px;">
<main style="max-width:680px;margin:auto;background:white;padding:28px;border-radius:12px;">
<p>EVERGROVE · EVERBRANCH</p><h1>Payment received</h1>
<p>{{ $receipt->tenant->name }} · {{ $receipt->invoice_number ?: $receipt->provider_receipt_id }}</p>
<p><strong>{{ strtoupper($receipt->currency) }} {{ number_format($receipt->total_amount_cents / 100, 2) }}</strong> paid {{ $receipt->paid_at->format('M j, Y') }} UTC. Provider tax: ${{ number_format($receipt->tax_amount_cents / 100, 2) }}.</p>
@if($receipt->receipt_url ?: $receipt->hosted_invoice_url)<p><a href="{{ $receipt->receipt_url ?: $receipt->hosted_invoice_url }}">Open the provider payment receipt</a></p>@endif
@include('billing.partials.usage-statement', ['usageStatement' => data_get($receipt->metadata, 'usage_statement')])
<p>Your service subscription and separately funded usage balance follow your accepted agreement. This statement adds no charges. Planned features remain unavailable until released and approved for your workspace.</p>
<p>Questions? {{ config('everbranch.support_email', 'john@evergrovesoftware.com') }}</p>
</main></body></html>
