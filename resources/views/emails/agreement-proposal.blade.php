@php($support = config('everbranch.support_email', 'john@evergrovesoftware.com'))
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Your Everbranch agreement</title></head>
<body style="margin:0;background:#f4f7f6;color:#0f1c1f;font-family:Arial,Helvetica,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Your agreement is ready. Review the details and next steps through your private link.</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7f6;"><tr><td align="center" style="padding:40px 16px;">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;">
<tr><td style="padding:0 0 28px;"><table role="presentation" cellspacing="0" cellpadding="0"><tr><td><img src="{{ asset('brand/everbranch-mark.png') }}" width="42" height="42" alt="" style="display:block;"></td><td style="padding-left:12px;font-size:25px;font-weight:700;letter-spacing:-1px;color:#123c43;">Everbranch</td></tr></table></td></tr>
<tr><td style="padding:36px 28px;background:#ffffff;border:1px solid #dbe4e3;border-top:4px solid #123c43;border-radius:12px;">
<p style="margin:0 0 16px;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#1e5a63;">Built around your business</p>
<h1 style="margin:0 0 16px;font-size:30px;line-height:1.2;letter-spacing:-1px;">Your next chapter starts here.</h1>
<p style="margin:0 0 24px;font-size:16px;line-height:1.7;color:#526164;">{{ $agreement->tenant->name }}, your Everbranch agreement is ready. Your private link brings together the scope, pricing, and next steps in one place.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="padding:20px;background:#f4f7f6;border-radius:8px;">
<p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#526164;">Prepared for {{ $agreement->tenant->name }}</p>
<p style="margin:0;font-size:17px;line-height:1.5;font-weight:700;">{{ $agreement->title }}</p>
</td></tr></table>
<table role="presentation" cellspacing="0" cellpadding="0" style="margin:28px 0 18px;"><tr><td bgcolor="#123c43" style="border-radius:7px;"><a href="{{ $proposalUrl }}" style="display:inline-block;padding:16px 24px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;">Review your agreement &rarr;</a></td></tr></table>
<p style="margin:0;font-size:13px;line-height:1.7;color:#526164;">No password needed. Review and accept when you’re ready, then continue to secure payment if applicable.</p>
@if($agreement->access_expires_at)<p style="margin:12px 0 0;font-size:12px;line-height:1.7;color:#526164;">Your private link is available through {{ $agreement->access_expires_at->format('F j, Y') }}. Please keep it private; anyone with the link can access your agreement.</p>@endif
<p style="margin:28px 0 0;padding-top:24px;border-top:1px solid #dbe4e3;font-size:14px;line-height:1.7;color:#526164;">Have a question? <a href="mailto:{{ $support }}" style="color:#123c43;text-decoration:underline;">Get in touch</a>. We’re here to help you get started.</p>
</td></tr>
<tr><td style="padding:24px 8px;font-size:12px;line-height:1.7;color:#657577;">Everbranch by Evergrove Software<br>If the button doesn’t work, <a href="{{ $proposalUrl }}" style="color:#123c43;">open your private agreement here</a>.</td></tr>
</table></td></tr></table>
</body></html>
