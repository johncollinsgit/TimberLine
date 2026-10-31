@php
    $modernForestry = $tenant->slug === 'modern-forestry';
    $terms = $agreement->terms;
    $interval = $terms['billing_months'] === 1 ? 'month' : $terms['billing_months'].' months';
@endphp
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow">
<title>{{ $modernForestry ? 'Your Candle Club' : 'Your subscription' }} | {{ $tenant->name }}</title>
@vite('resources/css/subscriptions-pilot-customer.css')
</head><body class="club-page">
<a class="skip-link" href="#subscription">Skip to your subscription</a>
<header class="club-header"><div class="club-wrap brand-row">
@if($modernForestry)<img class="club-logo" src="{{ asset('brand/modern-forestry-logo-white.png') }}" alt="Modern Forestry" width="180" height="68">@else<span class="merchant-name">{{ $tenant->name }}</span>@endif
<span class="header-label">{{ $modernForestry ? 'CANDLE CLUB' : 'MEMBER SUBSCRIPTIONS' }}</span>
</div></header>
<main class="club-wrap">
<section class="club-intro"><p class="eyebrow">YOUR MONTHLY RITUAL</p><h1>{{ $modernForestry ? 'Make room for what you love.' : 'Your subscription, in your hands.' }}</h1>
<p class="intro-copy">Review your next delivery and manage future billing.</p></section>
@if(session('subscription_status'))<p role="status" class="notice">{{ session('subscription_status') }}</p>@endif
@if($errors->any())<p role="alert" class="notice notice-error">{{ $errors->first() }}</p>@endif
<div class="club-layout single-column {{ ($deliveryOptions ?? null) ? 'delivery-layout' : '' }}"><div id="subscription" class="subscription-column">
<section class="paper-card terms-card" aria-labelledby="subscription-title">
<div class="card-heading"><p class="eyebrow">JUST FOR YOU</p><span class="member-badge">{{ ucfirst($agreement->status) }}</span></div>
<h2 id="subscription-title">{{ $modernForestry ? 'Your Candle Club' : 'Your subscription' }}</h2>
@if($memberName)<p class="member-greeting">Hello, {{ $memberName }}.</p>@endif
<ul class="subscription-lines">@foreach($terms['lines'] as $line)<li>{{ $line['quantity'] }} × {{ $line['label'] }}</li>@endforeach</ul>
<p class="subscription-price"><span>{{ $terms['currency'] }} {{ number_format($terms['amount_cents']/100, 2) }}</span> / {{ $interval }}</p>
<p class="fine-print">Includes {{ $terms['currency'] }} {{ number_format($terms['shipping_cents']/100, 2) }} shipping and {{ $terms['currency'] }} {{ number_format($terms['tax_cents']/100, 2) }} tax.</p>
<dl class="terms-list">
<div><dt>Next scheduled billing</dt><dd>{{ $agreement->next_billing_date?->format('F j, Y') ?? 'No future billing scheduled' }}@if($agreement->status === 'paused')<br>{{ $agreement->resume_on ? 'Scheduled to resume on this date' : 'Paused until you resume' }}@endif</dd></div>
<div><dt>Deliver to</dt><dd>{{ $terms['shipping_address']['firstName'] }} {{ $terms['shipping_address']['lastName'] }}<br>{{ $terms['shipping_address']['address1'] }}@if(!empty($terms['shipping_address']['address2'])), {{ $terms['shipping_address']['address2'] }}@endif<br>{{ $terms['shipping_address']['city'] }}, {{ $terms['shipping_address']['provinceCode'] }} {{ $terms['shipping_address']['zip'] }}</dd></div>
<div><dt>Payment method</dt><dd>Your existing saved payment method</dd></div>
</dl></section>
@if($deliveryOptions ?? null)
@if($deliveryOptions['pending_review_uuid'] ?? null)
<section class="paper-card"><p class="eyebrow">YOUR DELIVERY REVIEW</p><h2>Pick up where you left off</h2><p>Your delivery review is ready to check.</p><a class="club-button primary-button" href="{{ route('subscriptions.replacement.customer.delivery.status', ['token' => $token, 'requestId' => $deliveryOptions['pending_review_uuid']]) }}">Open delivery review</a></section>
@else
@include('subscriptions.replacement-delivery-form')
@endif
@endif
@if(in_array($agreement->status, ['active','paused'], true))
<section class="paper-card authorization-card" aria-labelledby="manage-title"><p class="eyebrow">A LITTLE FLEXIBILITY</p><h2 id="manage-title">Manage future billing</h2>
<p>These changes apply to future cycles. A payment already processing or a placed order may need our team’s help.</p>
@if($agreement->status === 'active')
@if($actions['skip'])<form method="POST" action="{{ route('subscriptions.replacement.customer.change', ['token'=>$token]) }}" class="button-row">@csrf
<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="revision" value="{{ $agreement->revision }}">
<button class="club-button secondary-button" name="action" value="skip">Skip the next cycle</button></form>@endif
<form method="POST" action="{{ route('subscriptions.replacement.customer.change', ['token'=>$token]) }}" class="schedule-form">@csrf
<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="revision" value="{{ $agreement->revision }}"><input type="hidden" name="action" value="pause">
<p><label for="pause-duration">How long would you like to pause?</label></p>
<select id="pause-duration" name="duration_months"><option value="">Until I choose to resume</option><option value="{{ $terms['billing_months'] }}">One billing cycle</option><option value="{{ 2*$terms['billing_months'] }}">Two billing cycles</option></select>
<div class="button-row"><button class="club-button secondary-button">Pause future billing</button></div></form>
@elseif($actions['resume'])<form method="POST" action="{{ route('subscriptions.replacement.customer.change', ['token'=>$token]) }}" class="button-row">@csrf
<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="revision" value="{{ $agreement->revision }}">
<button class="club-button primary-button" name="action" value="resume">Resume on the next eligible billing date</button></form>@endif
<details class="terms-details"><summary>Cancel your subscription</summary><p>Cancel all future subscription billing. Orders and payments already started are retained for our team to review.</p>
<form method="POST" action="{{ route('subscriptions.replacement.customer.change', ['token'=>$token]) }}" class="button-row">@csrf
<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="revision" value="{{ $agreement->revision }}">
<button class="club-button secondary-button" name="action" value="cancel">Confirm cancellation of future billing</button></form></details>
</section>@endif
</div></div>
@if($cycles->isNotEmpty())<section class="payment-history" aria-labelledby="history-title"><h2 id="history-title">Payments and orders</h2>
@foreach($cycles as $cycle)<div class="paper-card cycle-card"><h3>{{ $cycle->cycle_date->format('F j, Y') }} · {{ $cycle->currency }} {{ number_format($cycle->amount_cents/100,2) }}</h3>
<p>{{ ($cycle->method_binding['environment'] ?? null) === 'sandbox' ? 'Test payment' : 'Payment' }}: {{ match($cycle->payment_status) { 'succeeded'=>'confirmed', 'sending','unknown'=>'being verified', 'review'=>'our team is reviewing this payment', default=>str_replace('_',' ',$cycle->payment_status) } }}.</p>
<p>Order: {{ $cycle->handoff_status === 'confirmed' ? 'received for fulfillment' : 'awaiting confirmation' }}.</p></div>@endforeach</section>@endif
<footer class="club-footer"><p>{{ $modernForestry ? 'Made with care. Enjoyed at home.' : $tenant->name }}</p>@if($modernForestry)<a href="mailto:info@theforestrystudio.com">Need a hand? Contact Modern Forestry</a>@endif<p class="member-link-note">This private link expires after 15 minutes. Reopen it from your signed-in account.</p></footer>
</main></body></html>
