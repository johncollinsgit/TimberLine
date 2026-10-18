<x-layouts::app.sidebar title="Usage & balance">
    <flux:main>
        <div class="mx-auto max-w-5xl space-y-6 pb-10">
            <x-ui.operational-header title="Usage & balance" :description="'Recorded usage for '.$tenant->name.'.'" />
            <p>Optional premium marketing and AI services have usage costs under your accepted agreement. Your subscription, separately purchased credit and actual consumption are shown separately.</p>
            @if($statement['available'])
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach(['current_balance_micros' => 'Current prepaid balance', 'current_reserved_micros' => 'Reserved for pending activity', 'current_available_micros' => 'Available to use'] as $key => $label)
                        <div class="rounded-lg border border-zinc-200 p-4"><p>{{ $label }}</p><strong>${{ number_format($statement[$key] / 1000000, 2) }}</strong></div>
                    @endforeach
                </div>
                <p class="text-sm">Period: {{ $statement['period_start'] }} through {{ $statement['period_end'] }}. Amounts in USD. The first Standard payment covers setup and month 1 only, with no usage fees or automatic preload.</p>
                <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-3">Service</th><th class="p-3">Quantity</th><th class="p-3">Effective rate</th><th class="p-3">Wallet deduction</th><th class="p-3">Rate version</th></tr></thead><tbody>
                    @forelse($statement['wallet_usage'] as $row)
                        <tr class="border-t border-zinc-200"><td class="p-3">{{ strtoupper($row['service']) }}</td><td class="p-3">{{ number_format($row['quantity']) }} {{ $row['unit'] }}</td><td class="p-3">${{ number_format($row['effective_rate_micros'] / 1000000, 5) }}</td><td class="p-3">${{ number_format($row['amount_micros'] / 1000000, 5) }}</td><td class="p-3">{{ $row['pricing_version'] }}</td></tr>
                    @empty
                        <tr><td class="p-3" colspan="5">No settled wallet usage recorded for this period.</td></tr>
                    @endforelse
                </tbody></table></div>
                <p>Credit purchased this period: <strong>${{ number_format($statement['credit_purchases_micros'] / 1000000, 2) }}</strong>. Recorded wallet usage: <strong>${{ number_format($statement['wallet_usage_micros'] / 1000000, 5) }}</strong>. These deductions are not billed again on the monthly service invoice.</p>
                @if($statement['recorded_ai_usage'])
                    <h2 class="text-lg font-semibold">Recorded AI activity</h2>
                    <p class="text-sm">These records retain their existing agreed billing scope. They are not an additional wallet deduction on this page.</p>
                    @foreach($statement['recorded_ai_usage'] as $row)<p>{{ $row['feature'] }} · {{ $row['model'] }} · {{ number_format($row['requests']) }} requests · ${{ number_format($row['amount_micros'] / 1000000, 5) }} recorded</p>@endforeach
                @endif
                <p class="text-sm">{{ $statement['note'] }}</p>
            @else
                <p>Usage records are currently unavailable. No estimated usage is shown as an actual charge.</p>
            @endif
        </div>
    </flux:main>
</x-layouts::app.sidebar>
