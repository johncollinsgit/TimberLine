{{ $report['tenant']['name'] }} — Wholesale outreach, week of {{ $report['week'] }}

Open each branded email preview in the wholesale app. Review the photos and product links, copy the formatted message, then open Gmail and paste it into the message body. Check the sending account before sending.
@if($report['import_stale'])
WHOLESALE HISTORY NEEDS ATTENTION: {{ $report['reporting_issue'] ?? 'Current complete order history could not be verified.' }}
@endif
@forelse($report['rows'] as $row)

{{ $loop->iteration }}. {{ $row['company'] }} — {{ $row['email'] }}
{{ $row['reason'] }}; {{ $row['days_since_order'] }} days since last order.
Latest: {{ $row['order_number'] }}, {{ $row['order_date'] }}, net payments {{ $row['currency'] }} {{ $row['order_total'] }}
{{ implode('; ', $row['items']) }}
Review email draft: {{ $row['draft_url'] }}
@empty
{{ $report['import_stale'] ? 'Recommendations are paused until history is verified.' : 'No eligible accounts are due for outreach this week.' }}
@endforelse

Prepared {{ $report['generated_at'] }}. Last verified history: {{ $report['import_at'] ?: 'Unavailable' }}.
Qualified wholesale orders only. No customer emails have been sent.
