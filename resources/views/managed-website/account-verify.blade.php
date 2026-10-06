@extends('managed-website.store-layout')
@section('store-content')
@php($sawyer = data_get($site->settings, 'theme_key') === 'sawyer-naturals')
<section class="{{ $sawyer ? 'sn-wrap' : 'store-shell' }}" style="max-width:760px;padding-top:90px;text-align:center"><h1 class="{{ $sawyer ? 'sn-title' : '' }}">Finish signing in.</h1><p class="{{ $sawyer ? 'sn-copy' : 'store-copy' }}">Continue to your private order history.</p><form method="POST" action="{{ route('managed-website.store.account.consume', ['token' => $token]) }}">@csrf<button class="{{ $sawyer ? 'sn-btn' : 'store-button' }}" type="submit">Open my account</button></form></section>
@endsection
