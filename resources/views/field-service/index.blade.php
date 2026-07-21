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
                        @if($equipmentMaintenanceEnabled ?? false)<a href="{{ route('field-service.equipment.index') }}" class="fb-btn fb-btn-secondary">Equipment</a>@endif
                        @if(data_get($capabilities ?? [], 'create_jobs'))<a href="#new-job" class="fb-btn fb-btn-primary">Create job</a>@endif
                    </div>
                </div>
            </header>

            <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-3 shadow-sm sm:p-5">
                <div
                    id="field-service-jobs-grid"
                    data-endpoint="{{ route('field-service.jobs.data') }}"
                    data-update-template="{{ route('field-service.jobs.update', ['job' => 0]) }}"
                    data-candidate-template="{{ route('field-service.work-candidates.review', ['candidate' => 0]) }}"
                    data-can-manage="{{ data_get($capabilities ?? [], 'manage_jobs') ? '1' : '0' }}"
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
                    <form method="POST" action="{{ route('field-service.jobs.store') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @csrf
                        <input name="customer_name" required class="rounded-xl border border-zinc-300 px-3 py-3 text-sm" placeholder="Customer name">
                        <input name="title" required class="rounded-xl border border-zinc-300 px-3 py-3 text-sm" placeholder="Job title">
                        <input name="customer_phone" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm" placeholder="Customer phone">
                        <input name="service_address_line_1" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm" placeholder="Service address">
                        <input name="scheduled_for" type="datetime-local" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm">
                        <select name="assigned_user_id" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm">
                            <option value="">Unassigned</option>
                            @foreach($team as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                        </select>
                        <textarea name="description" rows="2" class="rounded-xl border border-zinc-300 px-3 py-3 text-sm md:col-span-2" placeholder="Scope, access notes, or instructions"></textarea>
                        <button type="submit" class="fb-btn fb-btn-primary min-h-11 justify-center">Create job</button>
                    </form>
                </section>
            @endif
        </div>
        @vite('resources/js/field-service/jobs-grid.tsx')
    </flux:main>
</x-layouts::app.sidebar>
