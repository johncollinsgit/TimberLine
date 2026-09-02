<x-layouts::app.sidebar title="Field Operations">
    <flux:main>
        <div class="mx-auto w-full max-w-[1900px] space-y-5 px-3 py-4 sm:px-5 lg:px-7">
            <header class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-700">{{ $tenant->name }}</div>
                        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-950">Work</h1>
                        <p class="mt-2 max-w-2xl text-sm text-zinc-600">Find, sort, assign, and update field work without leaving the grid.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('field-service.calendar') }}" class="fb-btn fb-btn-secondary">Calendar</a>
                        <a href="{{ route('field-service.payroll-hours') }}" class="fb-btn fb-btn-secondary">Team hours</a>
                        <a href="{{ route('field-service.resources') }}" class="fb-btn fb-btn-secondary">Inventory & vans</a>
                        @if($fleetTrackingEnabled ?? false)<a href="{{ route('field-service.fleet-tracking.index') }}" class="fb-btn fb-btn-secondary">Location tracking</a>@endif
                        @if($equipmentMaintenanceEnabled ?? false)<a href="{{ route('field-service.equipment.index') }}" class="fb-btn fb-btn-secondary">Equipment</a>@endif
                        @if(data_get($capabilities ?? [], 'create_jobs'))<a href="#new-job" class="fb-btn fb-btn-primary">Create job</a>@endif
                    </div>
                </div>
                @if($ownerMetrics)
                    <div class="mt-5 border-t border-zinc-200 pt-5">
                        <nav class="inline-flex rounded-xl bg-zinc-100 p-1" aria-label="Financial period">@foreach(data_get($ownerMetrics, 'options', []) as $option)<a href="{{ route('field-service.index', ['period' => $option['key']]) }}" class="min-h-11 rounded-lg px-4 py-3 text-sm font-semibold {{ data_get($ownerMetrics, 'period') === $option['key'] ? 'bg-white text-emerald-900 shadow-sm' : 'text-zinc-600' }}">{{ $option['label'] }}</a>@endforeach</nav>
                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Money In</div><div class="mt-2 text-2xl font-semibold text-emerald-950">{{ data_get($ownerMetrics, 'money_in') === null ? '—' : '$'.number_format((float) data_get($ownerMetrics, 'money_in'), 0) }}</div></div>
                            <div class="rounded-2xl border border-amber-100 bg-amber-50 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-amber-700">Money Spent</div><div class="mt-2 text-2xl font-semibold text-amber-950">{{ data_get($ownerMetrics, 'money_spent') === null ? '—' : '$'.number_format((float) data_get($ownerMetrics, 'money_spent'), 0) }}</div></div>
                            <div class="rounded-2xl border border-sky-100 bg-sky-50 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-sky-700">Finished Jobs</div><div class="mt-2 text-2xl font-semibold text-sky-950">{{ number_format((int) data_get($ownerMetrics, 'finished_jobs', 0)) }}</div></div>
                        </div>
                        <p class="mt-2 text-xs text-zinc-500">{{ data_get($ownerMetrics, 'quickbooks.message') }}</p>
                    </div>
                @endif
            </header>

            @if(data_get($capabilities ?? [], 'manage_jobs'))
                @php
                    $jobUpdateSms = (array) ($reminderSetting->job_update_sms ?? []);
                    $jobUpdateSmsEnabled = filter_var(old('job_update_sms_enabled', data_get($jobUpdateSms, 'enabled', false)), FILTER_VALIDATE_BOOLEAN);
                @endphp
                <section class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-semibold text-zinc-950">Job update text alerts</h2>
                            <p class="mt-1 text-sm text-zinc-600">Choose one office number for update comments, photos, and files from the web or field app.</p>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $reminderSetting->provider_status === 'verified' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">
                            {{ $reminderSetting->provider_status === 'verified' ? 'SMS sender verified' : 'SMS sender needs verification' }}
                        </span>
                    </div>
                    <form method="POST" action="{{ route('field-service.reminders.update') }}" class="mt-5 grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto_auto] lg:items-end">
                        @csrf
                        <input type="hidden" name="enabled" value="{{ $reminderSetting->enabled ? '1' : '0' }}">
                        <input type="hidden" name="channel" value="{{ $reminderSetting->channel ?: 'sms' }}">
                        <input type="hidden" name="cadence" value="{{ $reminderSetting->cadence ?: 'daily' }}">
                        <input type="hidden" name="send_time" value="{{ $reminderSetting->send_time }}">
                        <input type="hidden" name="timezone" value="{{ $reminderSetting->timezone ?: 'America/New_York' }}">
                        <input type="hidden" name="customer_copy" value="{{ $reminderSetting->customer_copy }}">
                        <input type="hidden" name="internal_notes" value="{{ $reminderSetting->internal_notes }}">
                        <label class="block">
                            <span class="text-sm font-semibold text-zinc-800">Send job updates to</span>
                            <input name="job_update_sms_phone" inputmode="tel" autocomplete="tel" value="{{ old('job_update_sms_phone', data_get($jobUpdateSms, 'phone')) }}" class="mt-2 w-full rounded-xl border border-zinc-300 px-3 py-3 text-base" placeholder="+1 (864) 640-6642">
                        </label>
                        <label class="flex min-h-12 items-center gap-3 rounded-xl border border-zinc-300 px-4 text-sm font-semibold text-zinc-800">
                            <input type="hidden" name="job_update_sms_enabled" value="0">
                            <input type="checkbox" name="job_update_sms_enabled" value="1" class="h-5 w-5 rounded border-zinc-400 text-emerald-700 focus:ring-emerald-600" @checked($jobUpdateSmsEnabled)>
                            Turn on text alerts
                        </label>
                        <button type="submit" class="fb-btn fb-btn-primary min-h-12 justify-center">Save alert number</button>
                    </form>
                    <p class="mt-3 text-xs text-zinc-500">Saving this number does not send a text. Alerts remain blocked until the workspace SMS sender is verified.</p>
                </section>
            @endif

            @php
                $calendarStart = $homeCalendarStart ?? now()->startOfDay();
                $calendarDays = collect(range(0, 6))->map(fn (int $offset) => $calendarStart->copy()->addDays($offset));
                $calendarJobs = collect($homeCalendarJobs ?? []);
            @endphp
            <section class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-xl font-semibold text-zinc-950">This week</h2><p class="mt-1 text-sm text-zinc-600">A live view of the fictional Green Shield schedule.</p></div><a href="{{ route('field-service.calendar') }}" class="fb-btn fb-btn-secondary">Open full calendar</a></div>
                <div class="mt-5 overflow-x-auto"><div class="grid min-w-[880px] grid-cols-7 gap-3">@foreach($calendarDays as $day)<div class="min-h-48 rounded-2xl border border-zinc-200 bg-zinc-50 p-3"><div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $day->format('D') }}</div><div class="mt-1 text-lg font-semibold text-zinc-950">{{ $day->format('M j') }}</div><div class="mt-3 space-y-2">@forelse($calendarJobs->filter(fn ($job) => $job->scheduled_for?->isSameDay($day)) as $calendarJob)<a href="{{ route('field-service.jobs.show', $calendarJob) }}" class="block rounded-xl border border-emerald-100 bg-white p-2.5 shadow-sm hover:border-emerald-300"><div class="text-xs font-semibold text-emerald-800">{{ $calendarJob->scheduled_for?->format('g:ia') }}</div><div class="mt-1 text-sm font-semibold leading-snug text-zinc-950">{{ $calendarJob->title }}</div><div class="mt-1 text-xs text-zinc-500">{{ $calendarJob->assignedUser?->name ?? 'Unassigned' }}</div></a>@empty<div class="pt-3 text-sm text-zinc-400">No visits</div>@endforelse</div></div>@endforeach</div></div>
            </section>

            <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-3 shadow-sm sm:p-5">
                <div
                    id="field-service-jobs-grid"
                    data-endpoint="{{ route('field-service.jobs.data') }}"
                    data-update-template="{{ route('field-service.jobs.update', ['job' => 0]) }}"
                    data-transition-template="{{ route('field-service.jobs.transitions', ['job' => 0]) }}"
                    data-updates-template="{{ route('field-service.jobs.updates', ['job' => 0]) }}"
                    data-note-template="{{ route('field-service.notes.store', ['job' => 0]) }}"
                    data-candidate-template="{{ route('field-service.work-candidates.review', ['candidate' => 0]) }}"
                    data-can-manage="{{ data_get($capabilities ?? [], 'manage_jobs') ? '1' : '0' }}"
                    data-can-manage-drafts="{{ ($canManageJobDrafts ?? false) ? '1' : '0' }}"
                    class="min-h-[680px]"
                >
                    <div class="rounded-2xl border border-dashed border-zinc-300 bg-white p-6 text-sm text-zinc-600">Loading the work grid…</div>
                </div>
                <noscript class="mt-3 block rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">JavaScript is required for spreadsheet editing. The Calendar and job detail links remain available above.</noscript>
            </section>

            @if(data_get($capabilities ?? [], 'create_jobs'))
                <section id="new-job" class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7">
                    <div class="mb-4">
                        <h2 class="text-xl font-semibold text-zinc-950">Create job</h2>
                        <p class="mt-1 text-sm text-zinc-600">Start with the essentials. You can fill in the rest from the job.</p>
                    </div>
                    <form id="field-service-create-job" method="POST" action="{{ route('field-service.jobs.store') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @csrf
                        <div class="xl:col-span-2">
                            <label for="field-service-customer-lookup" class="mb-1 block text-sm font-semibold text-zinc-700">Customer</label>
                            <input id="field-service-customer-lookup" name="customer_lookup" list="field-service-customer-options" value="{{ old('customer_lookup') }}" autocomplete="off" class="w-full rounded-xl border border-zinc-300 px-3 py-3 text-sm" placeholder="Search and select an existing customer">
                            <input id="field-service-marketing-profile-id" name="marketing_profile_id" type="hidden" value="{{ old('marketing_profile_id') }}">
                            <datalist id="field-service-customer-options">@foreach($jobCustomerChoices ?? [] as $customer)<option value="{{ $customer['label'] }}"></option>@endforeach</datalist>
                            <p id="field-service-customer-help" class="mt-1 text-xs text-zinc-500">Select a customer to prefill their contact and service address.</p>
                            @error('customer_lookup')<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                        </div>
                        <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-zinc-300 bg-zinc-50 px-3 text-sm font-semibold text-zinc-800 xl:col-span-2"><input id="field-service-create-customer" name="create_customer" value="1" type="checkbox" @checked(old('create_customer')) class="h-5 w-5 rounded border-zinc-400 text-emerald-700 focus:ring-emerald-600">This is a new customer</label>
                        <input id="field-service-customer-name" name="customer_name" required value="{{ old('customer_name') }}" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm" placeholder="Customer name">
                        <input name="title" required class="rounded-xl border border-zinc-300 px-3 py-3 text-sm" placeholder="Job title">
                        <input id="field-service-customer-phone" name="customer_phone" value="{{ old('customer_phone') }}" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm" placeholder="Customer phone">
                        <input id="field-service-customer-email" name="customer_email" type="email" value="{{ old('customer_email') }}" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm" placeholder="Customer email">
                        <input id="field-service-service-address" name="service_address_line_1" value="{{ old('service_address_line_1') }}" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm xl:col-span-2" placeholder="Service address">
                        <input name="scheduled_for" type="datetime-local" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm">
                        <select name="assigned_user_id" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm">
                            <option value="">Unassigned</option>
                            @foreach($team as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                        </select>
                        <textarea name="description" rows="2" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm md:col-span-2" placeholder="Scope, access notes, or instructions"></textarea>
                        <button type="submit" class="fb-btn fb-btn-primary min-h-11 justify-center">Create job</button>
                    </form>
                    <script id="field-service-job-customers" type="application/json">@json($jobCustomerChoices ?? [])</script>
                    <script>
                        (() => {
                            const form = document.getElementById('field-service-create-job');
                            const data = document.getElementById('field-service-job-customers');
                            if (!form || !data) return;

                            const customers = JSON.parse(data.textContent || '[]');
                            const lookup = document.getElementById('field-service-customer-lookup');
                            const profileId = document.getElementById('field-service-marketing-profile-id');
                            const createCustomer = document.getElementById('field-service-create-customer');
                            const help = document.getElementById('field-service-customer-help');
                            const fields = {
                                name: document.getElementById('field-service-customer-name'),
                                phone: document.getElementById('field-service-customer-phone'),
                                email: document.getElementById('field-service-customer-email'),
                                address_line_1: document.getElementById('field-service-service-address'),
                            };
                            const selectCustomer = () => {
                                const customer = customers.find((item) => item.label === lookup.value);
                                if (!customer) {
                                    profileId.value = '';
                                    if (!createCustomer.checked) help.textContent = 'Choose a customer from the list, or check “This is a new customer”.';
                                    return;
                                }
                                profileId.value = customer.id;
                                createCustomer.checked = false;
                                Object.entries(fields).forEach(([key, input]) => { if (input) input.value = customer[key] || ''; });
                                help.textContent = `Selected ${customer.name}. This job will link to their existing customer record.`;
                            };

                            lookup.addEventListener('input', selectCustomer);
                            createCustomer.addEventListener('change', () => {
                                if (createCustomer.checked) {
                                    profileId.value = '';
                                    lookup.value = '';
                                    help.textContent = 'A new customer will be created only if their email or phone is not already in the customer list.';
                                } else if (!profileId.value) {
                                    help.textContent = 'Choose a customer from the list, or check “This is a new customer”.';
                                }
                            });
                            form.addEventListener('submit', (event) => {
                                if (!profileId.value && !createCustomer.checked) {
                                    event.preventDefault();
                                    help.textContent = 'Choose an existing customer or check “This is a new customer” to continue.';
                                    lookup.focus();
                                }
                            });
                        })();
                    </script>
                </section>
            @endif

            <section class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex items-center justify-between gap-3"><div><h2 class="text-xl font-semibold text-zinc-950">My assigned tasks</h2><p class="mt-1 text-sm text-zinc-600">Your open next steps, including work waiting on you.</p></div><span class="rounded-full bg-zinc-100 px-3 py-1 text-sm font-semibold text-zinc-700">{{ number_format((int) ($assignedTaskTotal ?? 0)) }}</span></div>
                <div class="mt-4 divide-y divide-zinc-200 border-y border-zinc-200">
                    @forelse($assignedTasks ?? [] as $task)
                        <a href="{{ route('field-service.jobs.show', $task->job) }}" class="grid min-h-16 grid-cols-[8px_minmax(0,1fr)_auto] items-center gap-3 py-3">
                            <span class="h-9 rounded-full {{ $task->priority === 'urgent' ? 'bg-rose-500' : ($task->priority === 'high' ? 'bg-amber-500' : 'bg-emerald-400') }}"></span>
                            <span class="min-w-0">
                                <strong class="block truncate text-zinc-950">{{ $task->title }}</strong>
                                <small class="mt-1 block truncate text-zinc-500">
                                    {{ $task->job?->title }}
                                    @if($task->status === 'waiting')
                                        · Waiting
                                    @endif
                                    @if($task->due_at)
                                        · {{ $task->due_at->format('M j, g:ia') }}
                                    @endif
                                </small>
                            </span>
                            <span class="text-sm font-semibold text-emerald-800">Open →</span>
                        </a>
                    @empty
                        <p class="py-6 text-sm text-zinc-600">No open tasks assigned to you.</p>
                    @endforelse
                </div>
                @if(($assignedTaskTotal ?? 0) > 50)<p class="mt-3 text-xs text-zinc-500">Showing the first 50 tasks in priority and due-date order. Use the mobile assigned-task feed to continue loading.</p>@endif
            </section>
        </div>
        @vite('resources/js/field-service/jobs-grid.tsx')
    </flux:main>
</x-layouts::app.sidebar>
