@php
    $money = fn ($value) => $value === null ? 'Unavailable' : '$'.number_format((float) $value, 2);
    $percent = fn ($value) => $value === null ? 'Mapping needed' : number_format((float) $value, 1).'%';
    $ledger = $commandCenter['ledger'];
    $profile = $commandCenter['profile'];
    $close = $commandCenter['monthly_close'];
    $chartMaximum = max(1, abs((float) ($ledger['gross_income'] ?? 0)), abs((float) ($ledger['expenses'] ?? 0)), abs((float) ($ledger['net_operating_result'] ?? 0)));
    $chartWidth = fn ($value) => min(100, max(2, (abs((float) $value) / $chartMaximum) * 100));
    $streamLabels = ['wholesale' => 'Wholesale', 'online' => 'Online', 'events' => 'Events'];
@endphp

<x-layouts::app.sidebar :title="'Accounting · '.$tenant->name">
    <main class="mx-auto w-full max-w-[1600px] space-y-6 px-4 py-6 sm:px-6" aria-labelledby="accounting-title">
        <header class="border-b border-zinc-200 pb-5">
            <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-emerald-800">Owner workspace</div>
                    <h1 id="accounting-title" class="mt-2 text-3xl font-semibold text-zinc-950">Accounting Command Center</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600">
                        Understand the month, finish the close, and prepare for accountant work. QuickBooks remains the ledger; Everbranch does not post entries, file returns, or make payments.
                    </p>
                </div>
                <form method="GET" class="grid gap-2 sm:grid-cols-2 sm:items-end xl:grid-cols-[minmax(12rem,1fr)_9rem_9rem_auto]" aria-label="Accounting date range">
                    <label class="text-xs font-semibold text-zinc-700" for="accounting-range">
                        Reporting period
                        <select id="accounting-range" name="range" class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm" onchange="this.form.submit()">
                            @foreach($commandCenter['range']['options'] as $key => $label)
                                <option value="{{ $key }}" @selected($commandCenter['range']['key'] === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-zinc-700" for="accounting-start">
                        Custom start
                        <input id="accounting-start" type="date" name="start" value="{{ request('start', $commandCenter['range']['starts_at']) }}" class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm">
                    </label>
                    <label class="text-xs font-semibold text-zinc-700" for="accounting-end">
                        Custom end
                        <input id="accounting-end" type="date" name="end" value="{{ request('end', $commandCenter['range']['ends_at']) }}" class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm">
                    </label>
                    <button class="fb-btn-soft px-4 py-2 text-sm font-semibold" type="submit">Apply</button>
                </form>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-zinc-600">
                <span>{{ \Illuminate\Support\Carbon::parse($commandCenter['range']['starts_at'])->format('M j, Y') }}–{{ \Illuminate\Support\Carbon::parse($commandCenter['range']['ends_at'])->format('M j, Y') }}</span>
                <span>{{ ucfirst($ledger['accounting_basis']) }} basis</span>
                <span>Source: {{ $ledger['source'] }}</span>
                <span>{{ $ledger['observed_at'] ? 'Updated '.$ledger['observed_at']->diffForHumans() : 'No matching QuickBooks snapshot' }}</span>
            </div>
        </header>

        @if(session('status'))
            <div class="fb-state fb-state-success" role="status">{{ session('status') }}</div>
        @endif

        @if(!$profile['configured'])
            <section class="border border-amber-200 bg-amber-50 p-5" aria-labelledby="setup-heading">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 id="setup-heading" class="font-semibold text-amber-950">Start with a reviewed setup draft</h2>
                        <p class="mt-1 max-w-3xl text-sm leading-6 text-amber-900">A preset saves setup time, but every account mapping, filing frequency, due date, and provider responsibility still needs owner or accountant review.</p>
                    </div>
                    <form method="POST" action="{{ route('accounting.setup.preset', ['tenant' => $tenant->slug]) }}">
                        @csrf
                        <input type="hidden" name="preset" value="modern-forestry">
                        <button class="fb-btn-accent whitespace-nowrap px-4 py-2 text-sm font-semibold">Use the Modern Forestry draft</button>
                    </form>
                </div>
            </section>
        @elseif($profile['setup_status'] !== 'configured')
            <div class="border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status">
                Setup is in review. Values with missing or unapproved mappings remain unavailable instead of showing zero.
            </div>
        @endif

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Accounting status">
            @foreach($commandCenter['sources'] as $source)
                <a href="{{ $source['key'] === 'quickbooks' ? route('integrations.quickbooks.index') : '#source-coverage' }}" class="block border border-zinc-200 bg-white p-4 transition hover:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-700">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="font-semibold text-zinc-950">{{ $source['name'] }}</h2>
                        <span class="text-xs font-semibold {{ in_array($source['status'], ['connected', 'available'], true) ? 'text-emerald-800' : 'text-amber-800' }}">
                            {{ str_replace('_', ' ', ucfirst($source['status'])) }}
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-zinc-600">{{ $source['last_success_at'] ? 'Last activity '.\Illuminate\Support\Carbon::parse($source['last_success_at'])->diffForHumans() : ($source['required'] ? 'Required setup' : 'Optional source') }}</p>
                </a>
            @endforeach
            <a href="#event-source" class="block border border-zinc-200 bg-white p-4 transition hover:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-700">
                <div class="flex items-center justify-between gap-3"><h2 class="font-semibold">Event workbook</h2><span class="text-xs font-semibold text-amber-800">{{ str_replace('_', ' ', ucfirst($commandCenter['event_source']['status'])) }}</span></div>
                <p class="mt-2 text-xs text-zinc-600">Google Drive is the preferred source of truth.</p>
            </a>
        </section>

        <section class="border border-zinc-200 bg-white" aria-labelledby="income-expense-heading">
            <div class="flex flex-col gap-3 border-b border-zinc-200 p-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 id="income-expense-heading" class="text-xl font-semibold text-zinc-950">Income and expenses</h2>
                    <p class="mt-1 text-sm text-zinc-600">QuickBooks Profit and Loss for {{ strtolower($commandCenter['range']['label']) }}.</p>
                </div>
                @if($ledger['stale'])
                    <a href="{{ route('integrations.quickbooks.index') }}" class="text-sm font-semibold text-amber-800 hover:underline">Review stale or missing source →</a>
                @endif
            </div>

            @if($ledger['available'])
                <div class="grid gap-6 p-5 lg:grid-cols-[minmax(0,1.7fr)_minmax(18rem,0.8fr)]">
                    <div aria-label="Income, expense, and net result comparison chart" role="img">
                        @foreach([
                            ['label' => 'Gross income', 'value' => $ledger['gross_income'], 'color' => 'bg-emerald-700'],
                            ['label' => 'Expenses', 'value' => $ledger['expenses'], 'color' => 'bg-amber-600'],
                            ['label' => 'Net operating result', 'value' => $ledger['net_operating_result'], 'color' => ($ledger['net_operating_result'] ?? 0) >= 0 ? 'bg-sky-800' : 'bg-red-700'],
                        ] as $series)
                            <a href="#transactions" class="mb-5 block focus:outline-none focus:ring-2 focus:ring-emerald-700">
                                <div class="mb-2 flex items-center justify-between gap-4 text-sm"><span class="font-semibold">{{ $series['label'] }}</span><span>{{ $money($series['value']) }}</span></div>
                                <div class="h-8 bg-zinc-100" aria-hidden="true"><div class="h-8 {{ $series['color'] }}" style="width: {{ $chartWidth($series['value']) }}%"></div></div>
                            </a>
                        @endforeach
                    </div>
                    <dl class="grid content-start gap-3 sm:grid-cols-3 lg:grid-cols-1">
                        <div class="border-l-4 border-emerald-700 bg-zinc-50 p-4"><dt class="text-xs font-semibold uppercase text-zinc-500">Gross income</dt><dd class="mt-1 text-2xl font-semibold">{{ $money($ledger['gross_income']) }}</dd></div>
                        <div class="border-l-4 border-amber-600 bg-zinc-50 p-4"><dt class="text-xs font-semibold uppercase text-zinc-500">Expenses</dt><dd class="mt-1 text-2xl font-semibold">{{ $money($ledger['expenses']) }}</dd></div>
                        <div class="border-l-4 border-sky-800 bg-zinc-50 p-4"><dt class="text-xs font-semibold uppercase text-zinc-500">Net result</dt><dd class="mt-1 text-2xl font-semibold">{{ $money($ledger['net_operating_result']) }}</dd></div>
                    </dl>
                </div>
            @else
                <div class="p-8 text-center">
                    <p class="font-semibold text-zinc-900">No matching QuickBooks P&amp;L snapshot is available.</p>
                    <p class="mx-auto mt-2 max-w-2xl text-sm text-zinc-600">Everbranch will not substitute operational sales or display zero. Connect or refresh QuickBooks, then compare the resulting totals with the QuickBooks report.</p>
                    <a class="fb-btn-soft mt-4 inline-flex px-4 py-2 text-sm font-semibold" href="{{ route('integrations.quickbooks.index') }}">Open QuickBooks setup</a>
                </div>
            @endif
        </section>

        <section id="source-coverage" aria-labelledby="revenue-heading">
            <div class="mb-3">
                <h2 id="revenue-heading" class="text-xl font-semibold">Revenue mix</h2>
                <p class="mt-1 text-sm text-zinc-600">{{ $commandCenter['revenue_mix']['basis'] }}</p>
            </div>
            <div class="grid gap-3 lg:grid-cols-3">
                @foreach($commandCenter['revenue_mix']['streams'] as $key => $stream)
                    <a href="#transactions" class="border border-zinc-200 bg-white p-5 transition hover:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-700">
                        <div class="flex items-start justify-between gap-4"><h3 class="font-semibold">{{ $streamLabels[$key] }}</h3><span class="text-xs font-semibold text-zinc-500">{{ $percent($stream['percentage']) }}</span></div>
                        <div class="mt-3 text-2xl font-semibold">{{ $money($stream['amount']) }}</div>
                        <div class="mt-2 text-sm text-zinc-600">{{ number_format($stream['count']) }} source records · {{ $stream['source'] }}</div>
                        @if($key === 'events' && ($stream['unmapped_count'] ?? 0) > 0)
                            <div class="mt-2 text-xs text-zinc-500">{{ number_format($stream['unmapped_count']) }} completed Square payments still need event mapping.</div>
                        @endif
                        <div class="mt-3 text-xs font-semibold text-amber-800">{{ str_replace('_', ' ', ucfirst($stream['reconciliation_status'])) }}</div>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            <article class="border border-zinc-200 bg-white p-5" aria-labelledby="payroll-heading">
                <div class="flex items-start justify-between gap-4"><div><h2 id="payroll-heading" class="text-lg font-semibold">Payroll cost</h2><p class="mt-1 text-sm text-zinc-600">Only reviewed QuickBooks account mappings are included.</p></div><a href="{{ route('quickbooks.reports.index', ['tenant' => $tenant->slug]) }}" class="text-sm font-semibold text-emerald-800">Review mappings →</a></div>
                <dl class="mt-5 grid grid-cols-2 gap-4">
                    <div><dt class="text-xs uppercase text-zinc-500">Total payroll</dt><dd class="mt-1 text-xl font-semibold">{{ $money($commandCenter['payroll']['total_cost']) }}</dd></div>
                    <div><dt class="text-xs uppercase text-zinc-500">Of gross revenue</dt><dd class="mt-1 text-xl font-semibold">{{ $percent($commandCenter['payroll']['percentage_of_revenue']) }}</dd></div>
                    <div><dt class="text-xs uppercase text-zinc-500">Owner wages</dt><dd class="mt-1 font-semibold">{{ $money($commandCenter['payroll']['owner_wages']) }}</dd></div>
                    <div><dt class="text-xs uppercase text-zinc-500">Excluding owner</dt><dd class="mt-1 font-semibold">{{ $money($commandCenter['payroll']['excluding_owner']) }}</dd></div>
                </dl>
                @if(!$commandCenter['payroll']['mapping_reviewed'])
                    <p class="mt-5 border-t border-zinc-200 pt-4 text-sm font-semibold text-amber-800">Payroll mappings are incomplete. No ratio is calculated.</p>
                @endif
            </article>

            <article class="border border-zinc-200 bg-white p-5" aria-labelledby="debt-heading">
                <h2 id="debt-heading" class="text-lg font-semibold">Business debt</h2>
                <p class="mt-1 text-sm text-zinc-600">Daily snapshots begin only after liability and credit-card mappings are reviewed.</p>
                <div class="mt-5 text-3xl font-semibold">{{ $money($commandCenter['debt']['total']) }}</div>
                @forelse($commandCenter['debt']['accounts'] as $account)
                    <div class="mt-4 flex items-center justify-between border-t border-zinc-100 pt-4 text-sm"><div><div class="font-semibold">{{ $account['name'] }}</div><div class="text-xs text-zinc-500">{{ ucfirst(str_replace('_', ' ', $account['type'])) }} · {{ $account['observed_on'] }}</div></div><div class="font-semibold">{{ $money($account['balance']) }}</div></div>
                @empty
                    <p class="mt-4 text-sm font-semibold text-amber-800">{{ $commandCenter['debt']['history_note'] }}</p>
                @endforelse
            </article>
        </section>

        <section id="transactions" class="border border-zinc-200 bg-white" aria-labelledby="transactions-heading">
            <div class="border-b border-zinc-200 p-5"><h2 id="transactions-heading" class="text-xl font-semibold">Most recent transactions</h2><p class="mt-1 text-sm text-zinc-600">Read-only QuickBooks financial documents for this period.</p></div>
            <div class="max-h-[30rem] overflow-auto">
                <table class="min-w-full border-collapse text-left text-sm">
                    <caption class="sr-only">Recent QuickBooks transactions for the selected period</caption>
                    <thead class="sticky top-0 bg-zinc-50 text-xs uppercase text-zinc-600"><tr><th class="px-4 py-3" scope="col">Date</th><th class="px-4 py-3" scope="col">Description</th><th class="px-4 py-3" scope="col">Type</th><th class="px-4 py-3" scope="col">Status</th><th class="px-4 py-3 text-right" scope="col">Amount</th></tr></thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse($commandCenter['transactions'] as $transaction)
                            <tr class="hover:bg-zinc-50">
                                <td class="whitespace-nowrap px-4 py-3">{{ $transaction['date'] }}</td>
                                <td class="px-4 py-3"><a class="font-semibold text-emerald-900 hover:underline" href="{{ route('integrations.quickbooks.documents.show', ['tenant' => $tenant->slug, 'document' => $transaction['id']]) }}">{{ $transaction['description'] }}</a><div class="text-xs text-zinc-500">{{ $transaction['source'] }}{{ $transaction['needs_review'] ? ' · Needs review' : '' }}</div></td>
                                <td class="px-4 py-3">{{ ucfirst($transaction['type']) }}</td>
                                <td class="px-4 py-3">{{ ucfirst($transaction['status']) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold">{{ $money($transaction['amount']) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-8 text-center text-zinc-600" colspan="5">No QuickBooks financial documents are available for this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="border border-zinc-200 bg-white" aria-labelledby="close-heading">
            <div class="flex flex-col gap-3 border-b border-zinc-200 p-5 sm:flex-row sm:items-end sm:justify-between">
                <div><h2 id="close-heading" class="text-xl font-semibold">Monthly close</h2><p class="mt-1 text-sm text-zinc-600">{{ $close->period_start->format('F Y') }} · {{ $close->completed_items }} of {{ $close->total_items }} complete</p></div>
                <div class="text-sm font-semibold">{{ $close->total_items ? round(($close->completed_items / $close->total_items) * 100) : 0 }}%</div>
            </div>
            <div class="h-2 bg-zinc-100" aria-hidden="true"><div class="h-2 bg-emerald-700" style="width: {{ $close->total_items ? ($close->completed_items / $close->total_items) * 100 : 0 }}%"></div></div>
            <ol class="divide-y divide-zinc-100">
                @foreach($close->items->sortBy('sort_order') as $item)
                    <li class="flex items-start gap-3 px-5 py-3">
                        <form method="POST" action="{{ route('accounting.close-items.update', ['tenant' => $tenant->slug, 'item' => $item->id]) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="completed" value="{{ $item->status === 'completed' ? 0 : 1 }}">
                            <button class="mt-0.5 flex h-7 w-7 items-center justify-center border {{ $item->status === 'completed' ? 'border-emerald-800 bg-emerald-800 text-white' : 'border-zinc-300 bg-white text-zinc-500' }}" aria-label="{{ $item->status === 'completed' ? 'Reopen' : 'Complete' }}: {{ $item->title }}">{{ $item->status === 'completed' ? '✓' : $item->sort_order }}</button>
                        </form>
                        <div class="min-w-0 flex-1"><div class="font-medium {{ $item->status === 'completed' ? 'text-zinc-500 line-through' : 'text-zinc-900' }}">{{ $item->title }}</div><div class="mt-1 text-xs text-zinc-500">{{ $item->status === 'completed' && $item->completed_at ? 'Completed '.$item->completed_at->diffForHumans() : 'Open · evidence and notes supported' }}</div></div>
                    </li>
                @endforeach
            </ol>
        </section>

        <section aria-labelledby="tasks-heading">
            <div class="mb-3"><h2 id="tasks-heading" class="text-xl font-semibold">Accountant tasks</h2><p class="mt-1 text-sm text-zinc-600">Workflow reminders only—not tax advice. Dates remain unset until confirmed.</p></div>
            <div class="grid gap-3 lg:grid-cols-2">
                @forelse($commandCenter['compliance_tasks'] as $task)
                    <article class="border border-zinc-200 bg-white p-5">
                        <div class="flex items-start justify-between gap-4"><div><div class="text-xs font-semibold uppercase text-zinc-500">{{ $task->jurisdiction ?: 'Review required' }}</div><h3 class="mt-1 font-semibold">{{ $task->name }}</h3></div><span class="whitespace-nowrap text-xs font-semibold text-amber-800">{{ str_replace('_', ' ', ucfirst($task->status)) }}</span></div>
                        <p class="mt-3 text-sm leading-6 text-zinc-600">{{ $task->explanation }}</p>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs uppercase text-zinc-500">Due</dt><dd class="mt-1 font-semibold">{{ $task->due_at ? $task->due_at->format('M j, Y') : 'Confirm during setup' }}</dd></div><div><dt class="text-xs uppercase text-zinc-500">Verification</dt><dd class="mt-1 font-semibold">{{ ucfirst($task->confidence) }}</dd></div></dl>
                        @if($task->destination_url)
                            <a class="mt-4 inline-flex text-sm font-semibold text-emerald-800 hover:underline" href="{{ $task->destination_url }}" target="_blank" rel="noopener noreferrer">Open {{ $task->destination_name }} ↗</a>
                        @endif
                    </article>
                @empty
                    <div class="border border-zinc-200 bg-white p-6 text-sm text-zinc-600 lg:col-span-2">Apply a business setup draft to create review-required compliance work.</div>
                @endforelse
            </div>
        </section>

        <section id="event-source" class="border border-zinc-200 bg-white p-5" aria-labelledby="event-heading">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="text-xs font-semibold uppercase text-zinc-500">Preferred source · Google Drive</div>
                    <h2 id="event-heading" class="mt-1 text-xl font-semibold">Event profitability</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600">{{ $commandCenter['event_source']['message'] }}</p>
                    <p class="mt-2 text-xs text-zinc-500">Drive file ID: {{ $commandCenter['event_source']['google_drive_file_id'] ?: 'Not configured' }}</p>
                </div>
                @if($commandCenter['event_source']['source_url'])
                    <a class="fb-btn-soft inline-flex whitespace-nowrap px-4 py-2 text-sm font-semibold" href="{{ $commandCenter['event_source']['source_url'] }}" target="_blank" rel="noopener noreferrer">Open source workbook ↗</a>
                @endif
            </div>
            <div class="mt-5 border-t border-zinc-200 pt-4 text-sm font-semibold text-amber-800">Profitability remains incomplete until the live sheet names, event identifier, columns, formulas, and overrides are mapped and tested.</div>
        </section>
    </main>
</x-layouts::app.sidebar>
