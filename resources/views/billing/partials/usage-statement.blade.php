@if(is_array($usageStatement ?? null) && ($usageStatement['available'] ?? false))
<section aria-label="Usage statement" style="margin:24px 0;font-size:14px;line-height:1.6;">
    <h3 style="font-size:18px;">Usage statement · information only</h3>
    <p>{{ \Illuminate\Support\Carbon::parse($usageStatement['period_start'])->format('M j, Y') }}–{{ \Illuminate\Support\Carbon::parse($usageStatement['period_end'])->format('M j, Y') }} UTC. Usage paid from prepaid credit is not charged again in this payment.</p>
    <table style="width:100%;border-collapse:collapse;text-align:left;"><thead><tr><th>Service</th><th>Quantity</th><th>Effective unit cost</th><th>Deducted</th></tr></thead><tbody>
    @forelse($usageStatement['wallet_usage'] as $row)
        <tr><td style="padding:8px 0;">{{ strtoupper($row['service']) }}<br><small>{{ $row['pricing_version'] }}</small></td><td>{{ number_format($row['quantity']) }} {{ $row['unit'] }}</td><td>${{ number_format($row['effective_rate_micros'] / 1000000, 6) }}</td><td>${{ number_format($row['amount_micros'] / 1000000, 4) }}</td></tr>
    @empty<tr><td colspan="4" style="padding:8px 0;">No settled wallet usage in this period.</td></tr>@endforelse
    </tbody></table>
    <p>Usage deducted: <strong>${{ number_format($usageStatement['wallet_usage_micros'] / 1000000, 4) }}</strong>. Separate credit purchases: ${{ number_format($usageStatement['credit_purchases_micros'] / 1000000, 2) }}. Prepaid balance at statement capture: ${{ number_format($usageStatement['current_balance_micros'] / 1000000, 2) }}; reserved: ${{ number_format($usageStatement['current_reserved_micros'] / 1000000, 2) }}; available: ${{ number_format($usageStatement['current_available_micros'] / 1000000, 2) }}.</p>
    @if(count($usageStatement['recorded_ai_usage'] ?? []))
        <p>Separately recorded AI activity follows its accepted billing scope. These figures are informational and do not create a wallet deduction or additional charge here.</p>
        @foreach($usageStatement['recorded_ai_usage'] as $row)<p>{{ $row['feature'] }} · {{ $row['model'] }} · {{ number_format($row['requests']) }} requests · ${{ number_format($row['amount_micros'] / 1000000, 4) }}</p>@endforeach
    @endif
</section>
@endif
