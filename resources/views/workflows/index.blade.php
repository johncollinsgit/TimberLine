<x-layouts::app :title="'Order Calendar'">
    <div class="min-h-full bg-[radial-gradient(circle_at_top_left,rgba(16,185,129,0.10),transparent_32%),linear-gradient(180deg,#fafaf9,#f5f5f4)]">
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-9">
            <header class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="mb-3 inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.18em] text-emerald-800">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span> Order Calendar
                    </div>
                    <h1 class="text-3xl font-black tracking-tight text-zinc-950 sm:text-4xl">Your work, on the right calendar.</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-600 sm:text-base">Connect a work or store source, choose exactly how events should look, test every step, and see what happened on every run.</p>
                </div>
                <a href="{{ route('workflows.create') }}" wire:navigate class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-emerald-900/15 transition hover:-translate-y-0.5 hover:bg-emerald-700 focus:outline-none focus:ring-4 focus:ring-emerald-200">+ Create workflow</a>
            </header>

            <x-workflows.partials.nav />

            <form method="GET" class="grid gap-3 rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm sm:grid-cols-[minmax(0,1fr)_180px_auto]">
                <label class="sr-only" for="workflow-search">Search workflows</label>
                <input id="workflow-search" name="search" value="{{ $search }}" placeholder="Search workflows" class="rounded-xl border-zinc-200 bg-zinc-50 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                <label class="sr-only" for="workflow-status">Status</label>
                <select id="workflow-status" name="status" class="rounded-xl border-zinc-200 bg-zinc-50 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    @foreach(['all' => 'All statuses', 'active' => 'On', 'paused' => 'Paused', 'draft' => 'Draft'] as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="rounded-xl border border-zinc-300 bg-white px-4 py-2 text-sm font-bold text-zinc-800 hover:bg-zinc-50">Filter</button>
            </form>

            @if($workflows->isEmpty())
                <section class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-xl shadow-zinc-900/5">
                    <div class="grid lg:grid-cols-[1.1fr_.9fr]">
                        <div class="p-7 sm:p-10">
                            <span class="text-xs font-black uppercase tracking-[0.2em] text-emerald-700">Your first workflow</span>
                            <h2 class="mt-3 text-2xl font-black text-zinc-950">Turn dated Asana tasks into calendar events.</h2>
                            <p class="mt-3 max-w-xl text-sm leading-6 text-zinc-600">Connect once, choose a project and calendar, run a safe test, then publish. New tasks create events and later edits update the same event.</p>
                            <a href="{{ route('workflows.create') }}" wire:navigate class="mt-6 inline-flex rounded-xl bg-zinc-950 px-5 py-3 text-sm font-bold text-white hover:bg-zinc-800">Use a guided template</a>
                        </div>
                        <div class="relative flex min-h-64 items-center justify-center overflow-hidden bg-emerald-50 p-8">
                            <div class="absolute inset-0 opacity-50 [background-image:radial-gradient(#10b981_1px,transparent_1px)] [background-size:18px_18px]"></div>
                            <div class="relative w-full max-w-xs space-y-3">
                                <div class="flex items-center gap-3 rounded-2xl border border-rose-200 bg-white p-4 shadow-lg"><x-workflows.partials.provider-icon provider="asana" :providers="$providers" /><div><div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">When this happens</div><div class="font-bold text-zinc-950">Task is updated</div></div></div>
                                <div class="mx-auto h-8 w-px bg-emerald-400"></div>
                                <div class="flex items-center gap-3 rounded-2xl border border-sky-200 bg-white p-4 shadow-lg"><x-workflows.partials.provider-icon provider="google_calendar" :providers="$providers" /><div><div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">Do this</div><div class="font-bold text-zinc-950">Update calendar event</div></div></div>
                            </div>
                        </div>
                    </div>
                </section>
            @else
                <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
                    <div class="hidden grid-cols-[minmax(0,1fr)_100px_150px_120px_110px_44px] gap-4 border-b border-zinc-200 bg-zinc-50 px-5 py-3 text-[11px] font-black uppercase tracking-wider text-zinc-500 md:grid">
                        <span>Workflow</span><span>Status</span><span>Last run</span><span>Success rate</span><span>Recent activity</span><span></span>
                    </div>
                    @foreach($workflows as $workflow)
                        @php $lastRun = $workflow->runs->first(); $template = (array) ($templates[$workflow->template_key] ?? []); @endphp
                        <a href="{{ route('workflows.show', $workflow) }}" wire:navigate class="grid gap-4 border-b border-zinc-100 px-5 py-5 transition last:border-0 hover:bg-emerald-50/40 md:grid-cols-[minmax(0,1fr)_100px_150px_120px_110px_44px] md:items-center">
                            <div class="flex min-w-0 items-center gap-4">
                                <div class="flex -space-x-2"><x-workflows.partials.provider-icon :provider="$template['trigger_provider'] ?? 'asana'" :providers="$providers" /><x-workflows.partials.provider-icon :provider="$template['action_provider'] ?? 'google_calendar'" :providers="$providers" /></div>
                                <div class="min-w-0"><div class="truncate font-bold text-zinc-950">{{ $workflow->name }}</div><div class="mt-1 truncate text-xs text-zinc-500">{{ $template['trigger_event'] ?? 'Trigger' }} → {{ $template['action_event'] ?? 'Action' }}</div></div>
                            </div>
                            <div><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $workflow->status === 'active' ? 'bg-emerald-100 text-emerald-800' : ($workflow->status === 'paused' ? 'bg-amber-100 text-amber-800' : 'bg-zinc-100 text-zinc-700') }}">{{ $workflow->status === 'active' ? 'On' : ucfirst($workflow->status) }}</span></div>
                            <div class="text-sm text-zinc-600">{{ $lastRun?->finished_at?->diffForHumans() ?? 'Not run yet' }}</div>
                            <div class="text-sm font-semibold text-zinc-700">{{ $workflow->runs_count > 0 ? round(($workflow->successful_runs_count / $workflow->runs_count) * 100).'%' : '—' }}</div>
                            <div class="text-sm font-semibold {{ $lastRun?->status === 'success' ? 'text-emerald-700' : ($lastRun ? 'text-rose-700' : 'text-zinc-400') }}">{{ $lastRun ? str($lastRun->status)->headline() : 'Waiting' }}</div>
                            <span class="text-xl text-zinc-400">›</span>
                        </a>
                    @endforeach
                </section>
            @endif
        </div>
    </div>
</x-layouts::app>
