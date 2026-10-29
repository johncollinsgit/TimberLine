WREN FAMILY SOCCER · {{ $summary['season'] }}
{{ $summary['headline'] }}
@if($summary['correction'])
Correction to the original family application notice. Statuses and totals below reflect current records. A submitted application or temporary reservation is not a confirmed paid registration.
@endif

{{ $summary['parent'] }} · {{ $summary['email'] }}
@foreach($summary['children'] as $child)
{{ $child['name'] }} · {{ $child['team'] }} · {{ $child['status'] }}
@endforeach

SEASON TALLY · {{ $summary['updated_at'] }}
Families: {{ $summary['totals']['families'] }}
Confirmed places: {{ $summary['totals']['confirmed'] }}
Paid: {{ $summary['totals']['paid'] }} · Fee waived: {{ $summary['totals']['waived'] }}
Temporary reservations (payment unconfirmed): {{ $summary['totals']['reserved'] }}
Saved applications without reservations: {{ $summary['totals']['pending'] }}
Waitlisted: {{ $summary['totals']['waiting'] }}
Occupied places (confirmed + temporary): {{ $summary['totals']['children'] }}
Unpaid applications (temporary + saved): {{ $summary['totals']['unpaid'] }}
Families with paid registrations: {{ $summary['totals']['families_paid'] }}
Families with payment due: {{ $summary['totals']['families_unpaid'] }}

@foreach($summary['groups'] as $group)
{{ $group['name'] }} · {{ $group['confirmed'] }} confirmed · {{ $group['reserved'] }} temporary · {{ $group['pending'] }} saved without reservations · {{ $group['waiting'] }} waitlisted
{{ $group['paid'] }} paid · {{ $group['waived'] }} waived · {{ $group['placed'] }} occupied places (confirmed + temporary)
@forelse($group['children'] as $child)
  {{ $child['name'] }} ({{ $child['status'] }})
@empty
  No children yet.
@endforelse

@endforeach
Total payments received: ${{ number_format($summary['totals']['received_cents'] / 100, 2) }}
Refunded: ${{ number_format($summary['totals']['refunded_cents'] / 100, 2) }}
Net collected: ${{ number_format($summary['totals']['net_cents'] / 100, 2) }}

Confirmed places are registered children; paid counts require recorded confirmation and waived fees do not count as money received. Temporary unpaid reservations hold capacity until safely released; they are not confirmed registrations. Saved applications without reservations and waitlisted children do not hold capacity. A family with both paid and unpaid children appears in both family payment counts. Cancelled and historical registrations are excluded from the roster.

Open teams & payments: {{ $summary['workspace_url'] }}
