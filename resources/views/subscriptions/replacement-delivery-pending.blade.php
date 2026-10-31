@php
    $modernForestry = $tenant->slug === 'modern-forestry';
    $status = $review['native_status'];
    $confirming = in_array($status, ['commit_unknown', 'commit_prepared'], true);
    $preparing = in_array($status, ['calculation_pending', 'calculation_prepared'], true);
    $heading = $confirming ? 'We’re checking your delivery update.' : ($preparing ? 'Your delivery review is on its way.' : 'Let’s take another look.');
@endphp
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow">
@if($review['refreshable'] ?? false)<meta http-equiv="refresh" content="5">@endif
<title>Your delivery review | {{ $tenant->name }}</title>@vite('resources/css/subscriptions-pilot-customer.css')
</head><body class="club-page"><a class="skip-link" href="#delivery-status">Skip to delivery update</a>
<header class="club-header"><div class="club-wrap brand-row">@if($modernForestry)<img class="club-logo" src="{{ asset('brand/modern-forestry-logo-white.png') }}" alt="Modern Forestry" width="180" height="68">@else<span class="merchant-name">{{ $tenant->name }}</span>@endif<span class="header-label">YOUR MEMBERSHIP</span></div></header>
<main class="club-wrap delivery-review-wrap"><section class="club-intro"><p class="eyebrow">A LITTLE CARE FOR YOUR NEXT DELIVERY</p><h1>{{ $heading }}</h1></section>
<section id="delivery-status" class="paper-card terms-card delivery-review-card" aria-labelledby="status-title"><h2 id="status-title">{{ $confirming ? 'Confirming your choice' : 'Your delivery review' }}</h2>
@if($confirming)<p>We’re verifying your saved delivery choice. Check again for confirmation. You can still pause or cancel future billing from your membership page.</p>
@elseif($preparing)<p>We’re preparing the address, scents and future total for you to review. You’ll confirm those details before they are saved.</p>
@elseif($status === 'review_stale')<p>Your subscription or delivery options changed, or this review expired. Close this review and start again to see the current details.</p>
@else<p>We couldn’t finish this delivery update. Please contact our team if you need a hand.</p>@endif
@if($review['refreshable'] ?? false)<p class="fine-print">This page checks again automatically. You can also use the button below.</p>@endif
<div class="button-row"><a class="club-button primary-button" href="{{ route('subscriptions.replacement.customer.delivery.status', ['token' => $token, 'requestId' => $review['request_uuid']]) }}">Check again</a><a class="club-button secondary-button" href="{{ route('subscriptions.replacement.customer', ['token' => $token]) }}">Back to membership</a></div>
@if($review['can_discard'] ?? false)<form method="POST" action="{{ route('subscriptions.replacement.customer.delivery.discard', ['token' => $token, 'requestId' => $review['request_uuid']]) }}" class="delivery-confirmation">@csrf<button class="club-button secondary-button">Close this delivery review</button><p class="fine-print">Your membership keeps its current billing status and saved delivery details.</p></form>@endif
</section><footer class="club-footer"><p>{{ $modernForestry ? 'Made with care. Enjoyed at home.' : $tenant->name }}</p>@if($modernForestry)<a href="mailto:info@theforestrystudio.com">Need a hand? Contact Modern Forestry</a>@endif</footer></main></body></html>
