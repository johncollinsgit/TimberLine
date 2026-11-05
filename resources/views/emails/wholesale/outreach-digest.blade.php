<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>@media(max-width:600px){.outreach-action{display:block!important;width:auto!important;text-align:left!important;padding:0 0 20px!important}.outreach-details{display:block!important;width:auto!important}}</style></head>
<body style="margin:0;background:#f4f7f6;color:#183537;font-family:Arial,sans-serif">
<table role="presentation" width="100%"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="100%" style="width:100%;max-width:680px;table-layout:fixed;word-break:break-word;background:white;border-radius:12px"><tr><td style="padding:28px">
<p style="font-size:12px;letter-spacing:2px;color:#477369">EVERBRANCH · WHOLESALE</p>
<h1 style="font-family:Georgia,serif;font-size:30px">Your wholesale follow-ups</h1>
<p>{{ $report['tenant']['name'] }} · Week of {{ $report['week'] }}</p>
<p>Up to ten accounts to consider this week, based on reorder timing and recent wholesale value. Each button opens a branded email preview with the latest order details, product photos and wholesale links. Review, copy into Gmail and send when ready.</p>
@if($report['import_stale'])
<p style="padding:14px;background:#fff2d8;color:#634900"><strong>Wholesale history needs attention.</strong> {{ $report['reporting_issue'] ?? 'Current complete order history could not be verified.' }}</p>
@endif
@forelse($report['rows'] as $row)
<table role="presentation" width="100%" style="width:100%;table-layout:fixed;border-top:1px solid #dbe4e3"><tr><td class="outreach-details" style="padding:22px 12px 22px 0">
<h2 style="font-size:20px;margin:0 0 8px">{{ $loop->iteration }}. {{ $row['company'] }}</h2>
<p style="margin:6px 0">{{ $row['buyer'] }} · {{ $row['email'] }}</p>
<p style="margin:6px 0"><strong>{{ $row['reason'] }}</strong> · {{ $row['days_since_order'] }} days since last order
@if($row['expected_days'])
 · usual interval {{ $row['expected_days'] }} days
@endif
</p>
<p style="margin:6px 0">Latest: {{ $row['order_number'] }} · {{ $row['order_date'] }} · net payments {{ $row['currency'] }} {{ $row['order_total'] }}</p>
@if($row['items'])<p style="font-size:14px;color:#4d6364">{{ implode(' · ', $row['items']) }}</p>@endif
</td><td class="outreach-action" width="150" style="width:150px;padding:22px 0;text-align:right;vertical-align:middle"><a href="{{ $row['draft_url'] }}" style="display:inline-block;padding:12px 18px;background:#123c43;color:white;text-decoration:none;border-radius:7px">Review email draft</a></td></tr></table>
@empty
<p style="padding:20px;background:#eef5f2">{{ $report['import_stale'] ? 'Recommendations are paused until current order history can be verified.' : 'No eligible accounts are due for outreach this week.' }} No customer emails have been sent.</p>
@endforelse
<p style="font-size:12px;color:#617575;border-top:1px solid #dbe4e3;padding-top:18px">Prepared {{ $report['generated_at'] }}. Last verified history: {{ $report['import_at'] ?: 'Unavailable' }}@if($report['source_order_count'] !== null) ({{ number_format($report['source_order_count']) }} source orders)@endif. Uses qualified wholesale orders only; snoozed, handled and recently completed follow-ups are excluded. Single-order accounts use an estimated 120-day reorder window. This digest goes only to your internal inbox.</p>
</td></tr></table></td></tr></table></body></html>
