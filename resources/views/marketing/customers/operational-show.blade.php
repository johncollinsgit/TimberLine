@php
    $customerName = trim((string) ($profile->first_name.' '.$profile->last_name));
    $customerName = $customerName !== '' ? $customerName : ($profile->email ?: ($profile->phone ?: 'Customer'));
    $address = trim(implode(', ', array_filter([$profile->address_line_1, $profile->address_line_2, $profile->city, $profile->state, $profile->postal_code])));
@endphp

<x-layouts::app.sidebar :title="$customerName">
    <flux:main>
        <div class="fb-workflow-shell fb-workflow-shell--wide space-y-6">
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-zinc-200 pb-5">
                <div>
                    <div class="fb-eyebrow">Customer</div>
                    <h1 class="text-3xl font-semibold text-zinc-950">{{ $customerName }}</h1>
                    <p class="mt-2 text-sm text-zinc-600">Contact details, service address, and job history.</p>
                    @if($isArchived)
                        <div class="mt-3 inline-flex rounded-full border border-amber-300 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-900">Archived — hidden from the active directory</div>
                    @endif
                </div>
                <a href="{{ route('marketing.customers') }}" class="fb-btn fb-btn-secondary">Back to customers</a>
            </header>

            @if (session('toast.message'))
                <div class="fb-state {{ session('toast.style') === 'success' ? 'fb-state-success' : 'fb-state-warning' }}">{{ session('toast.message') }}</div>
            @endif

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <section class="space-y-6">
                    <section class="border border-zinc-200 bg-white p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-zinc-950">Contact and service address</h2>
                                <p class="mt-1 text-sm text-zinc-600">Keep this current so new jobs start with the right customer information.</p>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('marketing.customers.update', $profile) }}" class="mt-5 space-y-4">
                            @csrf
                            @method('PATCH')
                            <div class="grid gap-3 md:grid-cols-2">
                                <input type="text" name="first_name" value="{{ old('first_name', $profile->first_name) }}" placeholder="First name" class="rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-950">
                                <input type="text" name="last_name" value="{{ old('last_name', $profile->last_name) }}" placeholder="Last name" class="rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-950">
                                <input type="email" name="email" value="{{ old('email', $profile->email) }}" placeholder="Email" class="rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-950">
                                <input type="text" name="phone" value="{{ old('phone', $profile->phone) }}" placeholder="Phone" class="rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-950">
                                <input type="text" name="address_line_1" value="{{ old('address_line_1', $profile->address_line_1) }}" placeholder="Service address" class="rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-950 md:col-span-2">
                                <input type="text" name="address_line_2" value="{{ old('address_line_2', $profile->address_line_2) }}" placeholder="Address line 2" class="rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-950 md:col-span-2">
                                <input type="text" name="city" value="{{ old('city', $profile->city) }}" placeholder="City" class="rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-950">
                                <input type="text" name="state" value="{{ old('state', $profile->state) }}" placeholder="State" class="rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-950">
                                <input type="text" name="postal_code" value="{{ old('postal_code', $profile->postal_code) }}" placeholder="ZIP code" class="rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-950">
                                <input type="text" name="country" value="{{ old('country', $profile->country) }}" placeholder="Country" class="rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-950">
                            </div>
                            <textarea name="notes" rows="3" placeholder="Office notes" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-950">{{ old('notes', $profile->notes) }}</textarea>
                            <button type="submit" class="fb-btn fb-btn-primary">Save customer</button>
                        </form>
                    </section>

                    <section class="border border-zinc-200 bg-white p-5">
                        <div class="flex items-end justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-zinc-950">Job history</h2>
                                <p class="mt-1 text-sm text-zinc-600">{{ $jobs->count() }} linked job{{ $jobs->count() === 1 ? '' : 's' }}.</p>
                            </div>
                            <a href="{{ route('field-service.index', ['view' => 'list']) }}" class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">Open work</a>
                        </div>
                        <div class="mt-4 divide-y divide-zinc-200 border-y border-zinc-200">
                            @forelse($jobs as $job)
                                <a href="{{ route('field-service.jobs.show', $job) }}" class="grid gap-2 px-1 py-3 transition hover:bg-zinc-50 md:grid-cols-[1fr_auto]">
                                    <div>
                                        <div class="font-semibold text-zinc-950">{{ $job->title }}</div>
                                        <div class="mt-1 text-sm text-zinc-600">{{ optional($job->scheduled_for)->format('M j, Y g:ia') ?: 'Not scheduled' }} · {{ $job->assignedUser?->name ?: 'Unassigned' }}</div>
                                    </div>
                                    <div class="text-sm font-medium text-zinc-600">{{ ucfirst(str_replace('_', ' ', $job->operational_status ?: 'needs_details')) }}</div>
                                </a>
                            @empty
                                <div class="py-8 text-center text-sm text-zinc-500">No jobs are linked to this customer yet.</div>
                            @endforelse
                        </div>
                    </section>
                </section>

                <aside class="space-y-4">
                    <section class="border border-zinc-200 bg-zinc-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">Current address</div>
                        <div class="mt-2 text-sm leading-6 text-zinc-800">{{ $address !== '' ? $address : 'No service address saved' }}</div>
                    </section>
                    <section class="border border-zinc-200 bg-zinc-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">Directory controls</div>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">Archiving is available from the customer list. It keeps jobs and history intact and removes the customer from the active directory.</p>
                    </section>
                </aside>
            </div>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
