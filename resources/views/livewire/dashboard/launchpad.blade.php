@php
    $experience = is_array($dashboard['experience_profile'] ?? null) ? $dashboard['experience_profile'] : [];
    $workspace = is_array($experience['workspace'] ?? null) ? $experience['workspace'] : [];
    $hero = is_array($dashboard['hero'] ?? null) ? $dashboard['hero'] : [];
    $summaryCards = is_array($dashboard['summary_cards'] ?? null) ? $dashboard['summary_cards'] : [];
    $nextActions = is_array($dashboard['next_actions'] ?? null) ? $dashboard['next_actions'] : [];
    $pinnedModules = is_array($dashboard['pinned_modules'] ?? null) ? $dashboard['pinned_modules'] : [];
    $dateRange = is_array($dashboard['date_range'] ?? null) ? $dashboard['date_range'] : [];
    $rangeOptions = is_array($dateRange['options'] ?? null) ? $dateRange['options'] : [];
    $upcomingJobs = is_array($dashboard['upcoming_jobs'] ?? null) ? $dashboard['upcoming_jobs'] : [];
    $ownerReporting = is_array($dashboard['owner_reporting'] ?? null) ? $dashboard['owner_reporting'] : null;
    $classCalendar = is_array($dashboard['class_calendar'] ?? null) ? $dashboard['class_calendar'] : null;
@endphp

