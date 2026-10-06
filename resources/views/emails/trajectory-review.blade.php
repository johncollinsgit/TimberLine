<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Your Trajectory review</title></head>
<body style="margin:0;padding:0;background:#f4f6f5;color:#21343b;font-family:Arial,Helvetica,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;">Your review queue is ready. A clearer picture starts here.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
<tr><td style="padding:8px 0 28px;color:#206556;font-size:32px;font-weight:bold;">↗ Trajectory</td></tr>
<tr><td style="background:#ffffff;border:1px solid #dce5e0;border-radius:16px;padding:28px;">
<p style="margin:0 0 24px;font-size:18px;">Hi {{ $recipientName }},</p>
<h1 style="font-size:26px;line-height:1.3;margin:0 0 12px;">A little clarity for your next chapter.</h1>
<p style="font-size:17px;line-height:1.6;">You have <strong>{{ number_format($reviewCount) }} {{ $reviewCount === 1 ? 'transaction' : 'transactions' }}</strong> to review in {{ $spaceName }}. {{ $reviewCount > count($rows) ? 'Here are the four most recent.' : 'Here’s what’s waiting.' }}</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0">
@foreach($rows as $row)
<tr><td style="padding:20px 0;border-top:1px solid #dce5e0;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>
<td style="font-size:18px;font-weight:bold;overflow-wrap:anywhere;">{{ $row['merchant'] }}</td>
<td align="right" style="font-size:18px;white-space:nowrap;padding-left:12px;">{{ $row['amount'] }}</td>
</tr></table>
<div style="font-size:15px;line-height:1.6;color:#566a64;margin-top:8px;">{{ \Illuminate\Support\Str::headline($row['category']) }} · {{ \Illuminate\Support\Str::headline($row['flow']) }}<br>{{ \Carbon\CarbonImmutable::parse($row['date'])->format('F j, Y') }}</div>
</td></tr>
@endforeach
</table>
<p style="font-size:13px;color:#566a64;line-height:1.5;">USD · Positive amounts are money in; negative amounts are money out. Categories are awaiting your review.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td bgcolor="#206556" style="border-radius:8px;"><a href="{{ $reviewUrl }}" style="display:inline-block;color:#ffffff;text-decoration:none;font-size:17px;font-weight:bold;padding:16px 24px;">Review transactions →</a></td></tr></table>
<p style="font-size:14px;line-height:1.6;color:#566a64;">Make room for what matters. Review suggestions, confirm categories, and see where your money is taking you.</p>
</td></tr>
<tr><td style="padding:24px 8px;color:#566a64;font-size:13px;line-height:1.6;">You enabled review reminders for this finance space. <a href="{{ $settingsUrl }}" style="color:#206556;">Change frequency or turn off emails</a> in Accounts → Email reminders. Sign in to manage your private financial information.</td></tr>
</table></td></tr></table>
</body></html>
