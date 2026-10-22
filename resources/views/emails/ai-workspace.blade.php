<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;background:#f5f7f6;color:#21352c;font-family:Arial,sans-serif">
<table role="presentation" style="width:100%;border-collapse:collapse"><tr><td style="padding:24px 12px">
<table role="presentation" style="width:100%;max-width:600px;margin:auto;background:#fff;border-collapse:collapse">
<tr><td style="padding:24px;border-top:5px solid {{ preg_match('/^#[a-f0-9]{6}$/i', $email['brand']['color']) ? $email['brand']['color'] : '#174f46' }};font-size:22px;font-weight:bold">{{ $email['brand']['name'] }}</td></tr>
<tr><td style="padding:0 24px 24px;font-size:16px;line-height:1.6">{!! nl2br(e($email['body'])) !!}</td></tr>
<tr><td style="padding:20px 24px;border-top:1px solid #e4e9e6;font-size:14px">{{ $email['sender']['from_name'] }}<br>{{ $email['sender']['reply_to_email'] ?: $email['sender']['from_email'] }}</td></tr>
</table></td></tr></table></body></html>
