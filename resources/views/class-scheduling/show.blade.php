@php
    $isFrontYardFoods = $tenant->slug === 'front-yard-foods';
@endphp

<x-layouts::app.sidebar title="{{ $scheduledClass->title }}">
    <div class="mx-auto w-full max-w-[1400px] space-y-6 px-4 py-5 sm:px-6">
        @if(session('status'))<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">{{ session('status') }}</div>@endif
        <a href="{{ route('class-scheduling.index', ['month' => $scheduledClass->starts_at->format('Y-m')]) }}" class="text-sm font-semibold text-emerald-800">← Back to {{ $isFrontYardFoods ? 'events & classes' : 'class' }} calendar</a>

        <section class="mf-app-card overflow-hidden rounded-3xl">
            @if($scheduledClass->image_url)<img src="{{ $scheduledClass->image_url }}" alt="{{ $scheduledClass->title }}" class="h-56 w-full object-cover">@endif
            <div class="grid gap-5 p-5 sm:p-7 lg:grid-cols-[1fr_auto]">
                <div><div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-emerald-800">{{ $scheduledClass->category ?: ($isFrontYardFoods ? 'Event or class' : 'Class') }}</div><h1 class="mt-2 text-3xl font-semibold text-zinc-950">{{ $scheduledClass->title }}</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-zinc-600">{{ $scheduledClass->description }}</p>@if($isFrontYardFoods)<div class="mt-4 inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-900">Shopify publishing pending connection</div>@endif</div>
                <div class="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-950"><div class="font-semibold">{{ $scheduledClass->starts_at->format('D, M j · g:i A') }}</div><div class="mt-1">{{ $scheduledClass->location ?: 'Location pending' }}</div><div class="mt-3 text-2xl font-semibold">{{ $scheduledClass->seats_taken }}/{{ $scheduledClass->capacity }}</div><div class="text-xs text-emerald-800">seats enrolled</div></div>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
            <section class="mf-app-card rounded-3xl p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3"><div><h2 class="text-xl font-semibold text-zinc-950">Customers</h2><p class="mt-1 text-sm text-zinc-600">Open a customer record, message them, or schedule another reminder.</p></div><a href="{{ route('marketing.messages.send') }}" class="fb-btn fb-btn-secondary">Open message composer</a></div>
                <div class="mt-5 divide-y divide-zinc-200">
                    @forelse($scheduledClass->enrollments as $enrollment)
                        <article class="py-5 first:pt-0">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div><h3 class="font-semibold text-zinc-950">{{ $enrollment->name }}</h3><p class="mt-1 text-sm text-zinc-600">{{ $enrollment->email }}{{ $enrollment->phone ? ' · '.$enrollment->phone : '' }} · {{ $enrollment->seats }} {{ Str::plural('seat', $enrollment->seats) }}</p><div class="mt-2 flex flex-wrap gap-2">@foreach($enrollment->reminders as $reminder)<span class="rounded-full border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-zinc-600">{{ $reminder->channel }} · {{ $reminder->scheduled_for->format('M j g:i A') }} · {{ $reminder->status }}</span>@endforeach</div></div>
                                @if($enrollment->customer)<a href="{{ route('marketing.customers.show', $enrollment->customer) }}" class="text-sm font-semibold text-emerald-800">Open customer →</a>@endif
                            </div>
                            <form method="POST" action="{{ route('class-scheduling.reminders.store', $enrollment) }}" class="mt-4 grid gap-2 rounded-2xl bg-zinc-50 p-3 sm:grid-cols-[120px_1fr_1fr_auto]">
                                @csrf
                                <select name="channel" class="rounded-xl border-zinc-200 text-sm"><option value="email">Email</option><option value="sms">Text</option></select>
                                <input type="datetime-local" name="scheduled_for" required max="{{ $scheduledClass->starts_at->copy()->subMinute()->format('Y-m-d\TH:i') }}" class="rounded-xl border-zinc-200 text-sm">
                                <input name="message" placeholder="Optional reminder note" class="rounded-xl border-zinc-200 text-sm">
                                <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white">Schedule</button>
                            </form>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-zinc-300 p-6 text-sm text-zinc-600">No customers are enrolled yet.</div>
                    @endforelse
                </div>
            </section>

            <section class="mf-app-card rounded-3xl p-5 sm:p-6">
                <h2 class="text-xl font-semibold text-zinc-950">{{ $isFrontYardFoods ? 'Event/class settings' : 'Class settings' }}</h2>
                <form method="POST" action="{{ route('class-scheduling.update', $scheduledClass) }}" class="mt-5 space-y-4">
                    @csrf @method('PUT')
                    <label class="block text-sm font-medium text-zinc-700">Name<input name="title" required value="{{ $scheduledClass->title }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label>
                    <div class="grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium text-zinc-700">Category<input name="category" value="{{ $scheduledClass->category }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label><label class="text-sm font-medium text-zinc-700">Location<input name="location" value="{{ $scheduledClass->location }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label></div>
                    <div class="grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium text-zinc-700">Starts<input type="datetime-local" name="starts_at" value="{{ $scheduledClass->starts_at->format('Y-m-d\TH:i') }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label><label class="text-sm font-medium text-zinc-700">Ends<input type="datetime-local" name="ends_at" value="{{ $scheduledClass->ends_at?->format('Y-m-d\TH:i') }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label></div>
                    <div class="grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium text-zinc-700">Capacity<input type="number" min="1" name="capacity" value="{{ $scheduledClass->capacity }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label><label class="text-sm font-medium text-zinc-700">Price<input type="number" min="0" step="0.01" name="price" value="{{ $scheduledClass->price }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label></div>
                    <input type="hidden" name="reminder_hours" value="{{ collect($scheduledClass->reminder_offsets)->first() ?: 24 }}"><input type="hidden" name="image_url" value="{{ $scheduledClass->image_url }}">
                    <label class="block text-sm font-medium text-zinc-700">Status<select name="status" class="mt-1.5 w-full rounded-xl border-zinc-200">@foreach(['draft','published','cancelled','complete'] as $status)<option value="{{ $status }}" @selected($scheduledClass->status === $status)>{{ Str::headline($status) }}</option>@endforeach</select></label>
                    <label class="block text-sm font-medium text-zinc-700">Description<textarea name="description" rows="5" class="mt-1.5 w-full rounded-xl border-zinc-200">{{ $scheduledClass->description }}</textarea></label>
                    <label class="flex items-center gap-2 text-sm text-zinc-700"><input type="checkbox" name="registration_open" value="1" @checked($scheduledClass->registration_open) class="rounded border-zinc-300 text-emerald-700">Registration open</label>
                    <button class="fb-btn fb-btn-primary">{{ $isFrontYardFoods ? 'Save event or class' : 'Save class' }}</button>
                </form>
                <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs leading-5 text-amber-950">Reminders are queued here. Email/text delivery still requires this workspace’s provider readiness and the customer’s consent.@if($isFrontYardFoods) Publishing this event to Shopify is disabled until the Shopify site is connected and mapped.@endif</div>
            </section>
        </div>
    </div>
</x-layouts::app.sidebar>
