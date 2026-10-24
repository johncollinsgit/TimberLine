<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>@yield('title', 'Proposals') · Evergrove Software</title>@include('service-proposals.styles')@yield('presentation-styles')</head>
<body class="@yield('body-class')"><main>@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice" role="alert"><strong>Please review:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
<footer>Prepared with care by Evergrove Software.</footer></main></body></html>
