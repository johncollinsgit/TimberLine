<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Landlord Tenants</h1>
    </x-slot>

    <div class="space-y-6">
        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Landlord</div>
                    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Tenant Directory</div>
                    <p class="mt-2 text-sm text-emerald-50/70">
                        Read-only tenant index. Status and readiness values are derived from existing access, user, Shopify, and health data.
                    </p>
                </div>
                <a
                    href="{{ route('landlord.dashboard') }}"
                    class="inline-flex items-center rounded-full border border-emerald-400/30 bg-emerald-500/15 px-4 py-2 text-xs font-semibold text-white/90"
                >
                    Back to Dashboard
                </a>
            </div>

            <div class="mt-4 rounded-2xl border border-emerald-200/10 bg-black/20 p-4">
                <p class="text-xs uppercase tracking-[0.12em] text-emerald-100/60">Tenant Operations Selector</p>
                <p class="mt-1 text-xs text-emerald-50/70">
                    Select tenant context explicitly before opening guarded landlord operations.
                </p>
                <form method="POST" action="{{ route('landlord.tenants.select') }}" class="mt-3 flex flex-wrap items-center gap-2">
                    @csrf
                    <select
                        name="tenant"
                        class="min-w-[18rem] rounded-lg border border-emerald-200/20 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50"
                    >
                        @foreach ($tenants as $row)
                            <option value="{{ $row['id'] }}">
                                {{ $row['name'] }} ({{ $row['slug'] }})
                            </option>
                        @endforeach
                    </select>
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-lg border border-emerald-300/40 bg-emerald-500/15 px-4 py-2 text-xs font-semibold text-emerald-50"
                    >
                        Open Tenant Operations
                    </button>
                </form>
                @error('tenant')
                    <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="overflow-hidden rounded-3xl border border-emerald-200/10 bg-[#101513]/80">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1100px] divide-y divide-emerald-200/10 text-sm">
                    <thead class="bg-white/5 text-left text-xs uppercase tracking-[0.12em] text-emerald-100/60">
                        <tr>
                            <th class="px-4 py-3">Tenant</th>
                            <th class="px-4 py-3">Slug / Subdomain</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3 text-right">Users</th>
                            <th class="px-4 py-3 text-right">Connected Shopify Stores</th>
                            <th class="px-4 py-3">Primary Store Domain</th>
                            <th class="px-4 py-3">Health / Readiness</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-emerald-200/10">
                        @forelse ($tenants as $row)
                            <tr class="align-top text-emerald-50/85">
                                <td class="px-4 py-3">
                                    <a
                                        href="{{ route('landlord.tenants.show', ['tenant' => $row['id']]) }}"
                                        class="font-semibold text-white hover:text-emerald-200"
                                    >
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-mono text-xs text-emerald-100/90">{{ $row['slug'] }}</div>
                                    <div class="mt-1 text-xs text-emerald-50/60">{{ $row['subdomain'] }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-white">{{ $row['status_label'] }}</div>
                                    <div class="mt-1 text-xs text-emerald-50/60">{{ $row['status'] }}</div>
                                </td>
                                <td class="px-4 py-3 text-emerald-50/70">{{ $row['created_at'] ?? 'n/a' }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-white">{{ number_format((int) $row['user_count']) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-white">{{ number_format((int) $row['connected_shopify_stores_count']) }}</td>
                                <td class="px-4 py-3">
                                    @if ($row['primary_shopify_domain'])
                                        <div class="font-mono text-xs text-emerald-100/90">{{ $row['primary_shopify_domain'] }}</div>
                                        <div class="mt-1 text-xs text-emerald-50/60">key: {{ $row['primary_store_key'] ?? 'n/a' }}</div>
                                    @else
                                        <span class="text-emerald-50/60">n/a</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-emerald-50/70">
                                    <div>Users: {{ $row['health']['has_users'] ? 'yes' : 'no' }}</div>
                                    <div>Shopify connected: {{ $row['health']['has_connected_shopify_store'] ? 'yes' : 'no' }}</div>
                                    <div>Access profile: {{ $row['health']['has_access_profile'] ? 'yes' : 'no' }}</div>
                                    <div>Open integration issues: {{ (int) $row['health']['open_integration_health_events'] }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-5 text-sm text-emerald-50/65">
                                    No tenants found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
