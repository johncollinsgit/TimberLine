@extends('service-proposals.layout')
@section('title', 'Private proposal')
@section('content')
<div class="bounded">
@if($leesMcRae ?? false)@include('service-proposals.branding.lees-mcrae-reveal')@endif
<span class="brand"><span class="brand-mark" aria-hidden="true"></span>Evergrove Software</span><h1>A private proposal.</h1><p>Enter the password provided by your proposal owner to continue. Access lasts up to 30 minutes in this browser.</p><form method="post" action="{{ route('service-proposals.recipient.unlock', $token) }}">@csrf<label for="password">Proposal password</label><input id="password" name="password" type="password" autocomplete="current-password" maxlength="128" required><p><button>Open proposal</button></p></form><small>Ask your proposal owner for access if you do not have the password.</small></div>
@endsection
