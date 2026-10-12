<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribe · Everbranch</title></head>
<body style="margin:0;background:#f4f6f5;color:#21343b;font-family:Arial,Helvetica,sans-serif;padding:40px 16px;">
<main style="max-width:480px;margin:auto;background:white;border:1px solid #dce5e0;border-radius:16px;padding:32px;">
<p style="color:#206556;font-size:24px;font-weight:bold;">Everbranch</p>
@if($done)
<h1>You're unsubscribed.</h1><p style="line-height:1.6;">You will no longer receive marketing emails from this business.</p>
@else
<h1>Stop marketing emails?</h1><p style="line-height:1.6;">Choose Unsubscribe below. No sign-in or email reply is needed.</p>
<form method="post" action="{{ url('/email/unsubscribe') }}?token={{ rawurlencode($token) }}">
<input type="hidden" name="confirm" value="1"><button style="background:#206556;color:white;border:0;border-radius:8px;padding:16px 24px;font-size:17px;cursor:pointer;">Unsubscribe</button>
</form>
@endif
</main></body></html>
