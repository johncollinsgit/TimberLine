WREN FAMILY SOCCER · {{ $summary['season'] }}
{{ $summary['headline'] }}

{{ $summary['parent'] }} · {{ $summary['email'] }}
@foreach($summary['children'] as $child)
{{ $child['name'] }} · {{ $child['team'] }} · {{ $child['status'] }}
@endforeach

SEASON TALLY · {{ $summary['updated_at'] }}
Families: {{ $summary['totals']['families'] }}
Kids with spots: {{ $summary['totals']['children'] }}
Paid: {{ $summary['totals']['paid'] }} · Not paid: {{ $summary['totals']['unpaid'] }} · Fee waived: {{ $summary['totals']['waived'] }} · Waitlisted: {{ $summary['totals']['waiting'] }}
Families with paid registrations: {{ $summary['totals']['families_paid'] }}
Families with payment due: {{ $summary['totals']['families_unpaid'] }}

@foreach($summary['groups'] as $group)
{{ $group['name'] }} · {{ $group['placed'] }} spots · {{ $group['paid'] }} paid · {{ $group['unpaid'] }} unpaid · {{ $group['waived'] }} waived · {{ $group['waiting'] }} waiting
@forelse($group['children'] as $child)
  {{ $child['name'] }} ({{ $child['status'] }})
@empty
  No children yet.
@endforelse

@endforeach
Total payments received: ${{ number_format($summary['totals']['received_cents'] / 100, 2) }}
Refunded: ${{ number_format($summary['totals']['refunded_cents'] / 100, 2) }}
Net collected: ${{ number_format($summary['totals']['net_cents'] / 100, 2) }}

Paid counts use confirmed payments. Waived fees and waitlisted children are separate. A family with both paid and unpaid children appears in both family payment counts. Cancelled and historical registrations are excluded from the roster.

Open teams & payments: {{ $summary['workspace_url'] }}
