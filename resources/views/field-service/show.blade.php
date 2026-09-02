@php
    $tenantName = (string) ($tenant->name ?? 'Workspace');
    $team = collect($team ?? []);
    $backHref = ($back ?? '') === 'calendar' ? route('field-service.calendar') : route('field-service.index', ['view' => 'list']);
    $status = $job->operational_status ?: 'needs_details';
    $canProgress = (bool) data_get($capabilities ?? [], 'update_progress', false);
    $canManage = (bool) data_get($capabilities ?? [], 'manage_jobs', false);
    $taskUpdateIds = collect($taskUpdateIds ?? []);
    $officeTeam = $team->filter(fn ($member) => in_array(strtolower((string) $member->pivot?->role), ['owner', 'tenant_owner', 'admin', 'manager'], true));
    $siteAddress = trim(implode(', ', array_filter([$job->service_address_line_1, $job->service_address_line_2, $job->service_city, $job->service_state, $job->service_postal_code, $job->service_country])));
    $fictionalRoute = (array) data_get($job->metadata, 'fictional_route', []);
    $fictionalRoutePoints = collect((array) data_get($fictionalRoute, 'points', []))->filter(fn ($point) => is_numeric(data_get($point, 'x')) && is_numeric(data_get($point, 'y')))->values();
    $fictionalRoutePolyline = $fictionalRoutePoints->map(fn ($point) => data_get($point, 'x').' '.data_get($point, 'y'))->join(' ');
    $fictionalFinance = $job->financialDocuments->filter(fn ($document) => (bool) data_get($document->metadata, 'fictional_demo'));
    $fictionalMoneyIn = (float) $fictionalFinance->whereIn('document_type', ['invoice', 'estimate'])->sum('total_amount');
    $fictionalMoneySpent = (float) $fictionalFinance->where('document_type', 'expense')->sum('total_amount');
    $updateAttachmentsByNote = $job->assets
        ->filter(fn ($asset) => filled(data_get($asset->metadata, 'field_service_job_note_id')))
        ->groupBy(fn ($asset) => (int) data_get($asset->metadata, 'field_service_job_note_id'));
    $uploadedImages = $job->assets->filter(fn ($asset) => str_starts_with((string) $asset->mime_type, 'image/'));
@endphp

