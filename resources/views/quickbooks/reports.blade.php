@php
    $cards = $report['cards'];
    $money = fn ($value) => $value === null ? 'Not configured' : '$'.number_format((float) $value, 2);
    $percent = fn ($value) => $value === null ? '—' : number_format((float) $value, 1).'%';
    $labels = fn ($value) => implode(', ', array_map(fn ($row) => is_array($row) ? ($row['label'] ?? '') : $row, (array) $value));
@endphp

<x-layouts::app.sidebar :title="$tenant->name.' reports'">
    <div class="mx-auto w-full max-w-[1500px] space-y-6 px-4 py-6 sm:px-6">
        <header class="flex flex-col gap-4 border-b border-zinc-200 pb-5 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <div class="text-xs font-semibold uppercase text-emerald-800">Owner reporting</div>
                <h1 class="mt-2 text-3xl font-semibold text-zinc-950">QuickBooks and field work</h1>
                <p class="mt-2 max-w-3xl text-sm text-zinc-600">Accounting history and operational completion stay separate. Financial values are visible only to workspace owners and administrators.</p>
            </div>
            <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto lg:items-end"><form method="POST" action="{{ route('quickbooks.reports.refresh', $tenant) }}">@csrf<button class="rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-semibold">Refresh QuickBooks</button></form><form method="GET" class="w-full lg:w-52">
                <label class="text-xs font-semibold text-zinc-600" for="report-range">Time window</label>
                <select id="report-range" name="range" onchange="this.form.submit()" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold">
                    @foreach($report['range']['options'] as $value => $label)
                        <option value="{{ $value }}" @selected($report['range']['key'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </form></div>
        </header>

        @if(session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">{{ session('status') }}</div>
        @endif
        @if(session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-900">{{ session('error') }}</div>
        @endif

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-lg border border-zinc-200 bg-white p-4">
                <div class="text-xs font-semibold uppercase text-zinc-500">Unpaid invoices</div>
                <div class="mt-2 text-3xl font-semibold">{{ $money($cards['unpaid_invoices']['amount']) }}</div>
                <div class="mt-2 text-sm text-zinc-600">{{ number_format($cards['unpaid_invoices']['count']) }} open · {{ $money($cards['unpaid_invoices']['overdue_amount']) }} overdue</div>
            </article>
            <article class="rounded-lg border border-zinc-200 bg-white p-4">
                <div class="text-xs font-semibold uppercase text-zinc-500">Supplies and materials</div>
                <div class="mt-2 text-3xl font-semibold">{{ $money($cards['supplies']['amount']) }}</div>
                <div class="mt-2 text-sm text-zinc-600">Mapped P&amp;L accounts · {{ $report['range']['label'] }}</div>
            </article>
            <article class="rounded-lg border border-zinc-200 bg-white p-4">
                <div class="text-xs font-semibold uppercase text-zinc-500">Contract labor</div>
                <div class="mt-2 text-3xl font-semibold">{{ $money($cards['contract_labor']['amount']) }}</div>
                <div class="mt-2 text-sm text-zinc-600">{{ $percent($cards['contract_labor']['percent']) }} of income</div>
            </article>
            <article class="rounded-lg border border-zinc-200 bg-white p-4">
                <div class="text-xs font-semibold uppercase text-zinc-500">Work billed</div>
                <div class="mt-2 text-3xl font-semibold">{{ $money($cards['work_billed']['amount']) }}</div>
                <div class="mt-2 text-sm text-zinc-600">{{ number_format($cards['work_billed']['count']) }} invoices · {{ $percent($cards['work_billed']['year_over_year_percent']) }} YoY</div>
            </article>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 class="text-lg font-semibold">Labor</h2>
                <dl class="mt-4 grid grid-cols-2 gap-4">
                    <div><dt class="text-xs uppercase text-zinc-500">Employees, with owner</dt><dd class="mt-1 text-xl font-semibold">{{ $money($cards['employee_labor']['including_owner']) }}</dd><dd class="text-xs text-zinc-500">{{ $percent($cards['employee_labor']['including_owner_percent']) }} of income</dd></div>
                    <div><dt class="text-xs uppercase text-zinc-500">Employees, without owner</dt><dd class="mt-1 text-xl font-semibold">{{ $money($cards['employee_labor']['excluding_owner']) }}</dd><dd class="text-xs text-zinc-500">{{ $cards['employee_labor']['separable'] ? $percent($cards['employee_labor']['excluding_owner_percent']).' of income' : 'Owner compensation is not separable yet' }}</dd></div>
                    <div><dt class="text-xs uppercase text-zinc-500">Owner compensation</dt><dd class="mt-1 text-xl font-semibold">{{ $money($cards['employee_labor']['owner_compensation']) }}</dd></div>
                    <div><dt class="text-xs uppercase text-zinc-500">Combined labor</dt><dd class="mt-1 text-xl font-semibold">{{ $money($cards['combined_labor']['amount']) }}</dd><dd class="text-xs text-zinc-500">{{ $percent($cards['combined_labor']['percent']) }} of income</dd></div>
                </dl>
            </article>
            <article class="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 class="text-lg font-semibold">Work comparison</h2>
                <dl class="mt-4 grid grid-cols-2 gap-4">
                    <div><dt class="text-xs uppercase text-zinc-500">Jobs completed</dt><dd class="mt-1 text-2xl font-semibold">{{ number_format($cards['jobs_completed']['count']) }}</dd><dd class="text-xs text-zinc-500">{{ number_format($cards['jobs_completed']['prior_count']) }} in the prior-year window</dd></div>
                    <div><dt class="text-xs uppercase text-zinc-500">Invoices issued</dt><dd class="mt-1 text-2xl font-semibold">{{ number_format($cards['work_billed']['count']) }}</dd><dd class="text-xs text-zinc-500">{{ number_format($cards['work_billed']['prior_count']) }} in the prior-year window</dd></div>
                </dl>
                <p class="mt-4 text-xs leading-5 text-zinc-500">QuickBooks invoices describe work billed. Only Everbranch jobs with a completion timestamp count as completed jobs.</p>
            </article>
        </section>

        <section class="grid gap-4 xl:grid-cols-3">
            <article class="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 class="font-semibold">Next jobs</h2>
                <div class="mt-3 divide-y divide-zinc-100">
                    @forelse($report['upcoming_jobs'] as $job)
                        <a class="block py-3" href="{{ route('field-service.jobs.show', ['job' => $job['id'], 'back' => 'calendar']) }}">
                            <div class="font-semibold text-zinc-900">{{ $job['title'] }}</div>
                            <div class="mt-1 text-xs text-zinc-500">{{ $job['scheduled_for'] ? \Illuminate\Support\Carbon::parse($job['scheduled_for'])->format('M j, g:i A') : '' }} · {{ $job['assigned_to'] ?: 'Unassigned' }}</div>
                            <div class="mt-1 text-sm text-zinc-600">{{ $job['address'] ?: 'No service address' }}</div>
                        </a>
                    @empty
                        <p class="py-5 text-sm text-zinc-500">No upcoming jobs are scheduled.</p>
                    @endforelse
                </div>
            </article>
            <article class="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 class="font-semibold">Largest customers</h2>
                <div class="mt-3 divide-y divide-zinc-100">
                    @forelse($report['largest_customers'] as $customer)
                        <div class="flex items-center justify-between gap-3 py-3"><div><div class="font-semibold">{{ $customer['name'] }}</div><div class="text-xs text-zinc-500">{{ $customer['documents'] }} invoices</div></div><div class="font-semibold">{{ $money($customer['amount']) }}</div></div>
                    @empty
                        <p class="py-5 text-sm text-zinc-500">No invoiced customer activity in this window.</p>
                    @endforelse
                </div>
            </article>
            <article class="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 class="font-semibold">Quote aging</h2>
                <div class="mt-3 text-3xl font-semibold">{{ number_format($report['quote_aging']['count']) }}</div>
                <div class="text-sm text-zinc-600">{{ $money($report['quote_aging']['amount']) }} pending</div>
                <dl class="mt-4 space-y-2 text-sm"><div class="flex justify-between"><dt>Under 30 days</dt><dd>{{ $report['quote_aging']['under_30_days'] }}</dd></div><div class="flex justify-between"><dt>31–90 days</dt><dd>{{ $report['quote_aging']['days_31_to_90'] }}</dd></div><div class="flex justify-between"><dt>Over 90 days</dt><dd>{{ $report['quote_aging']['over_90_days'] }}</dd></div></dl>
            </article>
        </section>

        <details class="rounded-lg border border-zinc-200 bg-white p-5">
            <summary class="cursor-pointer font-semibold">QuickBooks reporting setup</summary>
            <form method="POST" action="{{ route('quickbooks.reports.settings', $tenant) }}" class="mt-5 grid gap-4 lg:grid-cols-2">
                @csrf @method('PUT')
                <label class="flex items-center gap-2 lg:col-span-2"><input type="checkbox" name="scheduled_sync_enabled" value="1" @checked($settings?->scheduled_sync_enabled)> <span class="text-sm font-semibold">Hourly read-only synchronization</span></label>
                @foreach([
                    'supplies_accounts' => ['Supplies and material accounts', $settings?->supplies_account_mappings],
                    'wage_accounts' => ['Employee wage accounts', $settings?->wage_account_mappings],
                    'contract_labor_accounts' => ['Contract labor accounts', $settings?->contract_labor_account_mappings],
                    'owner_compensation_accounts' => ['Owner compensation accounts', $settings?->owner_compensation_account_mappings],
                ] as $name => [$label, $value])
                    <label class="block text-sm font-semibold">{{ $label }}<textarea name="{{ $name }}" rows="3" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-normal" placeholder="One exact QuickBooks account label per line">{{ $labels($value) }}</textarea></label>
                @endforeach
                <label class="block text-sm font-semibold lg:col-span-2">Monthly owner compensation adjustments<textarea name="owner_compensation_adjustments" rows="3" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-xs" placeholder='{"2026-01": 5000, "2026-02": 5000}'>{{ json_encode($settings?->owner_compensation_adjustments ?: new stdClass, JSON_PRETTY_PRINT) }}</textarea></label>
                @if(!$report['mapping_state']['reviewed'] && $report['mapping_state']['suggestions'])
                    <div class="rounded-lg bg-amber-50 p-4 text-sm text-amber-950 lg:col-span-2">Suggested mappings were found in the latest P&amp;L. Review the exact QuickBooks account labels before financial cards are calculated.</div>
                @endif
                <div class="lg:col-span-2"><button class="rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white">Save reviewed mappings</button></div>
            </form>
        </details>
    </div>
</x-layouts::app.sidebar>
