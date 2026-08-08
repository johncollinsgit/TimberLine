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
    $channelPulse = is_array($dashboard['channel_pulse'] ?? null) ? $dashboard['channel_pulse'] : null;
@endphp

<div class="mx-auto w-full max-w-[1800px] px-3 pb-4 pt-2 sm:px-4 sm:pb-6 sm:pt-3 md:px-6 min-w-0">
    <div class="space-y-4 min-w-0">
        <header class="eb-dashboard-header">
            <div>
                <h1>Home</h1>
                @if(filled($workspace['label'] ?? null))
                    <p>{{ $workspace['label'] }}</p>
                @endif
            </div>
        </header>

        @if($channelPulse)
            <section class="eb-channel-pulse" aria-label="Channel performance" wire:poll.30s.visible>
                <div class="eb-channel-pulse__context">
                    <a href="{{ $channelPulse['href'] ?? route('sales-channels.index') }}">All channels</a>
                    <label>
                        <span class="sr-only">Time window</span>
                        <select wire:model.live="range" aria-label="Time window">
                            @foreach($rangeOptions as $value => $label)
                                <option value="{{ $value }}">{{ $value === '1d' ? 'Today' : $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="eb-channel-pulse__metrics">
                    @foreach(($channelPulse['metrics'] ?? []) as $metric)
                        <a href="{{ $metric['href'] ?? ($channelPulse['href'] ?? route('sales-channels.index')) }}" class="eb-channel-pulse__metric">
                            <span>{{ $metric['label'] ?? 'Metric' }}</span>
                            <strong>{{ $metric['value'] ?? '—' }}</strong>
                            <small>
                                @if(is_array($metric['trend'] ?? null))
                                    <b class="eb-channel-pulse__trend eb-channel-pulse__trend--{{ $metric['trend']['tone'] ?? 'neutral' }}">{{ $metric['trend']['label'] ?? '' }}</b>
                                @endif
                                @if($metric['live'] ?? false)<i class="eb-channel-pulse__live" aria-hidden="true"></i>@endif
                                {{ $metric['detail'] ?? '' }}
                            </small>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if($workflowHealth)
            <section class="eb-dashboard-notice" aria-labelledby="workflow-health-title">
                <div class="eb-dashboard-notice__copy">
                    <span class="eb-dashboard-notice__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13 2 4.5 13H11l-1 9 8.5-12H12l1-8Z"/></svg>
                    </span>
                    <div>
                        <h2 id="workflow-health-title">Workflow automations</h2>
                        <p>
                            {{ $workflowHealth['active'] }} active of {{ $workflowHealth['total'] }} workflow{{ $workflowHealth['total'] === 1 ? '' : 's' }}.
                            @if(($workflowHealth['needs_attention'] ?? 0) > 0)
                                {{ $workflowHealth['needs_attention'] }} run{{ $workflowHealth['needs_attention'] === 1 ? '' : 's' }} need attention.
                            @elseif($workflowHealth['total'] > 0)
                                Recent runs are healthy.
                            @else
                                Connect Asana and Google Calendar to build your first workflow.
                            @endif
                        </p>
                    </div>
                </div>
                <div class="eb-dashboard-notice__actions">
                    <a href="{{ $workflowHealth['history_href'] }}">Run history</a>
                    <a href="{{ $workflowHealth['href'] }}" class="eb-dashboard-notice__primary">Open automations</a>
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

        <section class="eb-dashboard-panel">
            <div class="eb-dashboard-panel__header">
                <div>
                    <h2>{{ $hero['label'] ?? 'Workspace readiness' }}</h2>
                    <p>{{ $hero['supporting'] ?? '' }}</p>
                </div>
                <a href="{{ $hero['href'] ?? route('dashboard') }}" class="eb-dashboard-kpi-value">{{ $hero['value'] ?? 'Ready' }}</a>
            </div>

            @if($summaryCards !== [])
                <div class="eb-dashboard-metrics">
                    @foreach($summaryCards as $card)
                        <a href="{{ $card['href'] ?? route('dashboard') }}">
                            <span>{{ $card['label'] ?? 'Metric' }}</span>
                            <strong>{{ $card['value'] ?? '0' }}</strong>
                            <small>{{ $card['detail'] ?? '' }}</small>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        @if($upcomingJobs !== [] || $ownerReporting)
            <div class="eb-dashboard-columns">
                <section class="eb-dashboard-panel">
                    <div class="eb-dashboard-panel__header">
                        <div><h2>Next jobs</h2><p>Upcoming incomplete work, address, and assignment.</p></div>
                        <a href="{{ route('field-service.calendar') }}" class="eb-dashboard-text-link">Open calendar</a>
                    </div>
                    <div class="eb-dashboard-rows">
                        @forelse($upcomingJobs as $job)
                            <a href="{{ $job['href'] ?? route('field-service.jobs.show', ['job' => $job['id']]) }}">
                                <div><strong>{{ $job['title'] }}</strong><small>{{ $job['address'] ?: 'Address not set' }} · {{ $job['assigned_to'] ?: 'Unassigned' }}</small></div>
                                <time>{{ filled($job['scheduled_for'] ?? null) ? \Illuminate\Support\Carbon::parse($job['scheduled_for'])->format('M j, g:i A') : 'Unscheduled' }}</time>
                            </a>
                        @empty
                            <div class="eb-dashboard-empty">No upcoming jobs are scheduled.</div>
                        @endforelse
                    </div>
                </section>
                @if($ownerReporting)
                    <a href="{{ route('quickbooks.reports.index', ['tenant' => $dashboard['tenant_slug'], 'range' => $dateRange['key'] ?? '1m']) }}" class="eb-dashboard-panel eb-dashboard-link-panel">
                        <div><h2>Owner reporting</h2><p>Labor, supplies, receivables, comparisons, and sync health.</p></div>
                        <span>Open reporting <span aria-hidden="true">→</span></span>
                    </a>
                @endif
            </div>
        @endif

        <div class="eb-dashboard-columns">
            <section class="eb-dashboard-panel">
                <div class="eb-dashboard-panel__header">
                    <div>
                        <h2>Recommended next actions</h2>
                        <p>Based on your workspace and recent activity.</p>
                    </div>
                </div>

                <div class="eb-dashboard-rows">
                    @foreach($nextActions as $action)
                        <a href="{{ $action['href'] ?? route('dashboard') }}">
                            <div><strong>{{ $action['label'] ?? 'Action' }}</strong><small>{{ $action['description'] ?? '' }}</small></div>
                            <span aria-hidden="true">›</span>
                        </a>
                    @endforeach
                </div>
            </section>

            <section class="eb-dashboard-panel">
                <div class="eb-dashboard-panel__header">
                    <div>
                        <h2>Your branches</h2>
                        <p>Enabled tools and available additions.</p>
                    </div>
                    @if(auth()->user()?->canAccessMarketing())
                        <a href="{{ route('marketing.modules') }}" class="eb-dashboard-text-link">Browse branches</a>
                    @endif
                </div>

                <div class="eb-dashboard-rows">
                    @forelse($pinnedModules as $module)
                        <a href="{{ $module['href'] ?? '#' }}">
                            <div><strong>{{ $module['display_name'] ?? 'Module' }}</strong><small>{{ $module['description'] ?? '' }}</small></div>
                            <span class="eb-dashboard-status">{{ $module['state_label'] ?? 'Module' }}</span>
                        </a>
                    @empty
                        <div class="eb-dashboard-empty">
                            Branch recommendations will appear here as workspace access and catalog availability evolve.
                        </div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</div>
