Everbranch Retail — request received

We have saved the activation request for {{ $business }}.
Reference: {{ $reference }}

Requested offer: {{ $offer['name'] }}
@if(isset($offer['monthly_cents']))
${{ number_format($offer['monthly_cents'] / 100, 2) }}/month per business.
@else
{{-- Preserve already-issued historical confirmations using their saved snapshot. --}}
${{ number_format($offer['introductory_monthly_cents'] / 100, 2) }}/month for {{ $offer['introductory_months'] }} months, then ${{ number_format($offer['ongoing_monthly_cents'] / 100, 2) }}/month.
@endif
One-time setup: ${{ number_format($offer['setup_cents'] / 100, 2) }}.

{{ $offer['eligibility_note'] }}

Your subscription is not active and no payment has been taken. We will review your requirements, eligibility, and written launch scope before activation through Everbranch's existing onboarding process. You have not been given access to the demonstration workspace.

Payment processing, postage, domains, messaging usage, third-party services, and custom work are confirmed separately when applicable.

Thank you,
Evergrove / Everbranch
