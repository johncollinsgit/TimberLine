<x-layouts::app.sidebar title="Production & sponsorships">
<flux:main><main class="mx-auto max-w-4xl space-y-5">
@include('managed-website.catalog.nav')
<h1 class="text-2xl font-semibold">Production &amp; sponsorships</h1>
<a class="underline" href="{{ $origin }}/studio/sponsorships">View the public booking page</a>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<form class="grid gap-3 rounded border p-5" method="post" action="{{ route('studio.admin.offers') }}">@csrf
<h2 class="font-semibold">Open a booking</h2>
<label>Episode ID from Studio <input required name="episode_id" class="w-full" placeholder="Episode UUID"></label>
<label>Offer and deliverables <input required name="title" maxlength="155" class="w-full"></label>
<label>Type <select name="kind"><option value="host_read">60-second host read</option><option value="production">Production shoot</option></select></label>
<p class="text-sm">Pilot rates in USD are $25 per thousand expected downloads for a host read, $75 for an edited social clip, and $500 for a local half-day shoot before editing. Enter the agreed flat price for this booking.</p>
<label>Booking price in cents <input required type="number" min="50" name="price_cents"></label>
<label>Optional social clip in cents <input type="number" min="50" name="clip_cents" value="7500"></label>
<label>Close bookings ({{ config('app.timezone') }}) <input required type="datetime-local" name="closes_at"></label><button class="fb-btn fb-btn-primary">Publish offer</button></form>
@foreach($offers as $offer)
<section class="space-y-3 rounded border p-5"><h2 class="font-semibold">{{ $offer->title }} · {{ '$'.number_format($offer->price_cents / 100, 2) }}</h2>
<p>{{ $offer->reserved ? 'Reserved' : ($offer->closes_at->isFuture() ? 'Available' : 'Closed') }} · {{ $offer->closes_at->format('M j, Y H:i T') }}</p>
<a class="underline" href="{{ route('studio.admin.rundown', $offer->episode_id) }}">Download paid Studio rundown</a>
@if($offer->closes_at->isFuture() && ! $offer->reserved)<form method="post" action="{{ route('studio.admin.close', $offer) }}">@csrf<button class="fb-btn fb-btn-secondary">Close this booking</button></form>@endif
@foreach($offer->briefs as $brief)
<article class="space-y-2 border-t pt-3"><h3>{{ $brief->sponsor }}</h3><p class="whitespace-pre-line">{{ $brief->brief }}</p>@if($brief->social_clip)<p>Includes edited social clip</p>@endif
@if($brief->order)
@php($checkout = $origin.'/invoices/'.$brief->order->lookup_token)
<a class="underline" href="mailto:{{ $brief->email }}?subject={{ rawurlencode($offer->title) }}&amp;body={{ rawurlencode('Your brief was approved. Review and pay your invoice at '.$checkout) }}">Email checkout link to sponsor</a>
@if($studio->paid($brief))
<p>Payment verified</p><form class="grid gap-2" method="post" action="{{ route('studio.admin.proof', [$offer, $brief]) }}">@csrf
<label>Published episode or production URL <input name="url" type="url" required value="{{ data_get($brief->proof,'url') }}"></label>
<label>Placement in seconds <input name="timestamp_seconds" type="number" min="0" max="86400" required value="{{ data_get($brief->proof,'timestamp_seconds') }}"></label>
@if($brief->social_clip)<label>Published social clip URL <input type="url" name="clip_url" required value="{{ data_get($brief->proof,'clip_url') }}"></label>@endif
<button class="fb-btn fb-btn-secondary">Save proof of delivery</button></form>
@else<p>Invoice status · {{ str($brief->order->payment_status)->headline() }}</p>@endif
@elseif(! $offer->reserved && $offer->closes_at->isFuture())
<form class="grid gap-2" method="post" action="{{ route('studio.admin.approve', [$offer, $brief]) }}">@csrf
<label>Reviewed tax in cents <input type="number" required min="0" name="tax_cents"></label>
<label>Tax basis <input required name="tax_basis" maxlength="255"></label><button class="fb-btn fb-btn-primary">Approve brief and issue invoice</button></form>
@endif</article>@endforeach</section>@endforeach
{{ $offers->links() }}</main></flux:main></x-layouts::app.sidebar>
