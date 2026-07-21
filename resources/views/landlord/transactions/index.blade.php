<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold text-zinc-950">Workspace transactions</h1>
            <p class="mt-1 text-sm text-zinc-600">Real Stripe activity, completed refunds, and recorded operating commitments.</p>
        </div>
    </x-slot>

    @php
        $money = static fn (int $cents, string $currency = 'USD'): string => strtoupper($currency) === 'USD'
            ? '$'.number_format($cents / 100, 2)
            : strtoupper($currency).' '.number_format($cents / 100, 2);
    @endphp

    <div class="mx-auto max-w-6xl space-y-5">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-950">Transactions</h1>
            <p class="text-sm text-zinc-600">A compact ledger of Stripe attempts and settlements. Only succeeded payments count as received.</p>
        </div>
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">{{ session('status') }}</div>
        @endif
        @if(session('status_error'))
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-900">{{ session('status_error') }}</div>
        @endif
        @if($errors->has('customer_phone') || $errors->has('consent_confirmed'))
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-900">{{ $errors->first('customer_phone') ?: $errors->first('consent_confirmed') }}</div>
        @endif
        @if($errors->has('refund'))
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-900">{{ $errors->first('refund') }}</div>
        @endif
        @if(!$stripeFeed['ok'])
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-900">{{ $stripeFeed['message'] }}</div>
        @elseif($stripeFeed['has_more'])
            <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-xs text-sky-900">Showing the 250 newest Stripe payments. Use Stripe export for older account history.</div>
        @endif

        <section class="grid overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm sm:grid-cols-3 sm:divide-x sm:divide-zinc-200">
            <div class="px-4 py-3"><p class="text-[11px] font-semibold uppercase tracking-[.14em] text-emerald-700">Payments received</p><p class="mt-1 text-xl font-semibold text-zinc-950">{{ $money($summary['incoming_cents']) }}</p><p class="mt-1 text-xs text-zinc-500">Succeeded Stripe payments only</p></div>
            <div class="border-t border-zinc-200 px-4 py-3 sm:border-t-0"><p class="text-[11px] font-semibold uppercase tracking-[.14em] text-zinc-600">Stripe activity</p><p class="mt-1 text-xl font-semibold text-zinc-950">{{ number_format($summary['stripe_activity_count']) }}</p><p class="mt-1 text-xs text-zinc-500">Attempts and completed payments</p></div>
            <div class="border-t border-zinc-200 px-4 py-3 sm:border-t-0"><p class="text-[11px] font-semibold uppercase tracking-[.14em] text-rose-700">Refunds issued</p><p class="mt-1 text-xl font-semibold text-zinc-950">{{ $money($summary['refund_cents']) }}</p><p class="mt-1 text-xs text-zinc-500">Succeeded customer refunds</p></div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-white p-4 shadow-sm">
            <form method="get" class="flex flex-col gap-3 md:flex-row">
                <input name="q" value="{{ $query }}" type="search" autofocus placeholder="Search a workspace, Stripe reference, vendor, or item…" class="min-w-0 flex-1 rounded-2xl border-zinc-300 px-4 py-3 text-sm">
                <select name="direction" class="rounded-2xl border-zinc-300 px-4 py-3 text-sm">
                    <option value="all" @selected($direction === 'all')>All activity</option>
                    <option value="incoming" @selected($direction === 'incoming')>Incoming payments</option>
                    <option value="outgoing" @selected($direction === 'outgoing')>Outgoing refunds & costs</option>
                </select>
                <button class="rounded-2xl bg-zinc-950 px-5 py-3 text-sm font-semibold text-white">Filter ledger</button>
                <button name="refresh_stripe" value="1" class="rounded-2xl border border-zinc-300 bg-white px-5 py-3 text-sm font-semibold text-zinc-800">Refresh Stripe</button>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <div class="border-b border-zinc-200 px-4 py-3"><h2 class="font-semibold text-zinc-950">Transaction ledger</h2><p class="mt-0.5 text-xs text-zinc-500">Stripe attempts stay visible with their real status; only succeeded payments are treated as cash received.</p></div>
            <div class="overflow-x-auto">
                <table class="min-w-[1040px] w-full border-collapse text-left text-sm">
                    <thead class="bg-zinc-50 text-[11px] font-semibold uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="px-3 py-2">Date</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Type</th>
                            <th class="px-3 py-2">Customer / workspace</th>
                            <th class="px-3 py-2">Payment method</th>
                            <th class="px-3 py-2">Itemization</th>
                            <th class="px-3 py-2">Reference</th>
                            <th class="px-3 py-2 text-right">Amount</th>
                            <th class="px-3 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse($transactions as $transaction)
                            @php
                                $isReceived = ($transaction['received'] ?? false) === true;
                                $isOutgoing = $transaction['direction'] === 'outgoing';
                                $statusTone = $isReceived
                                    ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                    : (in_array($transaction['status'], ['failed', 'payment_failed', 'send_failed', 'uncollectible'], true)
                                        ? 'bg-rose-50 text-rose-700 ring-rose-200'
                                        : (in_array($transaction['status'], ['open', 'processing', 'scheduled'], true)
                                            ? 'bg-amber-50 text-amber-700 ring-amber-200'
                                            : 'bg-zinc-100 text-zinc-700 ring-zinc-200'));
                            @endphp
                            <tr class="align-top hover:bg-zinc-50/70">
                                <td class="whitespace-nowrap px-3 py-2.5 text-xs text-zinc-600"><time>{{ $transaction['occurred_at']?->timezone(config('app.timezone'))->format('M j, Y') ?? 'Unavailable' }}<span class="block text-zinc-400">{{ $transaction['occurred_at']?->timezone(config('app.timezone'))->format('g:i A') }}</span></time></td>
                                <td class="px-3 py-2.5"><span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $statusTone }}">{{ $transaction['status_label'] }}</span></td>
                                <td class="whitespace-nowrap px-3 py-2.5 font-medium text-zinc-800">{{ $transaction['title'] }}</td>
                                <td class="px-3 py-2.5 text-zinc-700">{{ $transaction['counterparty'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-xs text-zinc-600">{{ $transaction['payment_method'] }}</td>
                                <td class="max-w-xs px-3 py-2.5 text-xs text-zinc-600">
                                    <details>
                                        <summary class="cursor-pointer font-medium text-zinc-700">{{ count($transaction['items']) }} {{ \Illuminate\Support\Str::plural('item', count($transaction['items'])) }}</summary>
                                        <div class="mt-2 space-y-1">@foreach($transaction['items'] as $item)<div class="flex min-w-56 justify-between gap-3"><span>{{ $item['label'] }}</span><span class="whitespace-nowrap font-medium text-zinc-800">{{ $item['amount_cents'] !== 0 ? $money($item['amount_cents'], $transaction['currency']) : 'Included' }}</span></div>@endforeach</div>
                                    </details>
                                    @if(!empty($transaction['note']))<p class="mt-1 text-amber-800">{{ $transaction['note'] }}</p>@endif
                                </td>
                                <td class="max-w-48 break-all px-3 py-2.5 font-mono text-xs text-zinc-500">{{ $transaction['reference'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold {{ $isReceived ? 'text-emerald-700' : ($isOutgoing ? 'text-rose-700' : 'text-zinc-900') }}">{{ $isReceived ? '+' : ($isOutgoing ? '−' : '') }}{{ $money($transaction['amount_cents'], $transaction['currency']) }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right text-xs">
                                    @if(!empty($transaction['receipt_url']))<a href="{{ $transaction['receipt_url'] }}" target="_blank" rel="noopener" class="font-semibold text-emerald-800 hover:underline">Open in Stripe ↗</a>@endif
                                    @if(data_get($transaction, 'reminder.eligible'))
                                        <details class="relative mt-1 inline-block text-left"><summary class="cursor-pointer font-semibold text-blue-700">Text reminder</summary><form method="post" action="{{ route('landlord.invoices.reminders.sms', [$transaction['reminder']['tenant_id'], $transaction['reminder']['invoice_id']]) }}" class="absolute right-0 z-20 mt-2 w-80 space-y-2 rounded-xl border border-zinc-200 bg-white p-3 text-left shadow-xl">@csrf<input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}"><label class="block text-xs font-medium text-zinc-700">Customer phone<input required name="customer_phone" type="tel" autocomplete="tel" maxlength="40" placeholder="(864) 555-0100" value="{{ $transaction['reminder']['phone'] }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm"></label><label class="flex items-start gap-2 whitespace-normal text-xs text-zinc-700"><input required type="checkbox" name="consent_confirmed" value="1" class="mt-0.5 rounded border-zinc-300"><span>I confirm this customer expressly agreed to receive billing texts at this number.</span></label><p class="whitespace-normal text-[11px] text-zinc-500">Stripe is checked again before the secure payment link is sent. The text identifies Everbranch and includes STOP instructions.</p><button class="w-full rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white">Send text reminder</button></form></details>
                                    @endif
                                    @if(data_get($transaction, 'refund.eligible'))
                                        <details class="relative mt-1 inline-block text-left"><summary class="cursor-pointer font-semibold text-rose-700">Refund</summary><form method="post" action="{{ route('landlord.transactions.refund', $transaction['refund']['receipt_id']) }}" class="absolute right-0 z-20 mt-2 w-72 space-y-2 rounded-xl border border-zinc-200 bg-white p-3 text-left shadow-xl">@csrf<input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}"><label class="block text-xs font-medium text-zinc-700">Amount remaining: {{ $money($transaction['refund']['remaining_cents'], $transaction['currency']) }}<input required name="amount" type="number" min="0.01" max="{{ number_format($transaction['refund']['remaining_cents'] / 100, 2, '.', '') }}" step="0.01" value="{{ number_format($transaction['refund']['remaining_cents'] / 100, 2, '.', '') }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm"></label><select name="reason" class="block w-full rounded-lg border-zinc-300 text-sm"><option value="requested_by_customer">Requested by customer</option><option value="duplicate">Duplicate charge</option><option value="fraudulent">Fraudulent</option></select><textarea name="note" rows="2" maxlength="1000" placeholder="Internal refund note (optional)" class="block w-full rounded-lg border-zinc-300 text-sm"></textarea><button class="w-full rounded-lg bg-rose-700 px-3 py-2 text-sm font-semibold text-white">Issue Stripe refund</button></form></details>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="p-10 text-center text-sm text-zinc-600">No ledger activity matches this view yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
