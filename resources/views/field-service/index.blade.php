@php
    $tenantName = (string) ($tenant->name ?? 'Workspace');
    $jobs = collect($jobs ?? []);
    $materials = collect($materials ?? []);
    $vehicles = collect($vehicles ?? []);
    $team = collect($team ?? []);
    $reminderSetting = $reminderSetting ?? null;
    $openJobs = $jobs->whereIn('operational_status', ['needs_details', 'scheduled', 'active', 'blocked'])->count();
    $scheduledJobs = $jobs->filter(fn ($job) => filled($job->scheduled_for))->count();
    $statusClass = function (?string $status): string {
        return match ($status) {
            'done', 'complete' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'scheduled' => 'border-sky-200 bg-sky-50 text-sky-800',
            'in_progress', 'active' => 'border-indigo-200 bg-indigo-50 text-indigo-800',
            'blocked' => 'border-rose-200 bg-rose-50 text-rose-800',
            'needed', 'open' => 'border-amber-200 bg-amber-50 text-amber-800',
            default => 'border-zinc-200 bg-zinc-50 text-zinc-700',
        };
    };
@endphp

<x-layouts::app.sidebar title="Field Service">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="fb-workflow-header">
                <div class="fb-eyebrow">{{ $tenantName }}</div>
                <h1 class="text-3xl font-semibold text-zinc-950">Field Service</h1>
                <div class="mt-4 flex flex-wrap gap-3">
                    <a href="{{ route('field-service.calendar') }}" class="fb-btn fb-btn-primary">Calendar</a>
                    <a href="{{ route('field-service.index', ['view' => 'list']) }}" class="fb-btn fb-btn-secondary">List</a>
                    <a href="#reminders" class="fb-btn fb-btn-secondary">Reminder setup</a>
                </div>

                @if (session('status'))
                    <div class="fb-state fb-state-success mt-4">{{ session('status') }}</div>
                @endif

                <div class="fb-metric-grid">
                    <div class="fb-metric">
                        <div class="fb-metric-label">Open jobs</div>
                        <div class="fb-metric-value">{{ number_format($openJobs) }}</div>
                    </div>
                    <div class="fb-metric">
                        <div class="fb-metric-label">Scheduled</div>
                        <div class="fb-metric-value">{{ number_format($scheduledJobs) }}</div>
                    </div>
                    <div class="fb-metric">
                        <div class="fb-metric-label">Materials</div>
                        <div class="fb-metric-value">{{ number_format($materials->count()) }}</div>
                    </div>
                    <div class="fb-metric">
                        <div class="fb-metric-label">Vehicles</div>
                        <div class="fb-metric-value">{{ number_format($vehicles->count()) }}</div>
                    </div>
                </div>
            </header>

            <div class="grid gap-6 xl:grid-cols-[0.85fr_1.15fr]">
                <section class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Add a customer job</div>
                            <div class="fb-panel-copy">Start with the customer, the address, and what needs to be done.</div>
                        </div>
                    </div>
                    <div class="fb-panel-body">
                        <form method="POST" action="{{ route('field-service.jobs.store') }}" class="grid gap-4">
                            @csrf
                            <div>
                                <label class="text-sm font-semibold text-zinc-800">Customer name</label>
                                <input name="customer_name" value="{{ old('customer_name') }}" required class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="Jane Smith">
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="text-sm font-semibold text-zinc-800">Email</label>
                                    <input name="customer_email" value="{{ old('customer_email') }}" type="email" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="jane@example.com">
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-zinc-800">Phone</label>
                                    <input name="customer_phone" value="{{ old('customer_phone') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="555-123-4567">
                                </div>
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-zinc-800">Lock box / gate code</label>
                                <input name="lock_box_code" value="{{ old('lock_box_code') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="Shown prominently for employees">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-zinc-800">Job title</label>
                                <input name="title" value="{{ old('title') }}" required class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="Panel inspection, outlet repair, lighting install">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-zinc-800">Work address</label>
                                <input name="service_address_line_1" value="{{ old('service_address_line_1') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="Street address">
                            </div>
                            <div class="grid gap-4 md:grid-cols-3">
                                <input name="service_city" value="{{ old('service_city') }}" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="City">
                                <input name="service_state" value="{{ old('service_state') }}" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="State">
                                <input name="service_postal_code" value="{{ old('service_postal_code') }}" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="ZIP">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-zinc-800">What needs to happen?</label>
                                <textarea name="description" rows="3" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="Notes, customer concern, access details...">{{ old('description') }}</textarea>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="text-sm font-semibold text-zinc-800">Assign to</label>
                                    <select name="assigned_user_id" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm">
                                        <option value="">No one yet</option>
                                        @foreach($team as $member)
                                            <option value="{{ $member->id }}">{{ $member->name ?: $member->email }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-zinc-800">Schedule</label>
                                    <input name="scheduled_for" type="datetime-local" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm">
                                </div>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <input name="first_task" value="{{ old('first_task') }}" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="First task, optional">
                                <input name="first_material" value="{{ old('first_material') }}" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="First material, optional">
                            </div>
                            <button type="submit" class="fb-btn fb-btn-accent justify-center">Create job</button>
                        </form>
                    </div>
                </section>

                <section class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Recent jobs</div>
                            <div class="fb-panel-copy">Open work, assigned people, materials, and notes.</div>
                        </div>
                    </div>
                    <div class="fb-panel-body space-y-4">
                        @forelse($jobs as $job)
                            <article class="rounded-2xl border border-zinc-200 bg-white p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 class="text-lg font-semibold text-zinc-950">
                                            <a href="{{ route('field-service.jobs.show', ['job' => $job]) }}" class="hover:underline">{{ $job->title }}</a>
                                        </h2>
                                        <p class="mt-1 text-sm text-zinc-600">
                                            {{ $job->customer_name ?: trim(($job->customer?->first_name ?? '').' '.($job->customer?->last_name ?? '')) ?: 'Customer not named' }}
                                            @if($job->customer_phone) · {{ $job->customer_phone }} @elseif($job->customer_email) · {{ $job->customer_email }} @endif
                                        </p>
                                    </div>
                                    <span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $statusClass((string) $job->operational_status) }}">
                                        {{ $statusLabels[$job->operational_status] ?? ucfirst(str_replace('_', ' ', (string) ($job->operational_status ?: 'needs_details'))) }}
                                    </span>
                                </div>

                                <div class="mt-4 grid gap-3 md:grid-cols-3">
                                    @if($job->lock_box_code)
                                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2">
                                            <div class="text-[11px] uppercase tracking-[0.16em] text-amber-700">Access code</div>
                                            <div class="mt-1 text-base font-bold text-amber-950">{{ $job->lock_box_code }}</div>
                                        </div>
                                    @endif
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                                        <div class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Address</div>
                                        <div class="mt-1 text-sm font-semibold text-zinc-900">
                                            {{ $job->service_address_line_1 ?: 'No address yet' }}
                                        </div>
                                        @if($job->service_city || $job->service_state || $job->service_postal_code)
                                            <div class="text-xs text-zinc-600">{{ trim(($job->service_city ?? '').' '.($job->service_state ?? '').' '.($job->service_postal_code ?? '')) }}</div>
                                        @endif
                                    </div>
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                                        <div class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Assigned</div>
                                        <div class="mt-1 text-sm font-semibold text-zinc-900">{{ $job->assignedUser?->name ?? 'No one yet' }}</div>
                                    </div>
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                                        <div class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Schedule</div>
                                        <div class="mt-1 text-sm font-semibold text-zinc-900">{{ optional($job->scheduled_for)->format('M j, g:ia') ?: 'Not scheduled' }}</div>
                                    </div>
                                </div>

                                @if($job->description)
                                    <p class="mt-4 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-700">{{ $job->description }}</p>
                                @endif

                                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                                    <form method="POST" action="{{ route('field-service.notes.store', ['job' => $job]) }}" class="rounded-xl border border-zinc-200 bg-white p-3">
                                        @csrf
                                        <label class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Add update</label>
                                        <textarea name="body" required rows="2" class="mt-2 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" placeholder="Work done, issue found, customer note"></textarea>
                                        <select name="status_update" class="mt-2 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                                            <option value="">No status change</option>
                                            @foreach(['scheduled', 'in_progress', 'blocked', 'done'] as $status)
                                                <option value="{{ $status }}">{{ $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status)) }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="fb-btn fb-btn-secondary mt-2 w-full justify-center">Add</button>
                                    </form>
                                    <form method="POST" action="{{ route('field-service.tasks.store', ['job' => $job]) }}" class="rounded-xl border border-zinc-200 bg-white p-3">
                                        @csrf
                                        <label class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Add task</label>
                                        <input name="title" required class="mt-2 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" placeholder="Task">
                                        <button type="submit" class="fb-btn fb-btn-secondary mt-2 w-full justify-center">Add</button>
                                    </form>
                                    <form method="POST" action="{{ route('field-service.materials.store') }}" class="rounded-xl border border-zinc-200 bg-white p-3">
                                        @csrf
                                        <input type="hidden" name="field_service_job_id" value="{{ $job->id }}">
                                        <label class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Add material</label>
                                        <input name="name" required class="mt-2 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" placeholder="Part or material">
                                        <button type="submit" class="fb-btn fb-btn-secondary mt-2 w-full justify-center">Add</button>
                                    </form>
                                    <form method="POST" action="{{ route('field-service.photos.store', ['job' => $job]) }}" class="rounded-xl border border-zinc-200 bg-white p-3">
                                        @csrf
                                        <label class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Add photo/file link</label>
                                        <input name="file_path" required class="mt-2 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" placeholder="URL or file path">
                                        <button type="submit" class="fb-btn fb-btn-secondary mt-2 w-full justify-center">Add</button>
                                    </form>
                                </div>

                                <div class="mt-4 grid gap-3 md:grid-cols-3">
                                    <div class="text-sm text-zinc-600"><span class="font-semibold text-zinc-950">{{ $job->tasks->count() }}</span> tasks</div>
                                    <div class="text-sm text-zinc-600"><span class="font-semibold text-zinc-950">{{ $job->materials->count() }}</span> materials</div>
                                    <div class="text-sm text-zinc-600"><span class="font-semibold text-zinc-950">{{ $job->notes->count() }}</span> updates · <span class="font-semibold text-zinc-950">{{ $job->photos->count() }}</span> photo/file links</div>
                                </div>

                                @if($job->notes->isNotEmpty())
                                    <div class="mt-4 space-y-2">
                                        @foreach($job->notes->sortByDesc('noted_at')->take(2) as $note)
                                            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-700">
                                                <div class="font-semibold text-zinc-950">{{ $note->createdBy?->name ?? 'Team update' }} @if($note->status_update) · {{ $statusLabels[$note->status_update] ?? $note->status_update }} @endif</div>
                                                <div class="mt-1">{{ $note->body }}</div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </article>
                        @empty
                            <div class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center">
                                <h2 class="text-lg font-semibold text-zinc-950">No jobs yet</h2>
                                <p class="mt-2 text-sm text-zinc-600">Add the first customer job and this becomes your daily work list.</p>
                            </div>
                        @endforelse
                    </div>
                </section>
            </div>

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <section id="reminders" class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Reminder setup</div>
                            <div class="fb-panel-copy">Captured for guided setup. SMS delivery stays off until Everbranch verifies the provider and consent state.</div>
                        </div>
                    </div>
                    <div class="fb-panel-body">
                        <form method="POST" action="{{ route('field-service.reminders.update') }}" class="grid gap-3">
                            @csrf
                            <label class="flex items-center gap-2 text-sm font-semibold text-zinc-800">
                                <input type="checkbox" name="enabled" value="1" @checked((bool) ($reminderSetting?->enabled ?? false))>
                                Request recurring reminders
                            </label>
                            <div class="grid gap-3 md:grid-cols-3">
                                <select name="channel" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm">
                                    <option value="sms" @selected(($reminderSetting?->channel ?? 'sms') === 'sms')>SMS</option>
                                    <option value="email" @selected(($reminderSetting?->channel ?? '') === 'email')>Email</option>
                                </select>
                                <select name="cadence" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm">
                                    @foreach(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $value => $label)
                                        <option value="{{ $value }}" @selected(($reminderSetting?->cadence ?? 'daily') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input name="send_time" type="time" value="{{ $reminderSetting?->send_time }}" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm">
                            </div>
                            <textarea name="customer_copy" rows="2" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="Customer reminder wording">{{ $reminderSetting?->customer_copy }}</textarea>
                            <textarea name="internal_notes" rows="2" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="Internal setup notes">{{ $reminderSetting?->internal_notes }}</textarea>
                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-900">
                                Provider status: {{ ucfirst(str_replace('_', ' ', (string) ($reminderSetting?->provider_status ?? 'not_verified'))) }}
                            </div>
                            <button type="submit" class="fb-btn fb-btn-secondary justify-center">Save reminder setup</button>
                        </form>
                    </div>
                </section>

                <section id="materials" class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Materials and parts</div>
                            <div class="fb-panel-copy">Track what needs to be bought, pulled, or loaded.</div>
                        </div>
                    </div>
                    <div class="fb-panel-body">
                        <form method="POST" action="{{ route('field-service.materials.store') }}" class="mb-4 grid gap-3 md:grid-cols-[1fr_110px_auto]">
                            @csrf
                            <input name="name" required class="rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="Material or part">
                            <input name="quantity" type="number" min="0" step="0.01" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="Qty">
                            <button type="submit" class="fb-btn fb-btn-secondary justify-center">Add</button>
                        </form>
                        <div class="space-y-2">
                            @forelse($materials as $material)
                                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-3">
                                    <div>
                                        <div class="font-semibold text-zinc-950">{{ $material->name }}</div>
                                        <div class="text-sm text-zinc-600">{{ $material->job?->title ?? 'General stock' }}</div>
                                    </div>
                                    <div class="text-sm text-zinc-600">{{ $material->quantity }} {{ $material->unit }}</div>
                                </div>
                            @empty
                                <p class="text-sm text-zinc-600">No materials yet.</p>
                            @endforelse
                        </div>
                    </div>
                </section>

                <section id="vehicles" class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Work vans</div>
                            <div class="fb-panel-copy">Keep a quick list of vehicles and notes.</div>
                        </div>
                    </div>
                    <div class="fb-panel-body">
                        <form method="POST" action="{{ route('field-service.vehicles.store') }}" class="mb-4 grid gap-3 md:grid-cols-[1fr_1fr_auto]">
                            @csrf
                            <input name="name" required class="rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="Van name">
                            <input name="identifier" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm" placeholder="Plate or unit #">
                            <button type="submit" class="fb-btn fb-btn-secondary justify-center">Add</button>
                        </form>
                        <div class="space-y-2">
                            @forelse($vehicles as $vehicle)
                                <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3">
                                    <div class="font-semibold text-zinc-950">{{ $vehicle->name }}</div>
                                    <div class="text-sm text-zinc-600">{{ $vehicle->identifier ?: 'No identifier' }}</div>
                                </div>
                            @empty
                                <p class="text-sm text-zinc-600">No work vans yet.</p>
                            @endforelse
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
