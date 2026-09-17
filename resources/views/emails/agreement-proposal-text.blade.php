Everbranch — Your agreement is ready

Prepared for {{ $agreement->tenant->name }}
{{ $agreement->title }}

Review your scope, pricing, and next steps:
{{ $proposalUrl }}

No password needed. Review and accept when you’re ready, then continue to secure payment if applicable.
@if($agreement->access_expires_at)
Your private link is available through {{ $agreement->access_expires_at->format('F j, Y') }}.
@endif
Please keep this link private; anyone with it can access your agreement.

Questions? {{ config('everbranch.support_email', 'john@evergrovesoftware.com') }}
Everbranch by Evergrove Software
