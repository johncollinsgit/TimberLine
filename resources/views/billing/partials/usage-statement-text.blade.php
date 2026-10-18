@if(is_array($usageStatement ?? null) && ($usageStatement['available'] ?? false))
USAGE STATEMENT — INFORMATION ONLY
Period: {{ $usageStatement['period_start'] }} to {{ $usageStatement['period_end'] }}
@forelse($usageStatement['wallet_usage'] as $row)
{{ strtoupper($row['service']) }} | {{ number_format($row['quantity']) }} {{ $row['unit'] }} | effective unit cost ${{ number_format($row['effective_rate_micros']/1000000, 6) }} | deducted ${{ number_format($row['amount_micros']/1000000, 4) }} | {{ $row['pricing_version'] }}
@empty
No settled wallet usage in this period.
@endforelse
Usage deducted: ${{ number_format($usageStatement['wallet_usage_micros']/1000000, 4) }}
Separate credit purchases: ${{ number_format($usageStatement['credit_purchases_micros']/1000000, 2) }}
Balance at capture: ${{ number_format($usageStatement['current_balance_micros']/1000000, 2) }}; reserved: ${{ number_format($usageStatement['current_reserved_micros']/1000000, 2) }}; available: ${{ number_format($usageStatement['current_available_micros']/1000000, 2) }}.
Prepaid deductions are not billed again in this payment.
@foreach($usageStatement['recorded_ai_usage'] ?? [] as $row)
Separate AI record (informational, follows accepted billing scope): {{ $row['feature'] }} / {{ $row['model'] }} | {{ number_format($row['requests']) }} requests | ${{ number_format($row['amount_micros']/1000000, 4) }}
@endforeach
@endif
