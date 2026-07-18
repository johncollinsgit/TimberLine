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
    $frontYardLaunch = is_array($dashboard['front_yard_launch'] ?? null) ? $dashboard['front_yard_launch'] : null;
    $workflowHealth = is_array($dashboard['workflow_automation_health'] ?? null) ? $dashboard['workflow_automation_health'] : null;
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

        @if($workflowHealth)
            <section class="overflow-hidden rounded-3xl border border-emerald-200/70 bg-gradient-to-br from-[#fffaf0] via-white to-sky-50 shadow-sm" aria-labelledby="workflow-health-title">
                <div class="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                    <div class="flex min-w-0 items-start gap-4">
                        <span class="inline-flex size-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-900 text-white shadow-sm" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-6"><path d="M13 2 4.5 13H11l-1 9 8.5-12H12l1-8Z"/></svg>
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-emerald-800">Order Calendar health</p>
                            <h2 id="workflow-health-title" class="mt-1 text-xl font-semibold text-zinc-950">
                                {{ $workflowHealth['active'] }} active of {{ $workflowHealth['total'] }} workflow{{ $workflowHealth['total'] === 1 ? '' : 's' }}
                            </h2>
                            <p class="mt-1 text-sm text-zinc-600">
                                @if(($workflowHealth['needs_attention'] ?? 0) > 0)
                                    {{ $workflowHealth['needs_attention'] }} run{{ $workflowHealth['needs_attention'] === 1 ? '' : 's' }} need attention from the last 7 days.
                                @elseif($workflowHealth['total'] > 0)
                                    Your recent workflow runs are healthy.
                                @else
                                    Connect Asana and Google Calendar to build your first workflow.
                                @endif
                            </p>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <a href="{{ $workflowHealth['history_href'] }}" class="rounded-full border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-zinc-800 transition hover:border-zinc-300">Run history</a>
                        <a href="{{ $workflowHealth['href'] }}" class="rounded-full bg-emerald-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-800">Open automations</a>
                    </div>
                </div>
            </section>
        @endif

        @if($frontYardLaunch)
            <section class="overflow-hidden rounded-[2.25rem] border border-emerald-100 bg-[#fbf6e6] shadow-sm">
                <div class="relative bg-gradient-to-br from-[#f9f3dc] via-white to-[#d8efe0] p-5 sm:p-8">
                    <div class="absolute right-8 top-8 hidden size-28 rounded-full bg-[#e6b84d]/20 blur-2xl sm:block"></div>
                    <div class="relative grid gap-6 xl:grid-cols-[1.05fr_0.95fr]">
                        <div>
                            <div class="inline-flex items-center rounded-full border border-white/80 bg-white/75 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-emerald-900 shadow-sm">{{ $frontYardLaunch['brand']['name'] ?? 'Front Yard Foods' }}</div>
                            <h1 class="mt-4 text-3xl font-semibold tracking-tight text-zinc-950 sm:text-5xl">{{ $frontYardLaunch['headline'] ?? 'Welcome' }}</h1>
                            <p class="mt-4 max-w-3xl text-sm leading-7 text-zinc-700 sm:text-base">{{ $frontYardLaunch['subheadline'] ?? '' }}</p>
                            <p class="mt-3 max-w-3xl text-sm leading-6 text-zinc-600">{{ $frontYardLaunch['explain'] ?? '' }}</p>
                            <div class="mt-5 flex flex-wrap gap-3">
                                @if($frontYardLaunch['events_href'] ?? null)<a href="{{ $frontYardLaunch['events_href'] }}" class="rounded-full bg-emerald-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">Open Events & Classes</a>@endif
                                @if($frontYardLaunch['inventory_href'] ?? null)<a href="{{ $frontYardLaunch['inventory_href'] }}" class="rounded-full border border-emerald-200 bg-white/80 px-4 py-2 text-sm font-semibold text-emerald-900 shadow-sm">Open Plant Inventory</a>@endif
                                @if($frontYardLaunch['agreement_href'] ?? null)<a href="{{ $frontYardLaunch['agreement_href'] }}" class="rounded-full border border-zinc-200 bg-white/80 px-4 py-2 text-sm font-semibold text-zinc-800 shadow-sm">View signed agreement</a>@endif
                            </div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
                            @foreach(($frontYardLaunch['statuses'] ?? []) as $status)
                                <div class="rounded-[1.5rem] border border-white/70 bg-white/80 p-4 shadow-sm">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ $status['label'] ?? 'Status' }}</p>
                                    <p class="mt-2 text-lg font-semibold capitalize text-zinc-950">{{ $status['value'] ?? 'pending' }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 bg-white/70 p-5 sm:p-6 xl:grid-cols-3">
                    <div class="rounded-[1.5rem] border border-zinc-200 bg-white p-5 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-950">What Evergrove is doing</h2>
                        <ul class="mt-4 space-y-3 text-sm leading-6 text-zinc-700">
                            @foreach(($frontYardLaunch['evergrove_doing'] ?? []) as $item)
                                <li class="flex gap-3"><span class="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-900">✓</span><span>{{ $item }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="rounded-[1.5rem] border border-zinc-200 bg-white p-5 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-950">What I need from you</h2>
                        <ul class="mt-4 space-y-3 text-sm leading-6 text-zinc-700">
                            @foreach(($frontYardLaunch['client_needs'] ?? []) as $item)
                                <li class="flex gap-3"><span class="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-900">•</span><span>{{ $item }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="rounded-[1.5rem] border border-emerald-100 bg-emerald-50 p-5 shadow-sm">
                        <h2 class="text-lg font-semibold text-emerald-950">How your data is used</h2>
                        <ul class="mt-4 space-y-3 text-sm leading-6 text-emerald-950">
                            @foreach(($frontYardLaunch['data_assurance'] ?? []) as $item)
                                <li class="flex gap-3"><span class="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold text-emerald-900">✓</span><span>{{ $item }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </section>
        @endif

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
