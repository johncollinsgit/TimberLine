@php
    $modernForestry = $tenant->slug === 'modern-forestry';
    $terms = $enrollment->terms;
    $interval = $terms['billing_months'] === 1 ? 'month' : $terms['billing_months'].' months';
    $firstDate = \Carbon\CarbonImmutable::parse($terms['first_billing_date'])->format('F j, Y');
    $features = [
        ['01', 'Vote for the next scent', 'Help choose what comes next, and revisit your voting history.'],
        ['02', 'Find your next favorite', 'Explore monthly scents and request a switch to an eligible 16oz favorite.'],
        ['03', 'Share your scent notes', 'Rate a monthly scent and tell our team what you loved.'],
        ['04', 'Keep your address current', 'Manage where your next Candle Club delivery goes.'],
        ['05', 'Make room for a skip', 'Review available skips and pauses when you need a little breathing room.'],
        ['06', 'Your club, at a glance', 'See upcoming billing and deliveries, manage payments, or request cancellation.'],
    ];
@endphp
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $modernForestry ? 'Candle Club' : 'Your subscription' }} | {{ $tenant->name }}</title>
@vite(['resources/css/subscriptions-pilot-customer.css', 'resources/js/subscriptions-pilot-customer.js'])
</head>
<body class="club-page">
<a class="skip-link" href="#subscription">Skip to your subscription</a>
<header class="club-header"><div class="club-wrap brand-row">
@if($modernForestry)<img class="club-logo" src="{{ asset('brand/modern-forestry-logo-white.png') }}" alt="Modern Forestry" width="180" height="68">@else<span class="merchant-name">{{ $tenant->name }}</span>@endif
<span class="header-label">{{ $modernForestry ? 'CANDLE CLUB' : 'MEMBER SUBSCRIPTIONS' }}</span>
</div></header>
<main class="club-wrap">
<section class="club-intro" aria-labelledby="page-title"><p class="eyebrow">{{ $modernForestry ? 'GOOD SCENTS. EVEN BETTER THINGS AHEAD.' : 'YOUR SUBSCRIPTION, SIMPLIFIED.' }}</p>
<h1 id="page-title">{{ $modernForestry ? 'A little more you. A lot more Candle Club.' : 'Your subscription, in your hands.' }}</h1>
<p class="intro-copy">{{ $modernForestry ? 'We’re bringing your favorite monthly ritual a little closer—with more ways to choose, share, and make it your own.' : 'Review your recurring terms and securely manage your payment setup.' }}</p></section>
@if($errors->any())<p role="alert" class="notice notice-error">{{ $errors->first() }}</p>@endif
@if($notice || session('pilot_customer_status'))<p role="status" class="notice">{{ $notice ?? session('pilot_customer_status') }}</p>@endif
<div class="club-layout {{ !$modernForestry ? 'single-column' : '' }}">
<div id="subscription" class="subscription-column">
<section class="paper-card terms-card" aria-labelledby="subscription-title">
<div class="card-heading"><p class="eyebrow">JUST FOR YOU</p><span class="member-badge">{{ $enrollment->status === 'draft' ? 'Review & authorize' : ucfirst($enrollment->status) }}</span></div>
<h2 id="subscription-title">{{ $modernForestry ? 'Your Candle Club' : 'Your subscription' }}</h2><p class="member-greeting">Prepared for {{ $terms['name'] }}</p>
<ul class="subscription-lines">@foreach($terms['lines'] as $line)<li>{{ $line['quantity'] }} × {{ $line['label'] }}</li>@endforeach</ul>
<p class="subscription-price"><span>{{ $terms['currency'] }} {{ number_format($terms['amount_cents']/100, 2) }}</span> / {{ $interval }}</p>
<p class="fine-print">Includes {{ $terms['currency'] }} {{ number_format($terms['shipping_cents']/100, 2) }} shipping and {{ $terms['currency'] }} {{ number_format($terms['tax_cents']/100, 2) }} tax.</p>
<dl class="terms-list"><div><dt>First charge</dt><dd>{{ $firstDate }}</dd></div>
@if($enrollment->next_billing_date)<div><dt>Next charge</dt><dd>{{ $enrollment->next_billing_date->format('F j, Y') }}</dd></div>@endif
<div><dt>Deliver to</dt><dd>{{ $terms['shipping_address']['address1'] }}@if(!empty($terms['shipping_address']['address2'])), {{ $terms['shipping_address']['address2'] }}@endif<br>{{ $terms['shipping_address']['city'] }}, {{ $terms['shipping_address']['provinceCode'] }} {{ $terms['shipping_address']['zip'] }}</dd></div></dl>
<details class="terms-details"><summary>Recurring terms & cancellation</summary><p>{{ $terms['cancellation_policy'] }}</p><p>Candle Cash cannot pay for or discount subscriptions.</p></details>
</section>
@if($enrollment->status === 'draft')
<form method="POST" action="{{ route('subscriptions.pilot.customer.authorize', ['token' => $token]) }}" class="paper-card authorization-card" data-authorization-form>
@csrf<input type="hidden" name="terms_hash" value="{{ $enrollment->terms_hash }}">
<p class="eyebrow">ONE SECURE STEP</p><h2>Authorize recurring payments</h2>
<p>Save a card securely with Stripe for the subscription shown above. <strong>You won’t be charged today.</strong></p>
<p>Your existing subscription continues until {{ $tenant->name }} confirms the billing transfer. Saving a card does not activate new billing.</p>
<label class="consent"><input type="checkbox" name="accepted" value="1" required><span>I authorize {{ $tenant->name }} to save my card with Stripe and charge <strong>{{ $terms['currency'] }} {{ number_format($terms['amount_cents']/100, 2) }} every {{ $interval }}</strong>, beginning <strong>{{ $firstDate }}</strong>, until I pause or cancel. I agree to the recurring terms and cancellation policy above.</span></label>
<button class="club-button primary-button" type="submit" data-authorization-submit>Continue to secure card setup <span aria-hidden="true">↗</span></button>
<p class="secure-note">Card details are entered on Stripe’s secure page.</p>@if($modernForestry)<p class="secure-note">This authorizes payment only. App features shown here are coming soon.</p>@endif<p class="secure-note" role="status" aria-live="polite" data-authorization-status></p>
</form>
@else
<section class="paper-card authorization-card" aria-labelledby="authorization-title"><p class="eyebrow">YOUR PAYMENT SETUP</p>
<h2 id="authorization-title">{{ $enrollment->status === 'authorized' ? 'Payment method authorized' : ucfirst($enrollment->status) }}</h2>
@if($enrollment->status === 'authorized')<p>Your card setup is confirmed. {{ $tenant->name }} will complete the billing-transfer checks before activating your subscription.</p>@endif
@if(in_array($enrollment->status, ['authorized', 'active', 'paused'], true))<form method="POST" action="{{ route('subscriptions.pilot.customer.stop', ['token' => $token]) }}" class="button-row">@csrf
@if($enrollment->status === 'active')<button name="action" value="pause" class="club-button secondary-button">Pause future charges</button>@endif
<button name="action" value="cancel" class="club-button secondary-button">Cancel future charges</button></form>@endif
</section>
@endif
</div>
@if($modernForestry)<aside class="coming-soon" aria-labelledby="coming-soon-title"><div class="coming-soon-heading"><span class="coming-soon-badge"><span aria-hidden="true"></span>COMING SOON</span><h2 id="coming-soon-title">More ways to<br>make it yours.</h2><p>A look at what we’re building for the Modern Forestry app.</p></div>
<div class="feature-grid">@foreach($features as [$number, $title, $body])<article class="feature-card"><span class="feature-number" aria-hidden="true">{{ $number }}</span><h3>{{ $title }}</h3><p>{{ $body }}</p></article>@endforeach</div>
<p class="roadmap-note">Features will roll out as they’re ready. Available options follow your subscription terms. There’s nothing to download or change today.</p></aside>@endif
</div>
@if($cycles->isNotEmpty())<section class="payment-history" aria-labelledby="history-title"><h2 id="history-title">Payments and orders</h2>
@foreach($cycles as $cycle)<div class="paper-card cycle-card"><h3>{{ $cycle->cycle_date->format('F j, Y') }} · {{ $cycle->currency }} {{ number_format($cycle->amount_cents/100, 2) }}</h3><p>Payment: {{ str_replace('_', ' ', $cycle->payment_status) }}. Shopify order: {{ $cycle->handoff_status === 'confirmed' ? 'received for fulfillment' : 'awaiting confirmation' }}.</p>
@if($cycle->payment_status === 'requires_action')<button class="club-button primary-button" data-pilot-authentication data-prepare="{{ route('subscriptions.pilot.customer.authentication', ['token' => $token, 'cycle' => $cycle->id]) }}" data-confirm="{{ route('subscriptions.pilot.customer.confirm', ['token' => $token, 'cycle' => $cycle->id]) }}">Authenticate this payment</button><p role="status" aria-live="polite" data-authentication-status></p>@endif
</div>@endforeach</section>@endif
<footer class="club-footer"><p>{{ $modernForestry ? 'Made with care. Enjoyed at home.' : $tenant->name }}</p>@if($modernForestry)<a href="mailto:info@theforestrystudio.com">Need a hand? Contact Modern Forestry <span aria-hidden="true">↗</span></a>@endif</footer>
</main></body></html>
