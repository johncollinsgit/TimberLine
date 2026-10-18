Everbranch by Evergrove — Payment received
{{ $receipt->tenant->name }} · {{ $receipt->invoice_number ?: $receipt->provider_receipt_id }}
Amount paid: {{ strtoupper($receipt->currency) }} {{ number_format($receipt->total_amount_cents / 100, 2) }}
Paid: {{ $receipt->paid_at->format('M j, Y') }} UTC
@if($receipt->receipt_url ?: $receipt->hosted_invoice_url)
Provider payment receipt: {{ $receipt->receipt_url ?: $receipt->hosted_invoice_url }}
@endif
@include('billing.partials.usage-statement-text', ['usageStatement' => data_get($receipt->metadata, 'usage_statement')])
This statement adds no charges. Your subscription and separate credit purchases follow your accepted agreement.
Questions? {{ config('everbranch.support_email', 'john@evergrovesoftware.com') }}
