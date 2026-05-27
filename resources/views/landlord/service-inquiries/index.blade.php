<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-900">Evergrove Service Inquiries</h1>
    </x-slot>

    <div class="space-y-6">
        <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-zinc-500">Landlord</p>
                    <h2 class="mt-2 text-3xl font-semibold text-zinc-950">Service Inquiry Queue</h2>
                    <p class="mt-2 max-w-3xl text-sm text-zinc-600">
                        Leads from Evergrove service pages and calculators, including any attached calculator planning payload.
                    </p>
                </div>
                <a href="{{ route('landlord.dashboard') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                    Back to dashboard
                </a>
            </div>

            <form method="GET" action="{{ route('landlord.service-inquiries.index') }}" class="mt-5 flex flex-wrap items-center gap-2">
                <label class="flex items-center gap-2 text-sm text-zinc-700">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">Status</span>
                    <select name="status" class="rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" onchange="this.form.submit()">
                        @foreach(($statusOptions ?? []) as $value => $label)
                            <option value="{{ $value }}" @selected(($activeStatus ?? 'all') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <noscript>
                    <button type="submit" class="rounded-xl border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Apply</button>
                </noscript>
            </form>
        </section>

        <section class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1120px] divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-500">
                        <tr>
                            <th class="px-4 py-3">Contact</th>
                            <th class="px-4 py-3">Company</th>
                            <th class="px-4 py-3">Context</th>
                            <th class="px-4 py-3">Calculator</th>
                            <th class="px-4 py-3">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white">
                        @forelse($inquiries as $inquiry)
                            @php
                                $payload = is_array($inquiry->calculator_payload ?? null) ? $inquiry->calculator_payload : [];
                                $range = isset($payload['low'], $payload['high'])
                                    ? '$'.number_format((int) $payload['low']).' - $'.number_format((int) $payload['high'])
                                    : null;
                            @endphp
                            <tr class="align-top text-zinc-700">
                                <td class="px-4 py-4">
                                    <div class="font-semibold text-zinc-950">{{ $inquiry->name }}</div>
                                    <a class="text-xs font-semibold text-zinc-700 hover:text-zinc-950" href="mailto:{{ $inquiry->email }}">{{ $inquiry->email }}</a>
                                    @if($inquiry->website)
                                        <div><a class="text-xs text-zinc-500 hover:text-zinc-900" href="{{ $inquiry->website }}" target="_blank" rel="noopener">{{ $inquiry->website }}</a></div>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    <div class="font-semibold text-zinc-900">{{ $inquiry->company ?: 'n/a' }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">{{ $inquiry->business_size ?: 'size unknown' }}</div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="max-w-md text-sm text-zinc-700">{{ $inquiry->pain_point ?: 'No notes yet.' }}</div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @if($inquiry->timeline)
                                            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 text-[11px] font-semibold text-zinc-600">{{ $inquiry->timeline }}</span>
                                        @endif
                                        @if($inquiry->budget_range)
                                            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 text-[11px] font-semibold text-zinc-600">{{ $inquiry->budget_range }}</span>
                                        @endif
                                        @if($inquiry->source_page)
                                            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 text-[11px] font-semibold text-zinc-600">{{ $inquiry->source_page }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    @if($payload !== [])
                                        <div class="font-semibold text-zinc-900">{{ $payload['tool'] ?? 'calculator' }}</div>
                                        @if($range)
                                            <div class="mt-1 text-sm text-zinc-700">{{ $range }}</div>
                                        @endif
                                        @if(! empty($payload['note']))
                                            <div class="mt-1 text-xs text-zinc-500">{{ $payload['note'] }}</div>
                                        @endif
                                    @else
                                        <span class="text-xs text-zinc-500">No calculator payload</span>
                                    @endif
                                </td>
                                <td class="px-4 py-4 text-xs text-zinc-500">
                                    {{ optional($inquiry->created_at)->format('M j, Y g:ia') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-sm text-zinc-500">No service inquiries yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 px-4 py-3">
                {{ $inquiries->links() }}
            </div>
        </section>
    </div>
</x-app-layout>
