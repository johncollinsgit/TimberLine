<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold text-zinc-950">Workspace transactions</h1>
            <p class="mt-1 text-sm text-zinc-600">A clear Everbranch ledger: money received, customer refunds, and recorded operating commitments.</p>
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
            <p class="text-sm text-zinc-600">Every confirmed workspace payment, refund, and recorded Everbranch operating commitment in one place.</p>
        </div>
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">{{ session('status') }}</div>
        @endif
        @if($errors->has('refund'))
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-900">{{ $errors->first('refund') }}</div>
        @endif

        <section class="grid gap-4 md:grid-cols-3">
            <div class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-[.16em] text-emerald-800">Payments received</p><p class="mt-2 text-3xl font-semibold text-emerald-950">{{ $money($summary['incoming_cents']) }}</p><p class="mt-2 text-xs text-emerald-800">Stripe-confirmed workspace payments</p></div>
            <div class="rounded-3xl border border-rose-200 bg-rose-50 p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-[.16em] text-rose-800">Refunds issued</p><p class="mt-2 text-3xl font-semibold text-rose-950">{{ $money($summary['refund_cents']) }}</p><p class="mt-2 text-xs text-rose-800">Actual customer money returned</p></div>
            <div class="rounded-3xl border border-sky-200 bg-sky-50 p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-[.16em] text-sky-800">Weekly commitments</p><p class="mt-2 text-3xl font-semibold text-sky-950">{{ $money($summary['weekly_commitments_cents']) }}</p><p class="mt-2 text-xs text-sky-800">Forecast from the cost ledger, not confirmed cash out</p></div>
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
            </form>
        </section>

        <section class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm">
            <div class="border-b border-zinc-100 px-5 py-4"><h2 class="font-semibold text-zinc-950">Itemized activity</h2><p class="mt-1 text-sm text-zinc-600">Operating commitments remain visibly marked as scheduled until a receipt or bank-feed connection confirms the cash movement.</p></div>
            <div class="divide-y divide-zinc-100">
                @forelse($transactions as $transaction)
                    <article class="p-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2"><span class="rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide {{ $transaction['direction'] === 'incoming' ? 'bg-emerald-100 text-emerald-800' : ($transaction['kind'] === 'commitment' ? 'bg-sky-100 text-sky-800' : 'bg-rose-100 text-rose-800') }}">{{ $transaction['direction'] === 'incoming' ? 'Incoming' : ($transaction['kind'] === 'commitment' ? 'Scheduled outgoing' : 'Outgoing') }}</span><span class="text-xs font-medium text-zinc-500">{{ ucfirst(str_replace('_', ' ', $transaction['status'])) }}</span></div>
                                <div class="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1"><h3 class="font-semibold text-zinc-950">{{ $transaction['title'] }}</h3><span class="text-sm text-zinc-600">{{ $transaction['counterparty'] }}</span></div>
                                <p class="mt-1 break-all font-mono text-xs text-zinc-500">{{ $transaction['reference'] }}</p>
                                <div class="mt-4 grid gap-2 sm:grid-cols-2">@foreach($transaction['items'] as $item)<div class="flex items-center justify-between rounded-xl bg-zinc-50 px-3 py-2 text-sm"><span class="pr-3 text-zinc-700">{{ $item['label'] }}</span><span class="font-medium text-zinc-950">{{ $item['amount_cents'] !== 0 ? $money($item['amount_cents'], $transaction['currency']) : 'Included' }}</span></div>@endforeach</div>
                                @if(!empty($transaction['note']))<p class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-950">{{ $transaction['note'] }}</p>@endif
                            </div>
                            <div class="flex shrink-0 flex-col items-start gap-3 lg:items-end"><p class="text-2xl font-semibold {{ $transaction['direction'] === 'incoming' ? 'text-emerald-700' : 'text-zinc-950' }}">{{ $transaction['direction'] === 'incoming' ? '+' : '−' }}{{ $money($transaction['amount_cents'], $transaction['currency']) }}</p><time class="text-xs text-zinc-500">{{ $transaction['occurred_at']?->timezone(config('app.timezone'))->format('M j, Y g:i A') ?? 'Date unavailable' }}</time>@if(!empty($transaction['receipt_url']))<a href="{{ $transaction['receipt_url'] }}" target="_blank" rel="noopener" class="text-sm font-semibold text-emerald-800 hover:underline">View Stripe receipt ↗</a>@endif
                                @if(data_get($transaction, 'refund.eligible'))
                                    <details class="w-full rounded-2xl border border-zinc-200 bg-zinc-50 p-3 text-left lg:w-72"><summary class="cursor-pointer text-sm font-semibold text-zinc-900">Refund payment</summary><form method="post" action="{{ route('landlord.transactions.refund', $transaction['refund']['receipt_id']) }}" class="mt-3 space-y-2">@csrf<input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}"><label class="block text-xs font-medium text-zinc-700">Amount remaining: {{ $money($transaction['refund']['remaining_cents'], $transaction['currency']) }}<input required name="amount" type="number" min="0.01" max="{{ number_format($transaction['refund']['remaining_cents'] / 100, 2, '.', '') }}" step="0.01" value="{{ number_format($transaction['refund']['remaining_cents'] / 100, 2, '.', '') }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm"></label><select name="reason" class="block w-full rounded-lg border-zinc-300 text-sm"><option value="requested_by_customer">Requested by customer</option><option value="duplicate">Duplicate charge</option><option value="fraudulent">Fraudulent</option></select><textarea name="note" rows="2" maxlength="1000" placeholder="Internal refund note (optional)" class="block w-full rounded-lg border-zinc-300 text-sm"></textarea><button class="w-full rounded-lg bg-rose-700 px-3 py-2 text-sm font-semibold text-white">Issue Stripe refund</button></form></details>
                                @endif
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="p-10 text-center text-sm text-zinc-600">No ledger activity matches this view yet.</div>
                @endforelse
            </div>
        </section>
    </div>
</x-app-layout>
