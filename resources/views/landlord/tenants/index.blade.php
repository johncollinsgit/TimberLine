<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-950">Landlord Tenants</h1>
    </x-slot>

    <div class="fb-page-canvas">
        <section class="fb-page-surface p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="text-[11px] uppercase tracking-[0.35em] text-zinc-500">Landlord</div>
                    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">Tenant Directory</div>
                    <p class="mt-2 text-sm text-zinc-600">
                        Read-only tenant index. Status and readiness values are derived from existing access, user, Shopify, and health data.
                    </p>
                </div>
                <a
                    href="{{ route('landlord.dashboard') }}"
                    class="fb-btn-soft fb-btn-accent"
                >
                    Back to Dashboard
                </a>
            </div>

            <div class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                <p class="text-xs uppercase tracking-[0.12em] text-zinc-600">Tenant Operations Selector</p>
                <p class="mt-1 text-xs text-zinc-600">
                    Select tenant context explicitly before opening guarded landlord operations.
                </p>
                <form method="POST" action="{{ route('landlord.tenants.select') }}" class="mt-3 flex flex-wrap items-center gap-2">
                    @csrf
                    <select
                        name="tenant"
                        class="min-w-[18rem] rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                    >
                        @foreach ($tenants as $row)
                            <option value="{{ $row['id'] }}">
                                {{ $row['name'] }} ({{ $row['slug'] }})
                            </option>
                        @endforeach
                    </select>
                    <button
                        type="submit"
                        class="fb-btn-soft fb-btn-accent"
                    >
                        Open Tenant Operations
                    </button>
                </form>
                @error('tenant')
                    <p class="mt-2 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1100px] divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-600">
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
                    <tbody class="divide-y divide-zinc-200">
                        @forelse ($tenants as $row)
                            <tr class="align-top text-zinc-700">
                                <td class="px-4 py-3">
                                    <a
                                        href="{{ route('landlord.tenants.show', ['tenant' => $row['id']]) }}"
                                        class="font-semibold text-zinc-900 hover:text-[var(--fb-brand)]"
                                    >
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-mono text-xs text-zinc-800">{{ $row['slug'] }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">{{ $row['subdomain'] }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-zinc-900">{{ $row['status_label'] }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">{{ $row['status'] }}</div>
                                </td>
                                <td class="px-4 py-3 text-zinc-600">{{ $row['created_at'] ?? 'n/a' }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-zinc-900">{{ number_format((int) $row['user_count']) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-zinc-900">{{ number_format((int) $row['connected_shopify_stores_count']) }}</td>
                                <td class="px-4 py-3">
                                    @if ($row['primary_shopify_domain'])
                                        <div class="font-mono text-xs text-zinc-800">{{ $row['primary_shopify_domain'] }}</div>
                                        <div class="mt-1 text-xs text-zinc-500">key: {{ $row['primary_store_key'] ?? 'n/a' }}</div>
                                    @else
                                        <span class="text-zinc-500">n/a</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-600">
                                    <div>Users: {{ $row['health']['has_users'] ? 'yes' : 'no' }}</div>
                                    <div>Shopify connected: {{ $row['health']['has_connected_shopify_store'] ? 'yes' : 'no' }}</div>
                                    <div>Access profile: {{ $row['health']['has_access_profile'] ? 'yes' : 'no' }}</div>
                                    <div>Open integration issues: {{ (int) $row['health']['open_integration_health_events'] }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-5 text-sm text-zinc-600">
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
