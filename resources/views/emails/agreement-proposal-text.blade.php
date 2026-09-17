@php($paid = $agreement->billingOrders->where('agreement_version_id', $agreement->current_version_id)->contains('status', 'paid'))
Everbranch — Your agreement is ready

Prepared for {{ $agreement->tenant->name }}
{{ $agreement->title }}

Review your scope, pricing, and next steps:
{{ $proposalUrl }}

No password needed.
@if($paid)
Your payment is confirmed. Open your agreement to view the payment confirmation or download your permanent copy.
@elseif($agreement->acceptance)
Your agreement is on file. Open it to view its details and any remaining next steps.
@else
Review and accept when you’re ready, then continue to secure payment if applicable.
@endif
@if($agreement->access_expires_at)
Your private link is available through {{ $agreement->access_expires_at->format('F j, Y') }}.
@endif
Please keep this link private; anyone with it can access your agreement.

Questions? {{ config('everbranch.support_email', 'john@evergrovesoftware.com') }}
Everbranch by Evergrove Software