<div class="mx-auto w-full max-w-[1800px] px-3 pb-4 pt-2 sm:px-4 sm:pb-6 sm:pt-3 md:px-6 min-w-0">
    <div class="space-y-6 sm:space-y-8 min-w-0">
        <section class="mx-auto w-full max-w-3xl" aria-label="Workspace search">
            <form wire:submit="submitSearch" class="relative">
                <label for="dashboard-launchpad-search" class="sr-only">Search the workspace</label>
                <input
                    id="dashboard-launchpad-search"
                    type="search"
                    wire:model.defer="search"
                    placeholder="Search your workspace"
                    class="h-12 w-full rounded-full border border-[var(--fb-border)] bg-white pl-5 pr-14 text-sm text-[var(--fb-text)] placeholder:text-[var(--fb-muted)] focus:border-[var(--fb-brand)] focus:outline-none focus:ring-4 focus:ring-emerald-900/5"
                    style="box-shadow: var(--fb-shadow-soft);"
                    autocomplete="off"
                />
                <button type="submit" class="absolute right-1.5 top-1.5 inline-flex size-9 items-center justify-center rounded-full bg-[var(--fb-brand)] text-white transition hover:bg-[var(--fb-brand-2)]" aria-label="Search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                </button>
            </form>
        </section>

        @if($classCalendar)
            @php
                $calendarMonth = \Illuminate\Support\Carbon::createFromFormat('!Y-m', $classCalendar['month']);
                $calendarStart = $calendarMonth->copy()->startOfMonth()->startOfWeek();
                $calendarEnd = $calendarMonth->copy()->endOfMonth()->endOfWeek();
                $classesByDate = collect($classCalendar['classes'])->groupBy(fn (array $class): string => \Illuminate\Support\Carbon::parse($class['starts_at'])->format('Y-m-d'));
            @endphp
            <section class="mf-app-card overflow-hidden rounded-3xl">
                <div class="flex items-center justify-between gap-4 border-b border-[var(--fb-border)] px-5 py-4 sm:px-6">
                    <div><div class="text-[11px] uppercase tracking-[0.22em] text-[var(--fb-muted)]">Class calendar</div><h2 class="mt-1 text-xl font-semibold text-[var(--fb-text)]">{{ $classCalendar['label'] }}</h2></div>
                    <a href="{{ $classCalendar['href'] }}" class="text-sm font-semibold text-[var(--fb-brand)]">Manage classes</a>
                </div>
                <div class="grid grid-cols-7 border-b border-[var(--fb-border)] bg-[var(--fb-surface-muted)] text-center text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--fb-muted)]">
                    @foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $day)<div class="px-1 py-2">{{ $day }}</div>@endforeach
                </div>
                <div class="grid grid-cols-7">
                    @for($day = $calendarStart->copy(); $day->lte($calendarEnd); $day->addDay())
                        @php
                            $dayClasses = $classesByDate->get($day->format('Y-m-d'), collect());
                        @endphp
                        <div class="min-h-24 border-b border-r border-[var(--fb-border)] p-1.5 {{ $day->month !== $calendarMonth->month ? 'bg-zinc-50/70 text-zinc-400' : 'bg-white' }}">
                            <div class="text-xs font-semibold">{{ $day->day }}</div>
                            <div class="mt-1 space-y-1">
                                @foreach($dayClasses as $class)
                                    <a href="{{ $class['href'] }}" class="block rounded-lg bg-emerald-50 px-1.5 py-1 text-[10px] font-semibold leading-tight text-emerald-900 hover:bg-emerald-100">
                                        {{ \Illuminate\Support\Carbon::parse($class['starts_at'])->format('g:i A') }} · {{ $class['title'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endfor
                </div>
            </section>
        @endif

        <section class="mf-app-card rounded-3xl p-5 sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="text-[11px] uppercase tracking-[0.24em] text-[var(--fb-muted)]">Primary KPI</div>
                    <h2 class="mt-2 text-xl font-semibold text-[var(--fb-text)]">{{ $hero['label'] ?? 'Workspace readiness' }}</h2>
                    <p class="mt-2 text-sm text-[var(--fb-muted)]">{{ $hero['supporting'] ?? '' }}</p>
                </div>
                <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-end">
                    <label class="block min-w-[11rem] text-xs font-medium text-[var(--fb-muted)]">
                        <span class="mb-1.5 block">Time window</span>
                        <select wire:model.live="range" class="w-full rounded-lg border border-[var(--fb-border)] bg-white px-3 py-2.5 text-sm font-semibold text-[var(--fb-text)] focus:outline-none">
                            @foreach($rangeOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <a href="{{ $hero['href'] ?? route('dashboard') }}" class="min-w-[10rem] rounded-lg border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] px-5 py-4 transition hover:-translate-y-0.5">
                        <div class="text-3xl font-semibold text-[var(--fb-text)]">{{ $hero['value'] ?? 'Ready' }}</div>
                    </a>
                </div>
            </div>

            @if($summaryCards !== [])
                <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                    @foreach($summaryCards as $card)
                        <a href="{{ $card['href'] ?? route('dashboard') }}" class="block rounded-3xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 transition hover:-translate-y-0.5 sm:p-5">
                            <div class="text-xs uppercase tracking-[0.24em] text-[var(--fb-muted)]">{{ $card['label'] ?? 'Metric' }}</div>
                            <div class="mt-3 text-3xl font-semibold text-[var(--fb-text)]">{{ $card['value'] ?? '0' }}</div>
                            <div class="mt-2 text-xs text-[var(--fb-muted)]">{{ $card['detail'] ?? '' }}</div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        @if($upcomingJobs !== [] || $ownerReporting)
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-[1.15fr_0.85fr]">
                <section class="mf-app-card rounded-3xl p-5 sm:p-6">
                    <div class="flex items-center justify-between gap-3">
                        <div><h2 class="text-lg font-semibold text-[var(--fb-text)]">Next jobs</h2><p class="mt-1 text-sm text-[var(--fb-muted)]">Upcoming incomplete work, address, and assignment.</p></div>
                        <a href="{{ route('field-service.calendar') }}" class="text-sm font-semibold text-[var(--fb-brand)]">Open calendar</a>
                    </div>
                    <div class="mt-4 divide-y divide-[var(--fb-border)]">
                        @forelse($upcomingJobs as $job)
                            <a href="{{ $job['href'] ?? route('field-service.jobs.show', ['job' => $job['id']]) }}" class="flex items-center justify-between gap-4 py-3">
                                <div class="min-w-0"><div class="truncate text-sm font-semibold text-[var(--fb-text)]">{{ $job['title'] }}</div><div class="mt-1 truncate text-xs text-[var(--fb-muted)]">{{ $job['address'] ?: 'Address not set' }} · {{ $job['assigned_to'] ?: 'Unassigned' }}</div></div>
                                <time class="shrink-0 text-xs font-semibold text-[var(--fb-muted)]">{{ filled($job['scheduled_for'] ?? null) ? \Illuminate\Support\Carbon::parse($job['scheduled_for'])->format('M j, g:i A') : 'Unscheduled' }}</time>
                            </a>
                        @empty
                            <div class="py-6 text-sm text-[var(--fb-muted)]">No upcoming jobs are scheduled.</div>
                        @endforelse
                    </div>
                </section>
                @if($ownerReporting)
                    <a href="{{ route('quickbooks.reports.index', ['tenant' => $dashboard['tenant_slug'], 'range' => $dateRange['key'] ?? '1m']) }}" class="mf-app-card block rounded-3xl p-5 transition hover:-translate-y-0.5 sm:p-6">
                        <h2 class="text-lg font-semibold text-[var(--fb-text)]">Owner reporting</h2>
                        <p class="mt-1 text-sm text-[var(--fb-muted)]">Detailed labor, supplies, receivables, comparisons, and sync health.</p>
                        <span class="mt-5 inline-flex rounded-lg border border-[var(--fb-border)] bg-white px-4 py-2 text-sm font-semibold text-[var(--fb-brand)]">Open financial reporting</span>
                    </a>
                @endif
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[1.1fr_0.9fr]">
            <section class="mf-app-card rounded-3xl p-5 sm:p-6">
                <div class="mb-5">
                    <h2 class="text-lg font-semibold text-[var(--fb-text)] sm:text-xl">Recommended next actions</h2>
                    <p class="mt-1 text-sm text-[var(--fb-muted)]">Actions shift with tenant mode, current signals, and module availability.</p>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @foreach($nextActions as $action)
                        <a
                            href="{{ $action['href'] ?? route('dashboard') }}"
                            class="group relative overflow-hidden rounded-3xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 transition hover:-translate-y-0.5 focus:outline-none"
                            style="box-shadow: var(--fb-shadow-soft);"
                        >
                            <div class="text-sm font-semibold text-[var(--fb-text)]">{{ $action['label'] ?? 'Action' }}</div>
                            <div class="mt-2 text-sm leading-6 text-[var(--fb-muted)]">{{ $action['description'] ?? '' }}</div>
                        </a>
                    @endforeach
                </div>
            </section>

            <section class="mf-app-card rounded-3xl p-5 sm:p-6">
                <div class="mb-5 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-[var(--fb-text)] sm:text-xl">Pinned modules</h2>
                        <p class="mt-1 text-sm text-[var(--fb-muted)]">A quick read on active and high-value next-step modules.</p>
                    </div>
                    @if(auth()->user()?->canAccessMarketing())
                        <a href="{{ route('marketing.modules') }}" class="inline-flex items-center rounded-full border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] px-3 py-1.5 text-xs font-semibold text-[var(--fb-brand)]">Open Modules</a>
                    @endif
                </div>

                <div class="space-y-3">
                    @forelse($pinnedModules as $module)
                        <a href="{{ $module['href'] ?? '#' }}" class="block rounded-3xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 transition hover:-translate-y-0.5">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-[var(--fb-text)]">{{ $module['display_name'] ?? 'Module' }}</div>
                                    <div class="mt-1 text-sm leading-6 text-[var(--fb-muted)]">{{ $module['description'] ?? '' }}</div>
                                </div>
                                <span class="rounded-full border border-[var(--fb-border)] bg-white px-2.5 py-1 text-[10px] uppercase tracking-[0.18em] text-[var(--fb-muted)]">{{ $module['state_label'] ?? 'Module' }}</span>
                            </div>
                        </a>
                    @empty
                        <div class="rounded-3xl border border-dashed border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 text-sm text-[var(--fb-muted)]">
                            Module recommendations will appear here as workspace access and App Store availability evolve.
                        </div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</div>
