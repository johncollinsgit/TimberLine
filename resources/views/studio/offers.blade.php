<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Production &amp; sponsorships · {{ $site->tenant->name }}</title>
<style>body{margin:auto;padding:3rem 1rem;max-width:46rem;background:#faf7f0;color:#25221e;font:1rem/1.55 system-ui}article{background:white;border:1px solid #d9d4ca;border-radius:.7rem;padding:1.4rem;margin:1rem 0}label{display:block;margin:.8rem 0}input:not([type=checkbox]),textarea{box-sizing:border-box;width:100%;padding:.65rem;font:inherit}button{background:#28231f;color:white;padding:.75rem 1rem;border:0;border-radius:.35rem;font:inherit}time,small{color:#57524d}</style></head>
<body><main><p>{{ $site->tenant->name }}</p><h1>Production &amp; sponsorships</h1>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
@forelse($offers as $offer)
<article><small>{{ $offer->kind === 'host_read' ? '60-second host read' : 'Hands-on production' }}</small>
<h2>{{ $offer->title }}</h2><p><strong>{{ '$'.number_format($offer->price_cents / 100, 2) }} USD</strong> · Open through <time>{{ $offer->closes_at->format('M j, Y H:i T') }}</time></p>
<form method="post" action="{{ route('studio.briefs.submit', $offer) }}">@csrf
<input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}">
<label>Sponsor or company<input required name="sponsor" maxlength="150"></label><label>Contact email<input type="email" required name="email" maxlength="254"></label>
<label>What should the audience know?<textarea name="brief" required minlength="10" maxlength="4000" rows="3"></textarea></label>
@if($offer->clip_cents !== null)<label><input type="checkbox" name="social_clip" value="1"> Add one edited social clip · {{ '$'.number_format($offer->clip_cents / 100, 2) }}</label>@endif
<button>Submit brief for review</button></form></article>
@empty<p>Booking dates will appear here when the next production is scheduled.</p>@endforelse
{{ $offers->links() }}</main></body></html>
