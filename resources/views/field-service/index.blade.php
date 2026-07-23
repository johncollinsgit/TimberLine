<x-layouts::app.sidebar title="Field Operations">
    <flux:main>
        <div class="mx-auto w-full max-w-[1900px] space-y-5 px-3 py-4 sm:px-5 lg:px-7">
            <header class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-700">{{ $tenant->name }}</div>
                        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-950">Work</h1>
                        <p class="mt-2 max-w-2xl text-sm text-zinc-600">Find, sort, assign, and update field work without leaving the grid.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('field-service.calendar') }}" class="fb-btn fb-btn-secondary">Calendar</a>
                        <a href="{{ route('field-service.payroll-hours') }}" class="fb-btn fb-btn-secondary">Team hours</a>
                        <a href="{{ route('field-service.resources') }}" class="fb-btn fb-btn-secondary">Inventory & vans</a>
                        @if($equipmentMaintenanceEnabled ?? false)<a href="{{ route('field-service.equipment.index') }}" class="fb-btn fb-btn-secondary">Equipment</a>@endif
                        @if(data_get($capabilities ?? [], 'create_jobs'))<a href="#new-job" class="fb-btn fb-btn-primary">Create job</a>@endif
                    </div>
                </div>
                @if($ownerMetrics)
                    <div class="mt-5 border-t border-zinc-200 pt-5">
                        <nav class="inline-flex rounded-xl bg-zinc-100 p-1" aria-label="Financial period">@foreach(data_get($ownerMetrics, 'options', []) as $option)<a href="{{ route('field-service.index', ['period' => $option['key']]) }}" class="min-h-11 rounded-lg px-4 py-3 text-sm font-semibold {{ data_get($ownerMetrics, 'period') === $option['key'] ? 'bg-white text-emerald-900 shadow-sm' : 'text-zinc-600' }}">{{ $option['label'] }}</a>@endforeach</nav>
                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Money In</div><div class="mt-2 text-2xl font-semibold text-emerald-950">{{ data_get($ownerMetrics, 'money_in') === null ? '—' : '$'.number_format((float) data_get($ownerMetrics, 'money_in'), 0) }}</div></div>
                            <div class="rounded-2xl border border-amber-100 bg-amber-50 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-amber-700">Money Spent</div><div class="mt-2 text-2xl font-semibold text-amber-950">{{ data_get($ownerMetrics, 'money_spent') === null ? '—' : '$'.number_format((float) data_get($ownerMetrics, 'money_spent'), 0) }}</div></div>
                            <div class="rounded-2xl border border-sky-100 bg-sky-50 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-sky-700">Finished Jobs</div><div class="mt-2 text-2xl font-semibold text-sky-950">{{ number_format((int) data_get($ownerMetrics, 'finished_jobs', 0)) }}</div></div>
                        </div>
                        <p class="mt-2 text-xs text-zinc-500">{{ data_get($ownerMetrics, 'quickbooks.message') }}</p>
                    </div>
                @endif
            </header>

            <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-3 shadow-sm sm:p-5">
                <div
                    id="field-service-jobs-grid"
                    data-endpoint="{{ route('field-service.jobs.data') }}"
                    data-update-template="{{ route('field-service.jobs.update', ['job' => 0]) }}"
                    data-candidate-template="{{ route('field-service.work-candidates.review', ['candidate' => 0]) }}"
                    data-can-manage="{{ data_get($capabilities ?? [], 'manage_jobs') ? '1' : '0' }}"
                    data-can-manage-drafts="{{ ($canManageJobDrafts ?? false) ? '1' : '0' }}"
                    class="min-h-[680px]"
                >
                    <div class="rounded-2xl border border-dashed border-zinc-300 bg-white p-6 text-sm text-zinc-600">Loading the work grid…</div>
                </div>
                <noscript class="mt-3 block rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">JavaScript is required for spreadsheet editing. The Calendar and job detail links remain available above.</noscript>
            </section>

            @if(data_get($capabilities ?? [], 'create_jobs'))
                <section id="new-job" class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7">
                    <div class="mb-4">
                        <h2 class="text-xl font-semibold text-zinc-950">Create job</h2>
                        <p class="mt-1 text-sm text-zinc-600">Start with the essentials. You can fill in the rest from the job.</p>
                    </div>
                    <form method="POST" action="{{ route('field-service.jobs.store') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @csrf
                        <input name="customer_name" required class="rounded-xl border border-zinc-300 px-3 py-3 text-sm" placeholder="Customer name">
                        <input name="title" required class="rounded-xl border border-zinc-300 px-3 py-3 text-sm" placeholder="Job title">
                        <input name="customer_phone" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm" placeholder="Customer phone">
                        <input name="service_address_line_1" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm" placeholder="Service address">
                        <input name="scheduled_for" type="datetime-local" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm">
                        <select name="assigned_user_id" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm">
                            <option value="">Unassigned</option>
                            @foreach($team as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                        </select>
                        <textarea name="description" rows="2" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm md:col-span-2" placeholder="Scope, access notes, or instructions"></textarea>
                        <button type="submit" class="fb-btn fb-btn-primary min-h-11 justify-center">Create job</button>
                    </form>
                </section>
            @endif

            <section class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex items-center justify-between gap-3"><div><h2 class="text-xl font-semibold text-zinc-950">My assigned tasks</h2><p class="mt-1 text-sm text-zinc-600">Your open next steps, including work waiting on you.</p></div><span class="rounded-full bg-zinc-100 px-3 py-1 text-sm font-semibold text-zinc-700">{{ number_format((int) ($assignedTaskTotal ?? 0)) }}</span></div>
                <div class="mt-4 divide-y divide-zinc-200 border-y border-zinc-200">
                    @forelse($assignedTasks ?? [] as $task)
                        <a href="{{ route('field-service.jobs.show', $task->job) }}" class="grid min-h-16 grid-cols-[8px_minmax(0,1fr)_auto] items-center gap-3 py-3">
                            <span class="h-9 rounded-full {{ $task->priority === 'urgent' ? 'bg-rose-500' : ($task->priority === 'high' ? 'bg-amber-500' : 'bg-emerald-400') }}"></span>
                            <span class="min-w-0">
                                <strong class="block truncate text-zinc-950">{{ $task->title }}</strong>
                                <small class="mt-1 block truncate text-zinc-500">
                                    {{ $task->job?->title }}
                                    @if($task->status === 'waiting')
                                        · Waiting
                                    @endif
                                    @if($task->due_at)
                                        · {{ $task->due_at->format('M j, g:ia') }}
                                    @endif
                                </small>
                            </span>
                            <span class="text-sm font-semibold text-emerald-800">Open →</span>
                        </a>
                    @empty
                        <p class="py-6 text-sm text-zinc-600">No open tasks assigned to you.</p>
                    @endforelse
                </div>
                @if(($assignedTaskTotal ?? 0) > 50)<p class="mt-3 text-xs text-zinc-500">Showing the first 50 tasks in priority and due-date order. Use the mobile assigned-task feed to continue loading.</p>@endif
            </section>
        </div>
        @vite('resources/js/field-service/jobs-grid.tsx')
    </flux:main>
</x-layouts::app.sidebar>
