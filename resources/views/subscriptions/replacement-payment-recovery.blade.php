@php
    $modernForestry = $tenant->slug === 'modern-forestry';
    $amount = $payment['currency'].' '.number_format($payment['amount_cents']/100, 2);
    $confirmed = $payment['status'] === 'succeeded' && $payment['order_confirmed'];
@endphp
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow">
<title>{{ $confirmed ? 'Payment confirmed' : 'Your payment' }} | {{ $tenant->name }}</title>
@vite('resources/css/subscriptions-pilot-customer.css')
</head><body class="club-page">
<a class="skip-link" href="#payment">Skip to your payment</a>
<header class="club-header"><div class="club-wrap brand-row">
@if($modernForestry)<img class="club-logo" src="{{ asset('brand/modern-forestry-logo-white.png') }}" alt="Modern Forestry" width="180" height="68">@else<span class="merchant-name">{{ $tenant->name }}</span>@endif
<span class="header-label">{{ $modernForestry ? 'CANDLE CLUB' : 'MEMBER SUBSCRIPTIONS' }}</span>
</div></header>
<main class="club-wrap"><section class="club-intro"><p class="eyebrow">YOUR MEMBERSHIP</p>
<h1>{{ $confirmed ? 'Payment confirmed.' : ($payment['can_authenticate'] ? 'One quick verification.' : 'Let’s check your payment.') }}</h1>
<p class="intro-copy">Your {{ $amount }} payment for {{ \Carbon\CarbonImmutable::parse($payment['cycle_date'])->format('F j, Y') }}.</p></section>
@if($errors->any())<p role="alert" class="notice notice-error">{{ $errors->first() }}</p>@endif
<section id="payment" class="paper-card terms-card" aria-labelledby="payment-title">
@if($confirmed)
<h2 id="payment-title">You’re all set</h2><p>Your payment is confirmed and your order has been received for fulfillment.</p>
@elseif($payment['can_authenticate'])
<h2 id="payment-title">Verify your {{ $amount }} payment</h2>
<p>Your bank needs verification to finish this payment using your saved payment method.</p>
<p class="fine-print">Verification opens in a new tab. When you finish, return here and check your payment status.</p>
<form method="POST" target="_blank" rel="noopener noreferrer" action="{{ route('subscriptions.replacement.customer.payment.verify', ['token' => $token, 'cycleId' => $payment['cycle_public_id']]) }}" class="button-row">@csrf
<input type="hidden" name="review_hash" value="{{ $payment['review_hash'] }}">
<button class="club-button primary-button">Continue payment verification</button></form>
@elseif($payment['provider_payment_received'])
<h2 id="payment-title">Our team is checking this payment</h2><p>We’ve received a payment result and are reviewing the payment and order details. Please contact us before paying another way.</p>
@elseif($payment['status'] === 'declined')
<h2 id="payment-title">Payment wasn’t completed</h2><p>Please contact us for help with this payment and your saved payment method.</p>
@else
<h2 id="payment-title">Your payment is being checked</h2><p>We haven’t confirmed a completed payment. Please check again or contact us before paying another way.</p>
@endif
@if(in_array($payment['future_status'], ['paused', 'cancelled'], true))
<p class="notice">Your future subscription billing is {{ $payment['future_status'] }}. This payment had already started. Verification keeps your future billing {{ $payment['future_status'] }}.</p>
@endif
<div class="button-row"><a class="club-button secondary-button" href="{{ route('subscriptions.replacement.customer.payment.status', ['token' => $token, 'cycleId' => $payment['cycle_public_id']]) }}">Check payment status</a>
<a class="club-button secondary-button" href="{{ route('subscriptions.replacement.customer', ['token' => $token]) }}">Back to your membership</a></div>
</section><footer class="club-footer"><p>{{ $modernForestry ? 'Made with care. Enjoyed at home.' : $tenant->name }}</p>
@if($modernForestry)<a href="mailto:info@theforestrystudio.com">Need a hand? Contact Modern Forestry</a>@endif
<p class="member-link-note">This private link expires after 15 minutes. Reopen it from your signed-in account.</p>
</footer></main></body></html>
