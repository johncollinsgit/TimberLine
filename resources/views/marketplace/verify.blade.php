@extends('marketplace.layout')
@section('title', 'Confirm sign-in')
@section('content')<div class="page-shell narrow"><section class="panel"><span class="eyebrow">WELCOME TO YOUR CORNER.</span><h1>One more click.</h1><p>Confirm to sign in to your Markets and Makers account.</p><form method="post" action="/account/verify/{{ $token }}">@csrf<button class="button">Sign in securely →</button></form></section></div>@endsection
