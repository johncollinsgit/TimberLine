<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-900">Custom Module Requests</h1>
    </x-slot>

    <div class="space-y-6">
        @if (session('status'))
            <section class="rounded-xl border border-zinc-300 bg-zinc-50 px-4 py-3 text-sm text-zinc-900">
                {{ session('status') }}
            </section>
        @endif

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Everbranch Admin</p>
                    <h2 class="mt-1 text-2xl font-semibold text-zinc-950">Custom module request triage</h2>
                    <p class="mt-2 max-w-3xl text-sm text-zinc-600">
                        Intake and discovery queue only. Status updates do not create modules, install modules, change feature access, generate quotes or invoices, or activate billing.
                    </p>
                </div>
                <a href="{{ route('landlord.dashboard') }}" class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Back to Dashboard</a>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach($filterOptions as $key => $label)
                    <a
                        href="{{ route('landlord.custom-module-requests.index', ['filter' => $key]) }}"
                        class="rounded-full border px-3 py-1.5 text-xs font-semibold {{ $activeFilter === $key ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-100' }}"
                    >
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <div class="border-b border-zinc-200 px-5 py-4">
                <h3 class="text-base font-semibold text-zinc-950">Requests</h3>
                <p class="mt-1 text-xs text-zinc-600">Landlord notes are internal and are not shown to tenants.</p>
            </div>
            <div class="divide-y divide-zinc-200">
                @forelse($requests as $customRequest)
                    <article class="p-5">
                        <div class="grid gap-4 xl:grid-cols-[1fr_360px]">
                            <div class="space-y-3 text-sm text-zinc-700">
                                <div>
                                    <div class="text-base font-semibold text-zinc-950">{{ $customRequest->title }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">
                                        Tenant: {{ $customRequest->tenant?->name ?? 'Unknown tenant' }}
                                        · Status: {{ $statusLabels[$customRequest->status] ?? $customRequest->statusLabel() }}
                                        · Submitted {{ optional($customRequest->created_at)->toFormattedDateString() }}
                                    </div>
                                </div>
                                <p class="whitespace-pre-line">{{ $customRequest->problem_summary }}</p>
                                <div class="flex flex-wrap gap-2 text-xs">
                                    @if(filled($customRequest->related_module_key))
                                        <span class="rounded-full border border-zinc-300 px-2.5 py-1">Related: {{ $customRequest->related_module_key }}</span>
                                    @endif
                                    <span class="rounded-full border border-zinc-300 px-2.5 py-1">Mobile: {{ $customRequest->mobileRelevanceLabel() }}</span>
                                    <span class="rounded-full border border-zinc-300 px-2.5 py-1">Reusable: {{ $customRequest->reusable_module_interest ? 'yes' : 'no' }}</span>
                                    @if(filled($customRequest->urgency))
                                        <span class="rounded-full border border-zinc-300 px-2.5 py-1">Urgency: {{ str($customRequest->urgency)->replace('_', ' ')->headline() }}</span>
                                    @endif
                                </div>
                                @if(filled($customRequest->next_action))
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs">Next action: {{ $customRequest->next_action }}</div>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('landlord.custom-module-requests.update', ['customModuleRequest' => $customRequest]) }}" class="space-y-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                @csrf
                                <label class="block text-xs font-semibold text-zinc-700">
                                    Status
                                    <select name="status" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">
                                        @foreach($statusLabels as $key => $label)
                                            <option value="{{ $key }}" @selected($customRequest->status === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-xs font-semibold text-zinc-700">
                                    Next action
                                    <input name="next_action" value="{{ old('next_action', $customRequest->next_action) }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">
                                </label>
                                <label class="block text-xs font-semibold text-zinc-700">
                                    Landlord notes
                                    <textarea name="landlord_notes" rows="4" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">{{ old('landlord_notes', $customRequest->landlord_notes) }}</textarea>
                                </label>
                                <button type="submit" class="rounded-md bg-zinc-900 px-3 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Update triage</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <div class="p-8 text-center text-sm text-zinc-500">No custom module requests match this filter.</div>
                @endforelse
            </div>
        </section>
    </div>
</x-app-layout>
