@php
    $tenantName = (string) ($tenant->name ?? 'Workspace');
    $jobsByDay = collect($jobsByDay ?? []);
    $unscheduled = collect($unscheduled ?? []);
    $readiness = collect($readiness ?? []);
    $jobLabel = data_get($profile ?? [], 'labels.item', 'Job');
    $statusClass = fn (?string $status): string => match ($status) {
        'active' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'scheduled' => 'border-sky-200 bg-sky-50 text-sky-800',
        'blocked' => 'border-rose-200 bg-rose-50 text-rose-800',
        'quote' => 'border-amber-200 bg-amber-50 text-amber-800',
        'complete' => 'border-zinc-200 bg-zinc-100 text-zinc-700',
        default => 'border-orange-200 bg-orange-50 text-orange-800',
    };
    $googleCalendar = (array) ($googleCalendar ?? []);
    $googleEventsByDay = collect($googleEventsByDay ?? []);
    $calendarDays = collect($calendarDays ?? []);
@endphp

<x-layouts::app.sidebar title="Field Service">
    <flux:main>
        <div class="fb-workflow-shell fb-workflow-shell--wide">
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-zinc-200 pb-5">
                <div>
                    <div class="fb-eyebrow">{{ $tenantName }}</div>
                    <h1 class="text-3xl font-semibold text-zinc-950">Field Service</h1>
                </div>
                <nav class="inline-flex rounded-lg border border-zinc-200 bg-zinc-50 p-1" aria-label="Field Service view">
                    <a href="{{ route('field-service.calendar') }}" class="rounded-md bg-white px-4 py-2 text-sm font-semibold text-zinc-950 shadow-sm">Calendar</a>
                    <a href="{{ route('field-service.index', ['view' => 'list']) }}" class="rounded-md px-4 py-2 text-sm font-semibold text-zinc-600">List</a>
                    @if($equipmentMaintenanceEnabled ?? false)<a href="{{ route('field-service.equipment.index') }}" class="rounded-md px-4 py-2 text-sm font-semibold text-zinc-600">Equipment</a>@endif
                    <a href="{{ route('field-service.payroll-hours') }}" class="rounded-md px-4 py-2 text-sm font-semibold text-zinc-600">Payroll hours</a>
                </nav>
            </header>

            @if (session('status'))
                <div class="fb-state fb-state-success">{{ session('status') }}</div>
            @endif

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
                <section>
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-semibold text-zinc-950">{{ $calendarMonth->format('F Y') }}</h2>
                            <p class="text-sm text-zinc-600">Scheduled {{ strtolower($jobLabel) }}s and company-calendar events</p>
                        </div>
                        <div class="inline-flex items-center rounded-lg border border-zinc-200 bg-white p-1 shadow-sm">
                            <a href="{{ route('field-service.calendar', ['month' => $calendarMonth->subMonth()->format('Y-m')]) }}" class="rounded-md px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100" aria-label="Previous month">‹</a>
                            <a href="{{ route('field-service.calendar') }}" class="rounded-md px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100">Today</a>
                            <a href="{{ route('field-service.calendar', ['month' => $calendarMonth->addMonth()->format('Y-m')]) }}" class="rounded-md px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100" aria-label="Next month">›</a>
                        </div>
                    </div>
                    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white shadow-sm">
                        <div class="min-w-[760px]">
                            <div class="grid grid-cols-7 border-b border-zinc-200 bg-zinc-50 text-center text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-500">
                                @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday)
                                    <div class="px-2 py-3">{{ $weekday }}</div>
                                @endforeach
                            </div>
                            <div class="grid grid-cols-7">
                                @foreach($calendarDays as $day)
                                    @php
                                        $date = $day->toDateString();
                                        $dayJobs = collect($jobsByDay->get($date, []));
                                        $dayGoogleEvents = collect($googleEventsByDay->get($date, []));
                                        $isCurrentMonth = $day->isSameMonth($calendarMonth);
                                        $isToday = $date === $today;
                                    @endphp
                                    <section class="min-h-36 border-b border-r border-zinc-200 p-2 {{ $isCurrentMonth ? 'bg-white' : 'bg-zinc-50/70' }}">
                                        <div class="mb-2 flex items-center justify-between">
                                            <span class="inline-flex h-7 w-7 items-center justify-center rounded-full text-sm font-semibold {{ $isToday ? 'bg-emerald-700 text-white' : ($isCurrentMonth ? 'text-zinc-900' : 'text-zinc-400') }}">{{ $day->day }}</span>
                                            @if($dayJobs->isNotEmpty() || $dayGoogleEvents->isNotEmpty())
                                                <span class="text-[10px] font-semibold text-zinc-400">{{ $dayJobs->count() + $dayGoogleEvents->count() }}</span>
                                            @endif
                                        </div>
                                        <div class="space-y-1.5">
                                            @foreach($dayJobs as $job)
                                                @php($ready = data_get($readiness, $job->id.'.ready', false))
                                                <a href="{{ route('field-service.jobs.show', ['job' => $job, 'back' => 'calendar']) }}" class="block rounded-md border px-2 py-1.5 text-xs transition hover:brightness-95 {{ $statusClass($job->operational_status) }}" title="{{ $job->title }}">
                                                    <span class="font-semibold">{{ optional($job->scheduled_for)->format('g:ia') }}</span>
                                                    <span class="block truncate">{{ $job->title }}</span>
                                                    @unless($ready)<span class="block truncate opacity-75">Missing details</span>@endunless
                                                </a>
                                            @endforeach
                                            @foreach($dayGoogleEvents as $event)
                                                <a @if(!empty($event['url'])) href="{{ $event['url'] }}" target="_blank" rel="noreferrer" @endif class="block rounded-md border border-violet-200 bg-violet-50 px-2 py-1.5 text-xs text-violet-900 transition hover:bg-violet-100" title="{{ $event['summary'] ?? 'Company calendar event' }}">
                                                    <span class="font-semibold">{{ !empty($event['start']) ? \Carbon\CarbonImmutable::parse($event['start'])->format('g:ia') : 'All day' }}</span>
                                                    <span class="block truncate">{{ $event['summary'] ?? 'Company calendar event' }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    </section>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </section>

                <aside>
                    <div class="sticky top-4">
                        <div class="mb-3 flex items-end justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-zinc-950">Needs scheduling</h2>
                                <p class="text-sm text-zinc-600">{{ $unscheduled->count() }} current</p>
                            </div>
                        </div>
                        <div class="space-y-2">
                            @forelse($unscheduled as $job)
                                <a href="{{ route('field-service.jobs.show', ['job' => $job, 'back' => 'calendar']) }}" class="block rounded-lg border border-zinc-200 bg-white p-3 transition hover:border-emerald-400">
                                    <div class="font-semibold text-zinc-950">{{ $job->title }}</div>
                                    <div class="mt-1 text-sm text-zinc-600">{{ $job->customer_name ?: 'Customer needed' }}</div>
                                    <div class="mt-2 text-xs text-orange-800">{{ implode(' · ', data_get($readiness, $job->id.'.missing_labels', ['Schedule needed'])) }}</div>
                                </a>
                            @empty
                                <div class="rounded-lg border border-dashed border-zinc-300 p-5 text-sm text-zinc-600">Everything current is scheduled.</div>
                            @endforelse
                        </div>
                        <div class="mt-6 border-t border-zinc-200 pt-5">
                            <div class="flex items-end justify-between gap-3">
                                <div>
                                    <h2 class="text-lg font-semibold text-zinc-950">Google Calendar</h2>
                                    <p class="mt-1 text-sm text-zinc-600">Read-only events from the connected company calendar.</p>
                                </div>
                                @if(!($googleCalendar['available'] ?? false))
                                    <a href="{{ route('marketing.providers-integrations') }}" class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">Set up</a>
                                @elseif(!($googleCalendar['connected'] ?? false))
                                    <a href="{{ route('workflows.connections') }}" class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">Connect</a>
                                @endif
                            </div>
                            @if(($googleCalendar['error'] ?? null) !== null)
                                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{ $googleCalendar['error'] }}</div>
                            @elseif(!($googleCalendar['available'] ?? false))
                                <div class="mt-3 rounded-lg border border-dashed border-zinc-300 p-3 text-sm text-zinc-600">Calendar connection is available through Workflow Automations.</div>
                            @elseif(!($googleCalendar['connected'] ?? false))
                                <div class="mt-3 rounded-lg border border-dashed border-zinc-300 p-3 text-sm text-zinc-600">Connect the company Google Calendar to show its events alongside jobs.</div>
                            @else
                                <div class="mt-3 space-y-2">
                                    @forelse((array) ($googleCalendar['events'] ?? []) as $event)
                                        <a @if(!empty($event['url'])) href="{{ $event['url'] }}" target="_blank" rel="noreferrer" @endif class="block rounded-lg border border-zinc-200 bg-white p-3 transition hover:border-emerald-400">
                                            <div class="font-semibold text-zinc-950">{{ $event['summary'] }}</div>
                                            <div class="mt-1 text-xs text-zinc-600">{{ !empty($event['start']) ? \Carbon\CarbonImmutable::parse($event['start'])->format('M j, g:ia') : 'All day' }}@if(!empty($event['location'])) · {{ $event['location'] }}@endif</div>
                                        </a>
                                    @empty
                                        <div class="rounded-lg border border-dashed border-zinc-300 p-3 text-sm text-zinc-600">No company-calendar events this month.</div>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