<x-layouts::app.sidebar title="Field Service Job">
    <flux:main>
        <div class="fb-workflow-shell fb-workflow-shell--wide field-service-job-shell">
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
                            @if(!in_array($status, ['complete', 'canceled', 'history'], true))
                                <form method="POST" action="{{ route('field-service.jobs.transitions', $job) }}" onsubmit="return confirm('Complete and archive this job? It will remain searchable in job history.');">@csrf<input type="hidden" name="action" value="complete"><button class="fb-btn fb-btn-primary">Complete &amp; archive</button></form>
                            @endif
                            @if(in_array($status, ['scheduled', 'needs_details'], true))
                                <form method="POST" action="{{ route('field-service.jobs.transitions', $job) }}">@csrf<input type="hidden" name="action" value="start"><button class="fb-btn fb-btn-primary">Start</button></form>
                            @elseif(in_array($status, ['complete', 'canceled'], true) && $canManage)
                                <form method="POST" action="{{ route('field-service.jobs.transitions', $job) }}">@csrf<input type="hidden" name="action" value="reopen"><button class="fb-btn fb-btn-secondary">Reopen</button></form>
                            @endif
                            @if($canManage && !in_array($status, ['complete', 'canceled', 'history'], true))
                                <form method="POST" action="{{ route('field-service.jobs.transitions', $job) }}" onsubmit="return confirm('Delete this job from active work? It will be retained in searchable job history.');">@csrf<input type="hidden" name="action" value="archive"><button class="fb-btn fb-btn-secondary border-rose-300 text-rose-800 hover:border-rose-400 hover:bg-rose-50">Delete job</button></form>
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
                            <div class="rounded-lg border border-zinc-200 p-3"><div class="text-xs font-semibold uppercase text-zinc-500">Site</div><div class="mt-1 font-semibold text-zinc-950">{{ $job->service_address_line_1 ?: 'Address needed' }}</div><div class="text-sm text-zinc-600">{{ trim(implode(' ', array_filter([$job->service_city, $job->service_state, $job->service_postal_code]))) }}</div>@if($siteAddress)<div class="mt-2 flex gap-3 text-sm font-semibold"><a class="text-emerald-800" href="https://maps.apple.com/?q={{ urlencode($siteAddress) }}">Apple Maps</a><a class="text-emerald-800" href="https://www.google.com/maps/search/?api=1&query={{ urlencode($siteAddress) }}">Google Maps</a></div>@endif</div>
                            <div class="rounded-lg border border-zinc-200 p-3"><div class="text-xs font-semibold uppercase text-zinc-500">Schedule</div><div class="mt-1 font-semibold text-zinc-950">{{ optional($job->scheduled_for)->format('M j, g:ia') ?: 'Not scheduled' }}</div><div class="text-sm text-zinc-600">{{ $job->assignedUser?->name ?? 'No lead assigned' }}</div></div>
                            @if($job->project_manager_name || $job->project_manager_phone || $job->project_manager_email)<div class="rounded-lg border border-zinc-200 p-3 md:col-span-2"><div class="text-xs font-semibold uppercase text-zinc-500">Project Manager</div><div class="mt-1 font-semibold text-zinc-950">{{ $job->project_manager_name ?: 'Not named' }}@if($job->project_manager_company) · {{ $job->project_manager_company }}@endif</div><div class="mt-2 flex flex-wrap gap-3 text-sm">@if($job->project_manager_phone)<a class="font-semibold text-emerald-800" href="tel:{{ $job->project_manager_phone }}">Call {{ $job->project_manager_phone }}</a><a class="font-semibold text-emerald-800" href="sms:{{ $job->project_manager_phone }}">Text</a>@endif @if($job->project_manager_email)<a class="font-semibold text-emerald-800" href="mailto:{{ $job->project_manager_email }}">Email</a>@endif</div></div>@endif
                            <div class="rounded-lg border border-zinc-200 p-3 md:col-span-2"><div class="text-xs font-semibold uppercase text-zinc-500">Work</div><div class="mt-1 whitespace-pre-wrap text-sm text-zinc-700">{{ $job->description ?: 'Description needed' }}</div></div>
                            @if($canManage)
                                <details id="job-details" class="rounded-lg border border-zinc-200 p-3 md:col-span-2" @if(request()->boolean('edit')) open @endif>
                                    <summary class="cursor-pointer font-semibold text-emerald-800">Edit job details</summary>
                                    <form method="POST" action="{{ route('field-service.jobs.details.update', $job) }}" class="mt-4 grid gap-3 md:grid-cols-2">@csrf
                                        <label class="text-sm font-semibold text-zinc-700 md:col-span-2">Work description<textarea name="description" rows="4" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-normal" placeholder="Describe the work to be done">{{ old('description', $job->description) }}</textarea></label>
                                        <label class="relative text-sm font-semibold text-zinc-700 md:col-span-2" data-address-autocomplete>
                                            Service address
                                            <input name="service_address_line_1" value="{{ old('service_address_line_1', $job->service_address_line_1) }}" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-normal" placeholder="Start typing an address" autocomplete="street-address" aria-autocomplete="list" aria-expanded="false">
                                            <span class="mt-1 block text-xs font-normal text-zinc-500" data-address-status>Choose a suggested address to fill city, state, postal code, and country.</span>
                                            <div class="absolute z-20 mt-1 hidden w-full overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg" data-address-suggestions role="listbox"></div>
                                        </label>
                                        <label class="text-sm font-semibold text-zinc-700 md:col-span-2">Suite, unit, or additional address<input name="service_address_line_2" value="{{ old('service_address_line_2', $job->service_address_line_2) }}" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-normal" placeholder="Optional"></label>
                                        <label class="text-sm font-semibold text-zinc-700">City<input name="service_city" value="{{ old('service_city', $job->service_city) }}" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-normal"></label>
                                        <label class="text-sm font-semibold text-zinc-700">State<input name="service_state" value="{{ old('service_state', $job->service_state) }}" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-normal"></label>
                                        <label class="text-sm font-semibold text-zinc-700">Postal code<input name="service_postal_code" value="{{ old('service_postal_code', $job->service_postal_code) }}" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-normal"></label>
                                        <label class="text-sm font-semibold text-zinc-700">Country<input name="service_country" value="{{ old('service_country', $job->service_country) }}" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-normal"></label>
                                        <div class="border-t border-zinc-200 pt-3 md:col-span-2"><div class="text-sm font-semibold text-zinc-950">Project manager <span class="font-normal text-zinc-500">(optional)</span></div><p class="mt-1 text-xs font-normal text-zinc-500">Add or update the person who manages this job. Leave blank when there is no project manager.</p></div>
                                        <label class="text-sm font-semibold text-zinc-700">Name<input name="project_manager_name" value="{{ old('project_manager_name', $job->project_manager_name) }}" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-normal" placeholder="Project manager name"></label>
                                        <label class="text-sm font-semibold text-zinc-700">Company<input name="project_manager_company" value="{{ old('project_manager_company', $job->project_manager_company) }}" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-normal" placeholder="Optional"></label>
                                        <label class="text-sm font-semibold text-zinc-700">Phone<input type="tel" name="project_manager_phone" value="{{ old('project_manager_phone', $job->project_manager_phone) }}" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-normal" placeholder="Phone number"></label>
                                        <label class="text-sm font-semibold text-zinc-700">Email<input type="email" name="project_manager_email" value="{{ old('project_manager_email', $job->project_manager_email) }}" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-normal" placeholder="Email address"></label>
                                        <div class="md:col-span-2"><button class="fb-btn fb-btn-primary">Save job details</button></div>
                                    </form>
                                </details>
                            @endif
                        </div>
                    </section>

                    @if($fictionalRoutePolyline !== '')
                        <section class="fb-panel overflow-hidden"><div class="fb-panel-head"><div><div class="fb-panel-title">Fictional van route</div><p class="mt-1 text-sm text-zinc-600">{{ data_get($fictionalRoute, 'vehicle', $job->vehicles->first()?->name ?? 'Company van') }} · demonstration route for this job only</p></div></div><div class="relative h-72 overflow-hidden bg-slate-100 bg-cover bg-center" style="background-image:linear-gradient(rgba(246,243,236,.38),rgba(246,243,236,.38)),url('{{ asset('media/green-shield-fleet-map-osm.png') }}')"><svg viewBox="0 0 1000 600" preserveAspectRatio="none" class="absolute inset-0 h-full w-full" aria-label="Fictional van route map"><polyline points="{{ $fictionalRoutePolyline }}" fill="none" stroke="#173e3b" stroke-linecap="round" stroke-linejoin="round" stroke-width="18" opacity=".88"/><polyline points="{{ $fictionalRoutePolyline }}" fill="none" stroke="#f6f3ec" stroke-dasharray="18 16" stroke-linecap="round" stroke-linejoin="round" stroke-width="4"/>@foreach([$fictionalRoutePoints->first(), $fictionalRoutePoints->last()] as $routePoint)<circle cx="{{ data_get($routePoint, 'x') }}" cy="{{ data_get($routePoint, 'y') }}" r="18" fill="#c96b43" stroke="#fffdf7" stroke-width="8"/>@endforeach</svg><div class="absolute bottom-2 right-3 rounded bg-white/90 px-2 py-1 text-[11px] text-zinc-600">Map data © OpenStreetMap contributors · fictional route overlay</div></div><div class="fb-panel-body text-sm text-zinc-600">This route is fictional demonstration data, not a live employee or vehicle location feed.</div></section>
                    @endif

                    @if($fictionalFinance->isNotEmpty())
                        <section class="fb-panel"><div class="fb-panel-head"><div><div class="fb-panel-title">Fictional job financials</div><p class="mt-1 text-sm text-zinc-600">Demo-only income and cost records; not connected accounting data.</p></div></div><div class="fb-panel-body grid gap-3 sm:grid-cols-2"><div class="rounded-xl border border-emerald-100 bg-emerald-50 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Money in</div><div class="mt-2 text-2xl font-semibold text-emerald-950">${{ number_format($fictionalMoneyIn, 2) }}</div></div><div class="rounded-xl border border-amber-100 bg-amber-50 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-amber-700">Money spent</div><div class="mt-2 text-2xl font-semibold text-amber-950">${{ number_format($fictionalMoneySpent, 2) }}</div></div></div></section>
                    @endif

                </main>

                <aside class="space-y-6">
                    <section class="fb-panel"><div class="fb-panel-head"><div class="fb-panel-title">Tasks</div></div><div class="fb-panel-body space-y-3">
                        @if(data_get($capabilities, 'create_task'))
                            <form method="POST" action="{{ route('field-service.tasks.store', $job) }}" enctype="multipart/form-data" class="space-y-2">@csrf
                                <input name="title" required class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" placeholder="Add task">
                                <label class="block text-xs font-semibold text-zinc-600">Task description<textarea name="description" rows="2" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm font-normal" placeholder="Give the crew the details they need"></textarea></label>
                                <label class="block cursor-pointer rounded-lg border border-dashed border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-900">Add task photos<input name="photos[]" type="file" accept="image/*" multiple class="sr-only"><span class="mt-1 block text-xs font-normal text-emerald-700">Choose photos from this device.</span></label>
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
                                @if($task->description)<p class="mt-2 whitespace-pre-wrap text-sm text-zinc-600">{{ $task->description }}</p>@endif
                                @php($taskPhotos = $job->assets->filter(fn ($asset) => (int) data_get($asset->metadata, 'field_service_task_id', 0) === (int) $task->id && str_starts_with((string) $asset->mime_type, 'image/')))
                                @if($taskPhotos->isNotEmpty())<div class="mt-3 flex gap-2 overflow-x-auto">@foreach($taskPhotos as $asset)<a href="{{ route('documents.preview', [$tenant, $asset]) }}" target="_blank" class="h-20 w-20 shrink-0 overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100"><img src="{{ route('documents.preview', [$tenant, $asset]) }}" alt="{{ $asset->caption ?: $asset->file_name }}" class="h-full w-full object-cover"></a>@endforeach</div>@endif
                                @if($taskUpdateIds->contains((int) $task->id))
                                    <div class="mt-3 grid gap-2 border-t border-zinc-100 pt-3">
                                        <form method="POST" action="{{ route('field-service.tasks.update', [$job, $task]) }}" enctype="multipart/form-data" class="grid gap-2">@csrf @method('PATCH')<div class="flex gap-2"><select name="status" class="min-h-11 flex-1 rounded-lg border border-zinc-300 px-2 text-sm">@foreach(['open' => 'Open', 'in_progress' => 'In progress', 'waiting' => 'Waiting', 'done' => 'Done'] as $value => $label)<option value="{{ $value }}" @selected($task->status === $value)>{{ $label }}</option>@endforeach</select><button class="fb-btn fb-btn-secondary">Save</button></div><label class="text-xs font-semibold text-zinc-600">Task description<textarea name="description" rows="2" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm font-normal">{{ $task->description }}</textarea></label><label class="cursor-pointer rounded-lg border border-dashed border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-900">Add task photos<input name="photos[]" type="file" accept="image/*" multiple class="sr-only"><span class="mt-1 block text-xs font-normal text-emerald-700">Choose photos from this device.</span></label></form>
                                        @if($task->status !== 'done' && $officeTeam->isNotEmpty())<details><summary class="cursor-pointer py-2 text-sm font-semibold text-emerald-800">Send to Office</summary><form method="POST" action="{{ route('field-service.tasks.handoff', [$job, $task]) }}" class="mt-2 space-y-2">@csrf<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><label class="text-xs font-semibold text-zinc-600">Office recipient<select name="assignee_ids[]" required class="mt-1 min-h-11 w-full rounded-lg border border-zinc-300 px-2 py-2 text-sm"><option value="">Choose an office person</option>@foreach($officeTeam as $member)<option value="{{ $member->id }}">{{ $member->name ?: $member->email }} · {{ ucfirst(str_replace('_', ' ', $member->pivot->role)) }}</option>@endforeach</select></label><textarea name="note" rows="2" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" placeholder="What does the office need to do?"></textarea><button class="fb-btn fb-btn-primary w-full justify-center">Send to Office and mark waiting</button></form></details>@endif
                                    </div>
                                @endif
                            </div>
                        @empty<p class="text-sm text-zinc-600">No tasks yet.</p>@endforelse
                    </div></section>
                    <section class="fb-panel"><div class="fb-panel-head"><div><div class="fb-panel-title">Job photos</div><p class="mt-1 text-sm text-zinc-600">A visual record of the site and completed work.</p></div></div><div class="fb-panel-body space-y-4"><div class="grid grid-cols-2 gap-3">@foreach($uploadedImages as $asset)<div class="group relative aspect-[4/3] overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100"><a href="{{ route('documents.preview', [$tenant, $asset]) }}" target="_blank"><img src="{{ route('documents.preview', [$tenant, $asset]) }}" alt="{{ $asset->caption ?: $asset->file_name }}" class="h-full w-full object-cover transition duration-200 group-hover:scale-[1.03]"><span class="absolute inset-x-0 bottom-0 truncate bg-zinc-950/65 px-2 py-1.5 text-xs font-semibold text-white">{{ $asset->caption ?: $asset->file_name }}</span></a>@if($canManage)<form method="POST" action="{{ route('field-service.assets.destroy', [$job, $asset]) }}" onsubmit="return confirm('Do you want to delete this photo?')" class="absolute right-2 top-2">@csrf @method('DELETE')<button class="rounded bg-white/90 px-2 py-1 text-xs font-semibold text-rose-700">Delete</button></form>@endif</div>@endforeach @foreach($job->photos as $photo)<a href="{{ $photo->file_path }}" target="_blank" class="group relative aspect-[4/3] overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100"><img src="{{ $photo->file_path }}" alt="{{ $photo->caption ?: 'Job photo' }}" class="h-full w-full object-cover transition duration-200 group-hover:scale-[1.03]"><span class="absolute inset-x-0 bottom-0 truncate bg-zinc-950/65 px-2 py-1.5 text-xs font-semibold text-white">{{ $photo->caption ?: 'Job photo' }}</span></a>@endforeach</div>@if($uploadedImages->isEmpty() && $job->photos->isEmpty())<p class="text-sm text-zinc-600">No job photos yet. Add them from an update above.</p>@endif<div><div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Drawings & PDFs</div><div class="space-y-2">@forelse($job->assets->filter(fn ($asset) => $asset->mime_type === 'application/pdf') as $asset)<div class="flex min-h-11 items-center gap-2 rounded-lg border border-zinc-200 px-3 text-sm font-semibold text-emerald-900"><a href="{{ route('documents.download', [$tenant, $asset]) }}" class="min-w-0 flex-1 truncate">{{ $asset->file_name }}</a>@if($canManage)<form method="POST" action="{{ route('field-service.assets.destroy', [$job, $asset]) }}" onsubmit="return confirm('Do you want to delete this PDF?')">@csrf @method('DELETE')<button class="text-xs font-semibold text-rose-700">Delete</button></form>@endif</div>@empty<p class="text-sm text-zinc-600">No drawings or PDFs yet. Add one in Updates.</p>@endforelse</div></div></div></section>
                </aside>
            </div>

            <section class="fb-panel">
                <div class="fb-panel-head"><div><div class="fb-panel-title">Updates</div><p class="fb-panel-copy">Keep the whole team informed with progress, notes, and handoffs.</p></div></div>
                <div class="fb-panel-body space-y-3">
                    <form method="POST" action="{{ route('field-service.notes.store', $job) }}" enctype="multipart/form-data">@csrf<textarea name="body" rows="3" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" placeholder="Share an update or @mention a teammate"></textarea><div class="mt-3 flex flex-wrap items-center gap-3"><label class="fb-btn fb-btn-secondary cursor-pointer"><span>Take or add photos</span><input name="attachments[]" type="file" accept="image/*" capture="environment" multiple class="sr-only"></label><label class="fb-btn fb-btn-secondary cursor-pointer"><span>Add files</span><input name="attachments[]" type="file" accept="image/*,application/pdf,text/plain,text/csv,.doc,.docx,.xls,.xlsx" multiple class="sr-only"></label><span class="text-xs text-zinc-500">Photos, PDFs, documents, spreadsheets, and text files · up to 25 MB each</span></div><button class="fb-btn fb-btn-primary mt-3">Save update</button></form>
                    @forelse($job->notes->sortByDesc('noted_at') as $note)<article class="border-t border-zinc-200 pt-3"><div class="flex items-start justify-between gap-3"><div class="text-sm font-semibold text-zinc-950">{{ $note->createdBy?->name ?? 'Team update' }} <span class="font-normal text-zinc-500">{{ optional($note->noted_at)->diffForHumans() }}</span></div>@if($canManage)<form method="POST" action="{{ route('field-service.notes.destroy', [$job, $note]) }}" onsubmit="return confirm('Do you want to delete this update?')">@csrf @method('DELETE')<button class="text-xs font-semibold text-rose-700">Delete</button></form>@endif</div><div class="mt-1 whitespace-pre-wrap text-sm text-zinc-700">{{ $note->body }}</div>@if($updateAttachmentsByNote->has((int) $note->id))<div class="mt-3 flex flex-wrap gap-3">@foreach($updateAttachmentsByNote->get((int) $note->id) as $asset)@if(str_starts_with((string) $asset->mime_type, 'image/'))<a href="{{ route('documents.preview', [$tenant, $asset]) }}" target="_blank" class="group relative h-40 w-56 overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100"><img src="{{ route('documents.preview', [$tenant, $asset]) }}" alt="{{ $asset->caption ?: $asset->file_name }}" class="h-full w-full object-cover transition duration-200 group-hover:scale-[1.03]"><span class="absolute inset-x-0 bottom-0 truncate bg-zinc-950/65 px-2 py-1.5 text-xs font-semibold text-white">{{ $asset->file_name }}</span></a>@else<a href="{{ route('documents.download', [$tenant, $asset]) }}" class="inline-flex min-h-10 max-w-full items-center rounded-lg border border-zinc-200 bg-zinc-50 px-3 text-sm font-semibold text-emerald-900 hover:border-emerald-300"><span class="truncate">File · {{ $asset->file_name }}</span></a>@endif @endforeach</div>@endif</article>@empty<p class="text-sm text-zinc-600">No updates yet.</p>@endforelse
                </div>
            </section>
        </div>
    </flux:main>
</x-layouts::app.sidebar>

@if($canManage)
<script>
    (() => {
        const container = document.querySelector('[data-address-autocomplete]');
        if (!container) return;

        const input = container.querySelector('input[name="service_address_line_1"]');
        const choices = container.querySelector('[data-address-suggestions]');
        const status = container.querySelector('[data-address-status]');
        const form = container.closest('form');
        const suggestionsUrl = @json(route('field-service.address-suggestions'));
        const detailUrl = @json(route('field-service.address-details', ['placeId' => '__PLACE_ID__']));
        let timer;
        let controller;

        const close = () => {
            choices.replaceChildren();
            choices.classList.add('hidden');
            input.setAttribute('aria-expanded', 'false');
        };
        const setField = (name, value) => {
            const field = form?.querySelector(`[name="${name}"]`);
            if (field && value) field.value = value;
        };
        const select = async (suggestion) => {
            close();
            status.textContent = 'Filling in address details…';
            try {
                const response = await fetch(detailUrl.replace('__PLACE_ID__', encodeURIComponent(suggestion.place_id)), {headers: {Accept: 'application/json'}});
                const address = (await response.json()).address;
                if (!address) throw new Error('No address details');
                setField('service_address_line_1', address.line_1 || suggestion.label);
                setField('service_address_line_2', address.line_2);
                setField('service_city', address.city);
                setField('service_state', address.state);
                setField('service_postal_code', address.postal_code);
                setField('service_country', address.country);
                status.textContent = 'Address details filled in. Review them, then save the job.';
            } catch (_) {
                input.value = suggestion.label;
                status.textContent = 'Address selected. Complete any missing details, then save the job.';
            }
        };
        const search = async () => {
            const query = input.value.trim();
            if (query.length < 4) return close();
            controller?.abort();
            controller = new AbortController();
            try {
                const response = await fetch(`${suggestionsUrl}?q=${encodeURIComponent(query)}`, {headers: {Accept: 'application/json'}, signal: controller.signal});
                const suggestions = (await response.json()).suggestions || [];
                if (!suggestions.length) return close();
                choices.replaceChildren(...suggestions.map((suggestion) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'block w-full border-b border-zinc-100 px-3 py-2 text-left text-sm font-normal text-zinc-800 last:border-b-0 hover:bg-emerald-50 focus:bg-emerald-50';
                    button.textContent = suggestion.label;
                    button.setAttribute('role', 'option');
                    button.addEventListener('click', () => void select(suggestion));
                    return button;
                }));
                choices.classList.remove('hidden');
                input.setAttribute('aria-expanded', 'true');
            } catch (error) {
                if (error.name !== 'AbortError') close();
            }
        };

        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => void search(), 250);
        });
        input.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(); });
        document.addEventListener('click', (event) => { if (!container.contains(event.target)) close(); });
    })();
</script>
@endif
