@php
    $tenantName = (string) ($tenant->name ?? 'Workspace');
    $jobsByDay = collect($jobsByDay ?? []);
@endphp

<x-layouts::app.sidebar title="Field Service Calendar">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="fb-workflow-header">
                <div class="fb-eyebrow">Field Service</div>
                <h1 class="fb-title-xl">{{ $tenantName }} Calendar</h1>
                <p class="fb-subtitle">Upcoming jobs grouped by day. Open a job to review access, notes, tasks, photos, and materials.</p>
                <div class="mt-4">
                    <a href="{{ route('field-service.index') }}" class="fb-btn fb-btn-secondary">Back to jobs</a>
                </div>
            </header>

            <section class="fb-panel">
                <div class="fb-panel-head">
                    <div>
                        <div class="fb-panel-title">Upcoming jobs</div>
                        <div class="fb-panel-copy">The next 45 days of scheduled work.</div>
                    </div>
                </div>
                <div class="fb-panel-body space-y-4">
                    @forelse($jobsByDay as $day => $jobs)
                        <div class="rounded-2xl border border-zinc-200 bg-white p-4">
                            <div class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">
                                {{ \Carbon\CarbonImmutable::parse($day)->format('l, M j') }}
                            </div>
                            <div class="mt-3 space-y-2">
                                @foreach($jobs as $job)
                                    <a href="{{ route('field-service.jobs.show', ['job' => $job, 'back' => 'calendar']) }}" class="block rounded-xl border border-zinc-200 bg-zinc-50 p-4 transition hover:border-emerald-300 hover:bg-emerald-50">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <div class="font-semibold text-zinc-950">{{ $job->title }}</div>
                                                <div class="mt-1 text-sm text-zinc-600">{{ $job->customer_name ?: 'Customer not named' }}</div>
                                                <div class="mt-1 text-xs text-zinc-500">{{ trim(implode(', ', array_filter([$job->service_address_line_1, $job->service_city, $job->service_state]))) }}</div>
                                            </div>
                                            <div class="text-right text-sm text-zinc-600">
                                                <div class="font-semibold text-zinc-950">{{ optional($job->scheduled_for)->format('g:ia') }}</div>
                                                <div>{{ $statusLabels[$job->status] ?? ucfirst(str_replace('_', ' ', (string) $job->status)) }}</div>
                                            </div>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center">
                            <h2 class="text-lg font-semibold text-zinc-950">No upcoming jobs yet</h2>
                            <p class="mt-2 text-sm text-zinc-600">Schedule a job and it will show up here for the team.</p>
                        </div>
                    @endforelse
                </div>
            </section>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
