@php
    $issues = (array) data_get($report, 'health.issues', []);
    $unsubscribes = (array) data_get($report, 'recap.unsubscribed_customers', []);
    $metrics = [
        'Customers added' => number_format((int) data_get($report, 'recap.customers_added', 0)),
        'Customers unsubscribed' => number_format((int) data_get($report, 'recap.unsubscribed_count', 0)),
        'Birthday gifts issued' => number_format((int) data_get($report, 'recap.birthday_issued', 0)),
        'Birthday gifts redeemed' => number_format((int) data_get($report, 'recap.birthday_redeemed', 0)),
        'Candle Cash added' => '$'.number_format((float) data_get($report, 'candle_cash.earned.amount', 0), 2),
        'Candle Cash codes issued' => number_format((int) data_get($report, 'candle_cash.issued.count', 0)).' · $'.number_format((float) data_get($report, 'candle_cash.issued.amount', 0), 2),
        'Candle Cash codes redeemed' => number_format((int) data_get($report, 'candle_cash.redeemed.count', 0)).' · $'.number_format((float) data_get($report, 'candle_cash.redeemed.amount', 0), 2),
        'Emails sent' => number_format((int) data_get($report, 'recap.emails_sent', 0)),
        'Emails failed' => number_format((int) data_get($report, 'recap.emails_failed', 0)),
        'Wishlist items added' => number_format((int) data_get($report, 'wishlist.adds', 0)),
    ];
    $metricKeys = array_flip(\App\Services\Marketing\WeeklyRecapActivityService::METRICS);
@endphp
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Your Everbranch weekly recap</title></head>
<body style="margin:0;padding:0;background:#f4f6f5;color:#21343b;font-family:Arial,Helvetica,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;">Your customers, rewards and email activity for the past week.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
<tr><td style="padding:8px 0 28px;color:#206556;font-size:32px;font-weight:bold;">Everbranch</td></tr>
<tr><td style="background:#ffffff;border:1px solid #dce5e0;border-radius:16px;padding:28px;">
<p style="margin:0 0 16px;font-size:13px;color:#566a64;">{{ data_get($report, 'tenant.name') }} · {{ data_get($report, 'window.label') }} · Eastern time</p>
<h1 style="font-size:26px;line-height:1.3;margin:0 0 12px;">Your week at a glance.</h1>
<p style="font-size:16px;line-height:1.6;">Here is what happened with your customers and rewards last week. Select any number to see its activity in Everbranch.</p>
<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
@foreach($metrics as $label => $value)
<tr><th align="left" style="padding:14px 0;border-top:1px solid #dce5e0;font-size:15px;font-weight:normal;"><a href="{{ data_get($report, 'links.'.$metricKeys[$label]) }}" style="color:#206556;text-decoration:underline;">{{ $label }}</a></th><td align="right" style="padding:14px 0 14px 12px;border-top:1px solid #dce5e0;font-size:17px;font-weight:bold;"><a href="{{ data_get($report, 'links.'.$metricKeys[$label]) }}" style="color:#206556;text-decoration:underline;">{{ $value }} →</a></td></tr>
@endforeach
</table>
<h2 style="font-size:20px;margin:28px 0 12px;">Unsubscribes</h2>
<p style="font-size:14px;line-height:1.6;color:#566a64;">Unsubscribe requests take effect when processed. This recap keeps you informed; customers do not wait for Monday to be removed.</p>
@if($unsubscribes === [])
<p style="font-size:15px;">No email unsubscribe requests were recorded this week.</p>
@else
@foreach($unsubscribes as $customer)
<div style="padding:12px 0;border-top:1px solid #dce5e0;font-size:15px;line-height:1.6;"><strong>{{ $customer['name'] ?: 'Customer' }}</strong><br>{{ $customer['email'] }}<br><span style="font-size:13px;color:#566a64;">{{ $customer['at'] }}</span></div>
@endforeach
@if(data_get($report, 'recap.unsubscribed_list_truncated'))<p>The first 100 addresses are shown. The total above includes all addresses.</p>@endif
@endif
<h2 style="font-size:20px;margin:28px 0 12px;">Storefront health</h2>
<p style="font-size:15px;line-height:1.6;"><a href="{{ data_get($report, 'links.storefront_health') }}" style="color:#206556;text-decoration:underline;"><strong>{{ data_get($report, 'health.label') }} →</strong></a><br>{{ data_get($report, 'health.summary') }}</p>
@foreach($issues as $issue)
<p style="font-size:14px;line-height:1.5;">{{ $issue['event_type'] }} · {{ $issue['issue_type'] }}: {{ $issue['count'] }}</p>
@endforeach
<p style="font-size:13px;color:#566a64;line-height:1.6;">Birthday discount value issued: ${{ number_format((float) data_get($report, 'recap.birthday_discount_issued_amount', 0), 2) }}. Redeemed: ${{ number_format((float) data_get($report, 'recap.birthday_discount_redeemed_amount', 0), 2) }}.<br>Issuing a code and redeeming it at checkout are separate events. Applying a code alone is not counted as a redemption. Sent means accepted by the provider; delivery and failure details appear in Everbranch. Invalid addresses, permanent bounces, spam complaints and unsubscribes stop future marketing emails. Temporary and sender failures preserve eligibility.<br>Customer additions count distinct addresses newly recorded in Everbranch, including imports.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 0;"><tr><td bgcolor="#206556" style="border-radius:8px;"><a href="{{ rtrim(config('app.url'), '/') }}/marketing/customers?tenant={{ data_get($report, 'tenant.id') }}" style="display:inline-block;color:#fff;text-decoration:none;font-size:16px;font-weight:bold;padding:16px 24px;">Open Everbranch →</a></td></tr></table>
</td></tr>
<tr><td style="padding:24px 8px;color:#566a64;font-size:12px;line-height:1.6;">Sent every Monday at 8:00 a.m. Eastern for the previous Monday through Sunday. This internal recap contains customer unsubscribe details; keep it within your team. Figures reflect activity recorded in Everbranch.</td></tr>
</table></td></tr></table></body></html>
