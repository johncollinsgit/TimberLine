Everbranch — Payment confirmed

Thank you, {{ $agreement->tenant->name }}. Your payment is complete and your signed agreement is on file.

Amount paid: {{ strtoupper($receipt->currency) }} {{ number_format($receipt->total_amount_cents / 100, 2) }}
Paid: {{ $receipt->paid_at->format('M j, Y · H:i') }} UTC
@if($receipt->invoice_number)
Receipt: {{ $receipt->invoice_number }}
@endif
@if($receipt->receipt_url ?: $receipt->hosted_invoice_url)
View your payment receipt:
{{ $receipt->receipt_url ?: $receipt->hosted_invoice_url }}
@endif

View your signed agreement:
{{ $proposalUrl }}

Your agreement link opens directly, with no password. Please keep this private link for your records.
Future service billing follows your signed agreement.

Questions or ready for the next step? {{ config('everbranch.support_email', 'john@evergrovesoftware.com') }}
Everbranch by Evergrove Software
