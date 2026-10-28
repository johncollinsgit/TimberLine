@php($pilot = $pilot ?? ['available' => false, 'members' => []])
<section class="space-y-5" aria-label="Subscription pilot reporting" data-subscription-pilot>
    @if(session('pilot_status'))<p role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-950">{{ session('pilot_status') }}</p>@endif
    @if(session('pilot_error'))<p role="alert" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">{{ session('pilot_error') }}</p>@endif
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 pb-4">
        <div>
            <h2 class="text-lg font-semibold text-zinc-950">Subscription pilot</h2>
            <p class="mt-1 text-sm text-zinc-600">Recharge remains primary. Stripe pilot members require individual activation.</p>
        </div>
        @if(isset($pilotTenant))
            <form action="{{ route('subscriptions.pilot.index') }}" method="GET" class="flex items-center gap-2">
                <input type="hidden" name="tenant" value="{{ $pilotTenant->slug }}">
                <label class="sr-only" for="pilot-member">Pilot member</label>
                <select id="pilot-member" name="member" class="rounded-lg border-zinc-300 text-sm">
                    <option value="">All pilot members</option>
                    @foreach($pilot['members'] as $member)<option value="{{ $member['id'] }}" @selected(($pilot['selected'] ?? null) === $member['id'])>{{ $member['name'] }}</option>@endforeach
                </select>
                <button class="rounded-lg bg-emerald-900 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>
        @endif
    </div>
    @if(!($pilot['available'] ?? false))
        <p class="rounded-lg border border-zinc-200 bg-white p-5 text-sm text-zinc-600">Pilot reporting is awaiting its database release. Existing billing continues with Recharge.</p>
    @elseif(empty($pilot['members']))
        <div class="rounded-lg border border-zinc-200 bg-white p-5">
            <h3 class="font-semibold text-zinc-950">No pilot members prepared</h3>
            <p class="mt-2 text-sm text-zinc-600">Prepare reviewed customer identities and subscription terms before tracking a pilot. An empty pilot does not mean there are no Recharge subscribers.</p>
        </div>
    @else
        <div class="grid gap-3 sm:grid-cols-3" aria-label="Pilot overview">
            <div class="border-l-2 border-emerald-800 pl-4"><p class="text-xs text-zinc-500">Prepared members</p><p class="mt-1 text-2xl font-semibold">{{ $pilot['total_prepared'] }}</p></div>
            <div class="border-l-2 border-zinc-300 pl-4"><p class="text-xs text-zinc-500">Stripe merchant account</p><p class="mt-1 text-base font-semibold">{{ $pilot['payment_ready'] ? 'Account ready, member approval required' : 'Setup required' }}</p></div>
            <div class="border-l-2 border-amber-400 pl-4"><p class="text-xs text-zinc-500">Double-charge verification</p><p class="mt-1 text-base font-semibold">{{ collect($pilot['members'])->contains('duplicate_alert', true) ? 'Review required' : 'Incomplete provider coverage' }}</p></div>
        </div>
        <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs text-zinc-600"><tr><th class="px-4 py-3">Member</th><th class="px-4 py-3">Pilot cycle</th><th class="px-4 py-3">Billing owner</th><th class="px-4 py-3">Payment</th><th class="px-4 py-3">Shopify order</th><th class="px-4 py-3">Fulfillment</th><th class="px-4 py-3">Double-charge check</th></tr></thead>
                <tbody class="divide-y divide-zinc-100">
                @foreach($pilot['members'] as $member)
                    @continue(($pilot['selected'] ?? null) && $pilot['selected'] !== $member['id'])
                    <tr>
                        <td class="px-4 py-4"><p class="font-semibold text-zinc-950">{{ $member['name'] }}</p><p class="mt-1 text-xs text-zinc-500">{{ $member['status'] === 'prepared' ? 'Prepared for review' : ucfirst($member['status']) }}</p></td>
                        <td class="whitespace-nowrap px-4 py-4">{{ \Carbon\CarbonImmutable::parse($member['target_date'])->format('M j, Y') }}<p class="mt-1 text-xs text-zinc-500">{{ $member['expected_amount_cents'] !== null ? $member['currency'].' '.number_format($member['expected_amount_cents'] / 100, 2) : 'Amount needs review' }}</p></td>
                        <td class="px-4 py-4">{{ ucfirst($member['billing_owner']) }}</td>
                        <td class="px-4 py-4">{{ $member['payment_status'] }}</td><td class="px-4 py-4">{{ $member['shopify_status'] }}</td><td class="px-4 py-4">{{ $member['fulfillment_status'] }}</td>
                        <td class="px-4 py-4 {{ $member['duplicate_alert'] ? 'font-semibold text-red-800' : 'text-zinc-600' }}">{{ $member['duplicate_status'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="grid gap-5 lg:grid-cols-2">
            <section class="rounded-lg border border-zinc-200 bg-white p-4">
                <h3 class="font-semibold text-zinc-950">Observed subscription payments</h3>
                <p class="mt-1 text-xs text-zinc-500">{{ $pilot['currency'] ?? 'Select one currency to compare amounts.' }} · Source observations, not reconciled revenue. Missing feeds appear as gaps.</p>
                <div class="relative mt-4 h-64"><canvas data-pilot-payments role="img" aria-label="Observed Recharge, Stripe and Shopify Payments by billing month"></canvas></div>
                <details class="mt-3 text-xs text-zinc-600"><summary class="cursor-pointer">View payment chart data</summary>
                    <table class="mt-2 w-full text-left"><thead><tr><th>Month</th>@foreach($pilot['chart']['datasets'] as $dataset)<th>{{ $dataset['label'] }}</th>@endforeach</tr></thead><tbody>
                    @foreach($pilot['chart']['labels'] as $i => $month)<tr><td>{{ $month }}</td>@foreach($pilot['chart']['datasets'] as $dataset)<td>{{ isset($dataset['data'][$i]) ? number_format($dataset['data'][$i], 2) : 'Not verified' }}</td>@endforeach</tr>@endforeach
                    </tbody></table>
                </details>
            </section>
            <section class="rounded-lg border border-zinc-200 bg-white p-4">
                <h3 class="font-semibold text-zinc-950">Target-cycle outcomes</h3>
                <p class="mt-1 text-xs text-zinc-500">Payment, order and shipment evidence are tracked independently.</p>
                <div class="relative mt-4 h-64"><canvas data-pilot-outcomes role="img" aria-label="Pilot members with observed payment, order and fulfillment results"></canvas></div>
                <p class="mt-3 text-xs text-zinc-500">Prepared members have no confirmed Stripe charge until provider evidence is received.</p>
            </section>
        </div>
        <section class="rounded-lg border border-zinc-200 bg-white">
            <h3 class="border-b border-zinc-200 px-4 py-3 font-semibold text-zinc-950">Member preparation</h3>
            <div class="divide-y divide-zinc-100">
            @foreach($pilot['members'] as $member)
                @continue(($pilot['selected'] ?? null) && $pilot['selected'] !== $member['id'])
                <div class="grid gap-3 p-4 md:grid-cols-2">
                    <div><p class="font-medium">{{ $member['name'] }}</p>
                        @foreach($member['lines'] as $line)<p class="mt-1 text-sm text-zinc-600">{{ $line['quantity'] }} × {{ $line['label'] }} · {{ $member['currency'] }} {{ number_format($line['unit_price_cents'] / 100, 2) }} · bills every {{ $line['billing_months'] }} month(s), ships every {{ $line['delivery_months'] }} month(s)</p>@endforeach
                        @if(isset($pilotTenant) && ($member['can_review_execution'] ?? false))<a class="mt-2 inline-block text-sm font-medium text-emerald-900" href="{{ route('subscriptions.pilot.enrollment.review',['member'=>$member['id'],'tenant'=>$pilotTenant->slug]) }}">Review Stripe pilot setup</a><br>@endif
                        <a href="{{ $member['source_url'] }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-sm font-medium text-emerald-900">Review in Recharge ↗</a>
                        @if(isset($pilotTenant))<form method="POST" action="{{ route('subscriptions.pilot.refresh-orders', ['member' => $member['id'], 'tenant' => $pilotTenant->slug]) }}" class="mt-2">@csrf<button class="text-sm font-medium text-emerald-900">Refresh Shopify order evidence</button></form>@endif
                        <p class="mt-1 text-xs text-zinc-500">Merchant-portal observation: {{ \Carbon\CarbonImmutable::parse($member['source_observed_at'])->format('M j, Y g:i a T') }}</p>
                    </div>
                    <div><p class="text-sm font-medium text-zinc-800">{{ empty($member['activation_blockers']) ? 'Activation complete' : 'Subscription readiness' }}</p><ul class="mt-1 list-disc space-y-1 pl-4 text-xs text-zinc-600">@foreach($member['activation_blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
                </div>
            @endforeach
            </div>
        </section>
        <details class="rounded-lg border border-zinc-200 bg-white p-4 text-sm">
            <summary class="cursor-pointer font-semibold text-zinc-950">Connection readiness and evidence</summary>
            <div class="mt-3 space-y-2 text-zinc-600"><p>Recharge read connection: {{ $pilot['recharge_connected'] ? 'Verified connection exists; capture freshness still applies' : 'Not connected' }}</p>
                @foreach($pilot['payment_blockers'] as $blocker)<p>{{ $blocker }}</p>@endforeach
                <p>Candle Cash cannot be redeemed against subscriptions. Shopify orders are not counted as additional payments.</p>
                @if(Route::has('tenant-payments.index'))<a href="{{ route('tenant-payments.index', ['tenant' => $pilotTenant->slug ?? request('tenant')]) }}" class="inline-block font-medium text-emerald-900">Open Accept payments</a>@endif
            </div>
            <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-xs"><thead><tr><th class="py-2">Date</th><th>Provider</th><th>Type</th><th>Reference</th><th>Status</th><th>Amount</th><th>Evidence</th></tr></thead><tbody>
                @foreach($pilot['events'] as $event)<tr class="border-t border-zinc-100"><td class="py-2">{{ $event->occurred_at->format('M j, Y') }}</td><td>{{ ucfirst($event->provider) }}</td><td>{{ ucfirst($event->kind) }}</td><td>{{ $event->provider_reference }}</td><td>{{ ucfirst($event->status) }}</td><td>{{ $event->amount_cents !== null ? $event->currency.' '.number_format($event->amount_cents / 100, 2) : 'Not recorded' }}</td><td>{{ match($event->verification){'merchant_portal'=>'Merchant portal observation','stripe_read'=>'Current Stripe read','stripe_signature'=>'Signed Stripe receipt','customer_request'=>'Customer request','reviewed_activation'=>'Reviewed activation',default=>'Shopify order evidence'} }}</td></tr>@endforeach
            </tbody></table></div>
        </details>
        <script type="application/json" data-pilot-chart-data>{!! json_encode(['payments' => $pilot['chart'], 'members' => array_values(array_filter($pilot['members'], fn ($m) => !($pilot['selected'] ?? null) || $m['id'] === $pilot['selected']))], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
        @vite('resources/js/subscriptions-pilot.js')
    @endif
</section>
