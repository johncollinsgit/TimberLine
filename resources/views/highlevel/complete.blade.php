<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }}</title>
<style>body{font:16px system-ui;color:#17342a;background:#f5f7f4;margin:0;padding:40px 24px}main{max-width:560px;margin:10vh auto;padding:32px;background:white;border-radius:20px;border:1px solid #dce5df}p,li{line-height:1.65}a{color:#175c43}button{font:inherit;font-weight:600;background:#216347;color:white;border:0;border-radius:9px;padding:12px 18px;cursor:pointer}button[hidden]{display:none}</style></head>
<body><main><strong>Everbranch Fleet</strong><h1>{{ $title }}</h1><p>{{ $message }}</p>
@if($notify ?? false)
<button id="return-to-fleet" hidden>Return to Fleet</button>
<p id="return-help">Return to the CRM tab and select Everbranch in the client account’s sidebar.</p>
@if(($completionMessage ?? 'EVERBRANCH_BOUNCIE_CONNECTED') === 'EVERBRANCH_BOUNCIE_CONNECTED')
<ol><li>Select your company vehicles and save the selection.</li><li>Review your approved policy and tracking settings.</li><li>Check Fleet for location readings or an activation message.</li></ol>
@endif
@endif
<p>Support: <a href="mailto:{{ config('everbranch.support_email') }}">{{ config('everbranch.support_email') }}</a></p></main>
@if($notify ?? false)
<script>
if(window.opener){
    const button=document.getElementById('return-to-fleet');
    button.hidden=false;
    document.getElementById('return-help').textContent='Your Fleet tab will continue automatically. If this window stays open, use Return to Fleet.';
    button.onclick=()=>{window.opener.focus();window.close();};
    window.opener.postMessage({message:@json($completionMessage ?? 'EVERBRANCH_BOUNCIE_CONNECTED')},window.location.origin);
}
</script>
@endif
</body></html>
