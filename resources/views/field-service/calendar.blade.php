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

            <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
                <section>
                    <div class="mb-3 flex items-end justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-zinc-950">Upcoming schedule</h2>
                            <p class="text-sm text-zinc-600">Next 45 days</p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        @forelse($jobsByDay as $day => $jobs)
                            <section class="border-t border-zinc-200 pt-3">
                                <div class="mb-2 text-xs font-semibold uppercase text-zinc-500">
                                    {{ \Carbon\CarbonImmutable::parse($day)->format('l, M j') }}
                                </div>
                                <div class="space-y-2">
                                    @foreach($jobs as $job)
                                        @php($ready = data_get($readiness, $job->id.'.ready', false))
                                        <a href="{{ route('field-service.jobs.show', ['job' => $job, 'back' => 'calendar']) }}" class="grid gap-3 rounded-lg border border-zinc-200 bg-white p-3 transition hover:border-emerald-400 md:grid-cols-[90px_1fr_auto]">
                                            <div class="text-sm font-semibold text-zinc-950">{{ optional($job->scheduled_for)->format('g:ia') }}</div>
                                            <div class="min-w-0">
                                                <div class="truncate font-semibold text-zinc-950">{{ $job->title }}</div>
                                                <div class="truncate text-sm text-zinc-600">{{ $job->customer_name ?: 'Customer needed' }} · {{ trim(implode(', ', array_filter([$job->service_address_line_1, $job->service_city]))) ?: 'Site needed' }}</div>
                                                <div class="mt-1 text-xs text-zinc-500">{{ $job->assignedUser?->name ?? 'Unassigned' }}@if($job->participants->count() > 1) +{{ $job->participants->count() - 1 }}@endif</div>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                @unless($ready)<span class="rounded-full border border-orange-200 bg-orange-50 px-2 py-1 text-xs font-semibold text-orange-800">Missing details</span>@endunless
                                                <span class="rounded-full border px-2 py-1 text-xs font-semibold {{ $statusClass($job->operational_status) }}">{{ $statusLabels[$job->operational_status] ?? ucfirst(str_replace('_', ' ', $job->operational_status ?: 'needs_details')) }}</span>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            </section>
                        @empty
                            <div class="rounded-lg border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-600">No scheduled {{ strtolower($jobLabel) }}s yet.</div>
                        @endforelse
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
                                        <div class="rounded-lg border border-dashed border-zinc-300 p-3 text-sm text-zinc-600">No company-calendar events in the next 45 days.</div>
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
