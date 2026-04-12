<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-900">Landlord Console</h1>
    </x-slot>

    <div class="space-y-6">
        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <header class="sticky top-0 z-20 border-b border-zinc-200 bg-white/95 backdrop-blur">
                <div class="flex flex-wrap items-start justify-between gap-4 px-6 py-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Landlord</p>
                        <h2 class="mt-1 text-2xl font-semibold text-zinc-950">Landlord Operator Console</h2>
                        <p class="mt-1 max-w-3xl text-sm text-zinc-600">
                            Operational overview for tenant health, commercial configuration access, and guarded landlord-only actions.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a
                            href="{{ route('landlord.commercial.index') }}"
                            class="inline-flex items-center rounded-lg bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800"
                        >
                            Open Commercial Config
                        </a>
                        <a
                            href="{{ route('landlord.onboarding.journey') }}"
                            class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                        >
                            Onboarding Diagnostics
                        </a>
                        <a
                            href="{{ route('landlord.tenants.index') }}"
                            class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                        >
                            Open Tenant Directory
                        </a>
                    </div>
                </div>
                <nav class="overflow-x-auto border-t border-zinc-200 px-6 py-3">
                    <ul class="flex min-w-max items-center gap-2 text-xs font-medium text-zinc-600">
                        <li><a href="#overview" class="rounded-md border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100">Overview</a></li>
                        <li><a href="#recent-tenants" class="rounded-md border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100">Recent tenants</a></li>
                    </ul>
                </nav>
            </header>

            <div class="space-y-8 p-6">
                <section id="overview" class="space-y-4 scroll-mt-36">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-950">Overview</h3>
                        <p class="text-sm text-zinc-600">
                            Current landlord-host visibility across tenant health and connected Shopify stores.
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Total tenants</p>
                            <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($metrics['total_tenants'] ?? 0)) }}</p>
                        </article>
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Healthy</p>
                            <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($metrics['healthy_tenants'] ?? 0)) }}</p>
                        </article>
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Shopify connected</p>
                            <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($metrics['tenants_with_connected_shopify'] ?? 0)) }}</p>
                        </article>
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Needs attention</p>
                            <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($metrics['tenants_needing_attention'] ?? 0)) }}</p>
                        </article>
                    </div>
                </section>

                <section id="recent-tenants" class="space-y-4 scroll-mt-36">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-zinc-950">Recent tenants</h3>
                            <p class="text-sm text-zinc-600">Most recently created tenant records on landlord host.</p>
                        </div>
                        <a
                            href="{{ route('landlord.tenants.index') }}"
                            class="inline-flex items-center rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                        >
                            View full directory
                        </a>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">Tenant Operations Selector</p>
                        <p class="mt-1 text-xs text-zinc-600">
                            Select tenant context explicitly before running export/restore/customer actions.
                        </p>
                        <form method="POST" action="{{ route('landlord.tenants.select') }}" class="mt-3 flex flex-wrap items-center gap-2">
                            @csrf
                            <select
                                name="tenant"
                                class="min-w-[18rem] rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                                @if (collect($recent_tenants)->isEmpty()) disabled @endif
                            >
                                @foreach ($recent_tenants as $row)
                                    <option value="{{ $row['id'] }}">{{ $row['name'] }} ({{ $row['slug'] }})</option>
                                @endforeach
                            </select>
                            <button
                                type="submit"
                                class="inline-flex items-center rounded-lg border border-zinc-300 bg-white px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100 disabled:opacity-60"
                                @if (collect($recent_tenants)->isEmpty()) disabled @endif
                            >
                                Open Tenant Operations
                            </button>
                        </form>
                        @error('tenant')
                            <p class="mt-2 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="overflow-hidden rounded-xl border border-zinc-200">
                        <table class="w-full divide-y divide-zinc-200 text-sm">
                            <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-600">
                                <tr>
                                    <th class="px-4 py-3">Tenant</th>
                                    <th class="px-4 py-3">Slug</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Created</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 bg-white">
                                @forelse ($recent_tenants as $row)
                                    <tr class="text-zinc-700">
                                        <td class="px-4 py-3">
                                            <a
                                                href="{{ route('landlord.tenants.show', ['tenant' => $row['id']]) }}"
                                                class="font-semibold text-zinc-900 hover:text-zinc-600"
                                            >
                                                {{ $row['name'] }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs">{{ $row['slug'] }}</td>
                                        <td class="px-4 py-3">{{ $row['status_label'] }}</td>
                                        <td class="px-4 py-3">{{ $row['created_at'] ?? 'n/a' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-5 text-sm text-zinc-600">
                                            No tenants found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </section>
    </div>
</x-app-layout>
