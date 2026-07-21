@php
    $tenantName = (string) ($tenant->name ?? 'Workspace');
    $team = collect($team ?? []);
    $backHref = ($back ?? '') === 'calendar' ? route('field-service.calendar') : route('field-service.index', ['view' => 'list']);
    $status = $job->operational_status ?: 'needs_details';
    $canProgress = (bool) data_get($capabilities ?? [], 'update_progress', false);
    $canManage = (bool) data_get($capabilities ?? [], 'manage_jobs', false);
    $taskUpdateIds = collect($taskUpdateIds ?? []);
@endphp

<x-layouts::app.sidebar title="Field Service Job">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="border-b border-zinc-200 pb-5">
                <a href="{{ $backHref }}" class="text-sm font-semibold text-emerald-800">← {{ ($back ?? '') === 'calendar' ? 'Back to calendar' : 'Field Service' }}</a>
                <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase text-zinc-500">
                            <span>{{ $tenantName }}</span><span>·</span><span>{{ ucfirst(str_replace('_', ' ', $status)) }}</span><span>·</span><span>{{ ucfirst($job->priority ?: 'normal') }}</span>
                        </div>
                        <h1 class="mt-1 text-3xl font-semibold text-zinc-950">{{ $job->title }}</h1>
                        <p class="mt-1 text-sm text-zinc-600">{{ $job->customer_name ?: 'Customer not named' }}</p>
                        @if($job->equipment)<p class="mt-1 text-sm"><a href="{{ route('field-service.equipment.show', $job->equipment) }}" class="font-semibold text-emerald-800">Equipment: {{ $job->equipment->name }}</a></p>@endif
                    </div>
                    @if($canProgress)
                        <div class="flex flex-wrap gap-2">
                            @if(in_array($status, ['scheduled', 'needs_details'], true))
                                <form method="POST" action="{{ route('field-service.jobs.transitions', $job) }}">@csrf<input type="hidden" name="action" value="start"><button class="fb-btn fb-btn-primary">Start</button></form>
                            @elseif($status === 'active')
                                <form method="POST" action="{{ route('field-service.jobs.transitions', $job) }}" class="flex gap-2"><input type="hidden" name="action" value="block">@csrf<input name="reason" required class="w-48 rounded-lg border border-zinc-300 px-3 text-sm" placeholder="Why is it blocked?"><button class="fb-btn fb-btn-secondary">Block</button></form>
                                <form method="POST" action="{{ route('field-service.jobs.transitions', $job) }}">@csrf<input type="hidden" name="action" value="complete"><button class="fb-btn fb-btn-primary">Complete</button></form>
                            @elseif($status === 'blocked')
                                <form method="POST" action="{{ route('field-service.jobs.transitions', $job) }}">@csrf<input type="hidden" name="action" value="resume"><button class="fb-btn fb-btn-primary">Resume</button></form>
                            @elseif(in_array($status, ['complete', 'canceled'], true) && $canManage)
                                <form method="POST" action="{{ route('field-service.jobs.transitions', $job) }}">@csrf<input type="hidden" name="action" value="reopen"><button class="fb-btn fb-btn-secondary">Reopen</button></form>
                            @endif
                        </div>
                    @endif
                </div>
            </header>

            @if (session('status'))<div class="fb-state fb-state-success">{{ session('status') }}</div>@endif
            @unless(data_get($readiness, 'ready', false))
                <div class="rounded-lg border border-orange-200 bg-orange-50 p-3 text-sm text-orange-900"><strong>Not ready for field:</strong> {{ implode(', ', data_get($readiness, 'missing_labels', [])) }}</div>
            @endunless
            @if($job->blocked_reason)<div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900"><strong>Blocked:</strong> {{ $job->blocked_reason }}</div>@endif

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(320px,.8fr)]">
                <main class="space-y-6">
                    <section class="fb-panel">
                        <div class="fb-panel-head"><div class="fb-panel-title">Overview</div></div>
                        <div class="fb-panel-body grid gap-3 md:grid-cols-2">
                            @if($job->lock_box_code)<div class="rounded-lg border border-amber-200 bg-amber-50 p-3 md:col-span-2"><div class="text-xs font-semibold uppercase text-amber-700">Lock box / gate code</div><div class="mt-1 text-2xl font-bold text-amber-950">{{ $job->lock_box_code }}</div></div>@endif
                            <div class="rounded-lg border border-zinc-200 p-3"><div class="text-xs font-semibold uppercase text-zinc-500">Site</div><div class="mt-1 font-semibold text-zinc-950">{{ $job->service_address_line_1 ?: 'Address needed' }}</div><div class="text-sm text-zinc-600">{{ trim(implode(' ', array_filter([$job->service_city, $job->service_state, $job->service_postal_code]))) }}</div></div>
                            <div class="rounded-lg border border-zinc-200 p-3"><div class="text-xs font-semibold uppercase text-zinc-500">Schedule</div><div class="mt-1 font-semibold text-zinc-950">{{ optional($job->scheduled_for)->format('M j, g:ia') ?: 'Not scheduled' }}</div><div class="text-sm text-zinc-600">{{ $job->assignedUser?->name ?? 'No lead assigned' }}</div></div>
                            <div class="rounded-lg border border-zinc-200 p-3 md:col-span-2"><div class="text-xs font-semibold uppercase text-zinc-500">Work</div><div class="mt-1 whitespace-pre-wrap text-sm text-zinc-700">{{ $job->description ?: 'Description needed' }}</div></div>
                        </div>
                    </section>

                    <section class="fb-panel">
                        <div class="fb-panel-head"><div class="fb-panel-title">Updates</div></div>
                        <div class="fb-panel-body space-y-3">
                            <form method="POST" action="{{ route('field-service.notes.store', $job) }}">@csrf<textarea name="body" required rows="3" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" placeholder="Share an update or @mention a teammate"></textarea><button class="fb-btn fb-btn-primary mt-2">Post update</button></form>
                            @forelse($job->notes->sortByDesc('noted_at') as $note)<article class="border-t border-zinc-200 pt-3"><div class="text-sm font-semibold text-zinc-950">{{ $note->createdBy?->name ?? 'Team update' }} <span class="font-normal text-zinc-500">{{ optional($note->noted_at)->diffForHumans() }}</span></div><div class="mt-1 whitespace-pre-wrap text-sm text-zinc-700">{{ $note->body }}</div></article>@empty<p class="text-sm text-zinc-600">No updates yet.</p>@endforelse
                        </div>
                    </section>
                </main>

                <aside class="space-y-6">
                    <section class="fb-panel"><div class="fb-panel-head"><div class="fb-panel-title">Tasks</div></div><div class="fb-panel-body space-y-3">
                        @if(data_get($capabilities, 'create_task'))
                            <form method="POST" action="{{ route('field-service.tasks.store', $job) }}" class="space-y-2">@csrf
                                <input name="title" required class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" placeholder="Add task">
                                <div class="grid gap-2 sm:grid-cols-2">
                                    @if($canManage)
                                        <label class="text-xs font-semibold text-zinc-600">Assigned people<select name="assignee_ids[]" multiple class="mt-1 min-h-24 w-full rounded-lg border border-zinc-300 px-2 py-2 text-sm">@foreach($team as $member)<option value="{{ $member->id }}">{{ $member->name ?: $member->email }}</option>@endforeach</select></label>
                                    @else
                                        <input type="hidden" name="assignee_ids[]" value="{{ auth()->id() }}">
                                    @endif
                                    <label class="text-xs font-semibold text-zinc-600">Due<input name="due_at" type="datetime-local" class="mt-1 w-full rounded-lg border border-zinc-300 px-2 py-2 text-sm"></label>
                                </div>
                                <button class="fb-btn fb-btn-secondary w-full justify-center">Add task</button>
                            </form>
                        @endif
                        @forelse($job->tasks->sortBy('sort_order') as $task)
                            <div class="rounded-lg border border-zinc-200 p-3">
                                <div class="flex items-start justify-between gap-3"><div><div class="font-semibold text-zinc-950">{{ $task->title }}</div><div class="mt-1 text-xs text-zinc-500">{{ $task->assignees->map(fn($member) => $member->name ?: $member->email)->join(', ') ?: ($task->assignedUser?->name ?? 'Unassigned') }}@if($task->due_at) · {{ $task->due_at->format('M j') }}@endif</div></div><span class="rounded-full bg-zinc-100 px-2 py-1 text-[11px] font-semibold text-zinc-700">{{ ucfirst(str_replace('_', ' ', $task->status)) }}</span></div>
                                @if($taskUpdateIds->contains((int) $task->id))
                                    <div class="mt-3 grid gap-2 border-t border-zinc-100 pt-3">
                                        <form method="POST" action="{{ route('field-service.tasks.update', [$job, $task]) }}" class="flex gap-2">@csrf @method('PATCH')<select name="status" class="min-h-11 flex-1 rounded-lg border border-zinc-300 px-2 text-sm">@foreach(['open' => 'Open', 'in_progress' => 'In progress', 'waiting' => 'Waiting', 'done' => 'Done'] as $value => $label)<option value="{{ $value }}" @selected($task->status === $value)>{{ $label }}</option>@endforeach</select><button class="fb-btn fb-btn-secondary">Save</button></form>
                                        @if($task->status !== 'done')<details><summary class="cursor-pointer py-2 text-sm font-semibold text-emerald-800">Hand off next step</summary><form method="POST" action="{{ route('field-service.tasks.handoff', [$job, $task]) }}" class="mt-2 space-y-2">@csrf<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><select name="assignee_ids[]" multiple required class="min-h-24 w-full rounded-lg border border-zinc-300 px-2 py-2 text-sm">@foreach($team as $member)<option value="{{ $member->id }}">{{ $member->name ?: $member->email }}</option>@endforeach</select><textarea name="note" rows="2" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" placeholder="What are they waiting on?"></textarea><button class="fb-btn fb-btn-primary w-full justify-center">Hand off and mark waiting</button></form></details>@endif
                                    </div>
                                @endif
                            </div>
                        @empty<p class="text-sm text-zinc-600">No tasks yet.</p>@endforelse
                    </div></section>
                    <section class="fb-panel"><div class="fb-panel-head"><div class="fb-panel-title">Files</div></div><div class="fb-panel-body"><div class="grid grid-cols-3 gap-2">@foreach($job->photos as $photo)<a href="{{ $photo->file_path }}" class="aspect-square overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50"><img src="{{ $photo->file_path }}" alt="{{ $photo->caption ?: 'Job photo' }}" class="h-full w-full object-cover"></a>@endforeach</div>@if($job->photos->isEmpty())<p class="text-sm text-zinc-600">No photos yet. Add them from the Everbranch app.</p>@endif</div></section>
                </aside>
            </div>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
