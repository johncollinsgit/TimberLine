@php
    $tenantName = (string) ($tenant->name ?? 'Workspace');
    $team = collect($team ?? []);
    $backHref = ($back ?? '') === 'calendar' ? route('field-service.calendar') : route('field-service.index');
    $backLabel = ($back ?? '') === 'calendar' ? 'Back to calendar' : 'Back to jobs';
@endphp

<x-layouts::app.sidebar title="Field Service Job">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="fb-workflow-header">
                <div class="fb-eyebrow">{{ $tenantName }} Work</div>
                <h1 class="fb-title-xl">{{ $job->title }}</h1>
                <p class="fb-subtitle">{{ $job->customer_name ?: 'Customer not named' }} @if($job->customer_phone) · {{ $job->customer_phone }} @endif</p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <a href="{{ $backHref }}" class="fb-btn fb-btn-secondary">{{ $backLabel }}</a>
                    <a href="{{ route('field-service.calendar') }}" class="fb-btn fb-btn-secondary">Open calendar</a>
                </div>

                @if (session('status'))
                    <div class="fb-state fb-state-success mt-4">{{ session('status') }}</div>
                @endif
            </header>

            <div class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                <section class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Job details</div>
                            <div class="fb-panel-copy">Address, access, assignment, and work description.</div>
                        </div>
                    </div>
                    <div class="fb-panel-body space-y-4">
                        @if($job->lock_box_code)
                            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-700">Lock box / gate code</div>
                                <div class="mt-1 text-2xl font-bold text-amber-950">{{ $job->lock_box_code }}</div>
                            </div>
                        @endif

                        <div class="grid gap-3 md:grid-cols-2">
                            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
                                <div class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Address</div>
                                <div class="mt-1 text-sm font-semibold text-zinc-950">{{ $job->service_address_line_1 ?: 'No address yet' }}</div>
                                <div class="text-sm text-zinc-600">{{ trim(implode(' ', array_filter([$job->service_city, $job->service_state, $job->service_postal_code]))) }}</div>
                            </div>
                            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
                                <div class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Schedule</div>
                                <div class="mt-1 text-sm font-semibold text-zinc-950">{{ optional($job->scheduled_for)->format('M j, g:ia') ?: 'Not scheduled' }}</div>
                                <div class="text-sm text-zinc-600">{{ $job->assignedUser?->name ?? 'No one assigned' }}</div>
                            </div>
                        </div>

                        @if($job->description)
                            <div class="rounded-xl border border-zinc-200 bg-white p-4 text-sm text-zinc-700">{{ $job->description }}</div>
                        @endif

                        <div class="grid gap-4 md:grid-cols-2">
                            <form method="POST" action="{{ route('field-service.notes.store', ['job' => $job]) }}" class="rounded-xl border border-zinc-200 bg-white p-4">
                                @csrf
                                <label class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Add work update</label>
                                <textarea name="body" required rows="3" class="mt-2 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" placeholder="What changed on the job?"></textarea>
                                <select name="status_update" class="mt-2 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                                    <option value="">No status change</option>
                                    @foreach(['scheduled', 'in_progress', 'blocked', 'done'] as $status)
                                        <option value="{{ $status }}">{{ $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status)) }}</option>
                                    @endforeach
                                </select>
                                <input name="photo_file_path" class="mt-2 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" placeholder="Optional photo/file URL">
                                <button type="submit" class="fb-btn fb-btn-secondary mt-3 w-full justify-center">Save update</button>
                            </form>

                            <form method="POST" action="{{ route('field-service.tasks.store', ['job' => $job]) }}" class="rounded-xl border border-zinc-200 bg-white p-4">
                                @csrf
                                <label class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Assign task</label>
                                <input name="title" required class="mt-2 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" placeholder="Task">
                                <select name="assigned_user_id" class="mt-2 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                                    <option value="">No one yet</option>
                                    @foreach($team as $member)
                                        <option value="{{ $member->id }}">{{ $member->name ?: $member->email }}</option>
                                    @endforeach
                                </select>
                                <input name="due_at" type="datetime-local" class="mt-2 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                                <button type="submit" class="fb-btn fb-btn-secondary mt-3 w-full justify-center">Add task</button>
                            </form>
                        </div>
                    </div>
                </section>

                <section class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Activity</div>
                            <div class="fb-panel-copy">Employee updates, photos, tasks, and materials.</div>
                        </div>
                    </div>
                    <div class="fb-panel-body space-y-4">
                        <div class="space-y-2">
                            @forelse($job->notes->sortByDesc('noted_at') as $note)
                                <div class="rounded-xl border border-zinc-200 bg-white p-3">
                                    <div class="text-sm font-semibold text-zinc-950">{{ $note->createdBy?->name ?? 'Team update' }} @if($note->status_update) · {{ $statusLabels[$note->status_update] ?? $note->status_update }} @endif</div>
                                    <div class="mt-1 text-sm text-zinc-700">{{ $note->body }}</div>
                                    @foreach($note->photos as $photo)
                                        <a href="{{ $photo->file_path }}" class="mt-2 block text-xs font-semibold text-emerald-700 underline">{{ $photo->caption ?: 'Photo/file link' }}</a>
                                    @endforeach
                                </div>
                            @empty
                                <p class="text-sm text-zinc-600">No updates yet.</p>
                            @endforelse
                        </div>

                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
                            <div class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Tasks</div>
                            <div class="mt-2 space-y-2">
                                @foreach($job->tasks as $task)
                                    <div class="rounded-lg bg-white px-3 py-2 text-sm text-zinc-700">{{ $task->title }} @if($task->assignedUser) · {{ $task->assignedUser->name }} @endif</div>
                                @endforeach
                            </div>
                        </div>

                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
                            <div class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Materials</div>
                            <div class="mt-2 space-y-2">
                                @foreach($job->materials as $material)
                                    <div class="rounded-lg bg-white px-3 py-2 text-sm text-zinc-700">{{ $material->name }} · {{ $material->quantity }} {{ $material->unit }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
