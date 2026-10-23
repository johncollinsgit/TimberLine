@php($enterpriseInstall = $enterpriseInstall ?? (($enterpriseSample ?? false) ? [] : \App\Enterprise\AppManifest::links(request())))
@if($enterpriseInstall)
<link rel="manifest" href="{{ $enterpriseInstall['manifest'] }}" crossorigin="use-credentials">
<link rel="apple-touch-icon" href="{{ $enterpriseInstall['apple_icon'] }}">
<meta name="theme-color" content="{{ $enterpriseInstall['theme_color'] }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
@endif
