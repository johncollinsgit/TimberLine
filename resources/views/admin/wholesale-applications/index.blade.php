<x-app-layout>
    <x-slot name="header">
        <div>
            <div>
                <h1 class="text-xl font-semibold">Wholesale Applications</h1>
                <p class="mt-1 text-sm text-zinc-600">Simple review inbox for Modern Forestry Wholesale applications.</p>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <section class="fb-page-surface fb-page-surface--subtle p-6">
            <div class="fb-kpi-label">Wholesale inbox</div>
            <h2 class="mt-2 text-3xl font-semibold text-zinc-950">Review applications in one place</h2>
            <p class="mt-2 text-sm text-zinc-600">
                Submissions stay tied to the reusable forms system, but this page keeps day-to-day wholesale review quick.
            </p>

            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">Pending</div>
                    <div class="mt-2 text-3xl font-semibold text-amber-950">{{ number_format($summary['pending'] ?? 0) }}</div>
                </div>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Approved</div>
                    <div class="mt-2 text-3xl font-semibold text-emerald-950">{{ number_format($summary['approved'] ?? 0) }}</div>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-700">Rejected</div>
                    <div class="mt-2 text-3xl font-semibold text-zinc-950">{{ number_format($summary['rejected'] ?? 0) }}</div>
                </div>
            </div>
        </section>

        <section class="fb-page-surface p-6">
            <form method="GET" action="{{ route('admin.wholesale.applications') }}" class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="grid flex-1 gap-3 md:grid-cols-[minmax(0,1fr)_220px]">
                    <label class="space-y-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Search</span>
                        <input
                            type="text"
                            name="search"
                            value="{{ $search }}"
                            placeholder="Name, email, or company"
                            class="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm text-zinc-900 shadow-sm focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-200"
                        >
                    </label>
                    <label class="space-y-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Status</span>
                        <select
                            name="status"
                            class="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm text-zinc-900 shadow-sm focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-200"
                        >
                            @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All statuses'] as $value => $label)
                                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-full bg-zinc-950 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800">
                        Apply filters
                    </button>
                    <a href="{{ route('admin.wholesale.applications') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                        Reset
                    </a>
                </div>
            </form>
        </section>

        <section class="fb-page-surface overflow-hidden">
            <div class="border-b border-zinc-200 px-6 py-4">
                <div class="text-sm font-semibold text-zinc-950">
                    {{ $tenant?->name ?? 'Wholesale tenant' }}
                </div>
                <div class="mt-1 text-sm text-zinc-600">
                    Tenant slug: <span class="font-mono text-xs text-zinc-700">{{ $tenant?->slug ?? $tenantSlug }}</span>
                </div>
            </div>

            @if ($applications->count() === 0)
                <div class="px-6 py-12 text-sm text-zinc-600">
                    No wholesale applications matched this view yet.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200">
                        <thead class="bg-zinc-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">
                                <th class="px-6 py-3">Applicant</th>
                                <th class="px-6 py-3">Company</th>
                                <th class="px-6 py-3">Status</th>
                                <th class="px-6 py-3">Submitted</th>
                                <th class="px-6 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 bg-white">
                            @foreach ($applications as $application)
                                @php
                                    $badgeClasses = match ($application->status) {
                                        'approved' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                        'rejected' => 'border-rose-200 bg-rose-50 text-rose-700',
                                        default => 'border-amber-200 bg-amber-50 text-amber-700',
                                    };
                                @endphp
                                <tr>
                                    <td class="px-6 py-4 align-top">
                                        <div class="font-semibold text-zinc-950">{{ $application->name ?: 'Unknown applicant' }}</div>
                                        <div class="mt-1 text-sm text-zinc-600">{{ $application->email }}</div>
                                    </td>
                                    <td class="px-6 py-4 align-top text-sm text-zinc-700">
                                        {{ $application->company ?: '—' }}
                                    </td>
                                    <td class="px-6 py-4 align-top">
                                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $badgeClasses }}">
                                            {{ \Illuminate\Support\Str::headline((string) $application->status) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 align-top text-sm text-zinc-700">
                                        {{ optional($application->created_at)->format('M j, Y g:i A') ?: '—' }}
                                    </td>
                                    <td class="px-6 py-4 align-top text-right">
                                        <a href="{{ route('admin.wholesale.applications.show', $application) }}" class="rounded-full border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                                            Review
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-zinc-200 px-6 py-4">
                    {{ $applications->links() }}
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
