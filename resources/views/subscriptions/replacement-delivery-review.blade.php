@php
    $modernForestry = $tenant->slug === 'modern-forestry';
    $terms = $review['quote']['terms'];
    $evidence = $review['evidence'];
    $interval = $terms['billing_months'] === 1 ? 'month' : $terms['billing_months'].' months';
@endphp
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow">
<title>Review your delivery | {{ $tenant->name }}</title>@vite('resources/css/subscriptions-pilot-customer.css')
</head><body class="club-page">
<a class="skip-link" href="#delivery-review">Skip to delivery review</a>
<header class="club-header"><div class="club-wrap brand-row">@if($modernForestry)<img class="club-logo" src="{{ asset('brand/modern-forestry-logo-white.png') }}" alt="Modern Forestry" width="180" height="68">@else<span class="merchant-name">{{ $tenant->name }}</span>@endif<span class="header-label">YOUR MEMBERSHIP</span></div></header>
<main class="club-wrap delivery-review-wrap"><section class="club-intro"><p class="eyebrow">ONE LAST LOOK</p><h1>Review your next delivery.</h1><p class="intro-copy">Make sure the address, scents and future total look right.</p></section>
<section id="delivery-review" class="paper-card terms-card delivery-review-card" aria-labelledby="review-title"><div class="card-heading"><p class="eyebrow">YOUR UPDATED DELIVERY</p><span class="member-badge">Review</span></div><h2 id="review-title">Ready for your next ritual</h2>
<ul class="subscription-lines">@foreach($terms['lines'] as $line)<li>{{ $line['quantity'] }} × {{ $line['label'] }}</li>@endforeach</ul>
<dl class="terms-list"><div><dt>Deliver to</dt><dd>{{ $terms['shipping_address']['firstName'] }} {{ $terms['shipping_address']['lastName'] }}<br>{{ $terms['shipping_address']['address1'] }}@if(!empty($terms['shipping_address']['address2'])), {{ $terms['shipping_address']['address2'] }}@endif<br>{{ $terms['shipping_address']['city'] }}, {{ $terms['shipping_address']['provinceCode'] }} {{ $terms['shipping_address']['zip'] }}</dd></div>
<div><dt>Starts with</dt><dd>{{ $agreement->status === 'paused' ? 'Your next delivery after resuming' : $agreement->next_billing_date->format('F j, Y') }}</dd></div>
<div><dt>Membership</dt><dd>{{ $terms['currency'] }} {{ number_format($terms['subtotal_cents']/100,2) }}</dd></div><div><dt>Shipping</dt><dd>{{ $terms['currency'] }} {{ number_format($terms['shipping_cents']/100,2) }}</dd></div>
<div><dt>Estimated tax</dt><dd>{{ $terms['currency'] }} {{ number_format($terms['tax_cents']/100,2) }}</dd></div>
<div class="delivery-total"><dt>Future total</dt><dd><strong>{{ $terms['currency'] }} {{ number_format($terms['amount_cents']/100,2) }}</strong> / {{ $interval }}</dd></div></dl>
<p class="fine-print">A changed tax total requires another review before billing. Your existing saved payment method stays on this membership.</p>
@if($agreement->status === 'paused')<p class="notice">Your subscription will stay paused. Saving delivery details does not resume billing.</p>@endif
<form method="POST" action="{{ route('subscriptions.replacement.customer.delivery.confirm', ['token' => $token]) }}" class="delivery-confirmation">@csrf
<input type="hidden" name="request_id" value="{{ $evidence['request_uuid'] }}"><input type="hidden" name="revision" value="{{ $evidence['revision'] }}"><input type="hidden" name="review_hash" value="{{ $evidence['review_hash'] }}">
<label class="delivery-consent"><input type="checkbox" name="confirm_future_delivery" value="1" required><span>I’ve reviewed this future delivery and the {{ $terms['currency'] }} {{ number_format($terms['amount_cents']/100,2) }} total per {{ $interval }}.</span></label>
<div class="button-row"><button class="club-button primary-button">Save future delivery</button><a class="club-button secondary-button" href="{{ route('subscriptions.replacement.customer', ['token' => $token]) }}">Go back</a></div>
<p class="fine-print">You won’t be charged when you save these changes. This review expires at {{ \Carbon\CarbonImmutable::createFromTimestamp($evidence['valid_until'])->timezone($terms['timezone'])->format('g:i A T') }}.</p></form>
</section><footer class="club-footer"><p>{{ $modernForestry ? 'Made with care. Enjoyed at home.' : $tenant->name }}</p>@if($modernForestry)<a href="mailto:info@theforestrystudio.com">Need a hand? Contact Modern Forestry</a>@endif</footer></main></body></html>
