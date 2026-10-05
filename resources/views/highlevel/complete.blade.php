<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }}</title>
<style>body{font:16px system-ui;color:#17342a;background:#f5f7f4;margin:0;padding:40px 24px}main{max-width:560px;margin:10vh auto;padding:32px;background:white;border-radius:20px;border:1px solid #dce5df}p{line-height:1.65}a{color:#175c43}</style></head>
<body><main><strong>Everbranch Fleet</strong><h1>{{ $title }}</h1><p>{{ $message }}</p><p>Support: <a href="mailto:{{ config('everbranch.support_email') }}">{{ config('everbranch.support_email') }}</a></p></main>
@if($notify ?? false)
<script>if(window.opener){window.opener.postMessage({message:'EVERBRANCH_BOUNCIE_CONNECTED'},window.location.origin);}</script>
@endif
</body></html>
