<x-app-layout>
    @php
        $filters = is_array($filters ?? null) ? $filters : [];
        $rows = is_array($rows ?? null) ? $rows : [];
        $meta = is_array($meta ?? null) ? $meta : [];
        $setupRows = is_array($setupRows ?? null) ? $setupRows : [];
        $setupOptions = is_array($setupOptions ?? null) ? $setupOptions : [];
        $reviewStatuses = is_array($setupOptions['landlord_review_statuses'] ?? null) ? $setupOptions['landlord_review_statuses'] : [];
        $stuckPoint = (string) ($filters['stuck_point'] ?? '');
        $phase = (string) ($filters['phase'] ?? '');
        $formatDuration = static function (?int $seconds): string {
            if ($seconds === null) {
                return '—';
            }

            if ($seconds < 60) {
                return $seconds.'s';
            }

            if ($seconds < 3600) {
                return (string) ((int) round($seconds / 60)).'m';
            }

            return number_format($seconds / 3600, 1).'h';
        };
    @endphp

    <div class="space-y-6">
        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <header class="border-b border-zinc-200 bg-white/95 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Landlord</p>
                <h2 class="mt-1 text-2xl font-semibold text-zinc-950">Onboarding Journey Diagnostics</h2>
                <p class="mt-1 text-sm font-medium text-zinc-700">Journey milestones (read-only)</p>
                <p class="mt-1 max-w-4xl text-sm text-zinc-600">
                    Reduced from append-only onboarding journey telemetry. Use this to spot where tenants stall without introducing a second onboarding state model.
                </p>
            </header>

            <div class="space-y-6 p-6">
                @if (session('status'))
                    <section class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                        {{ session('status') }}
                    </section>
                @endif

                <section class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4" data-landlord-setup-status="true">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-zinc-950">Client setup status</h3>
                            <p class="mt-1 max-w-4xl text-sm text-zinc-600">
                                Readiness skeleton for import path, Shopify connection, Square/CSV/manual setup, module interests, and future Android/iOS mobile intent. This does not activate checkout or connector automation.
                            </p>
                        </div>
                        <span class="rounded-full border border-zinc-300 bg-white px-3 py-1 text-xs font-semibold text-zinc-700">
                            {{ count($setupRows) }} tenant{{ count($setupRows) === 1 ? '' : 's' }}
                        </span>
                    </div>

                    <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200 bg-white">
                        <table class="w-full divide-y divide-zinc-200 text-sm">
                            <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-600">
                                <tr>
                                    <th class="px-4 py-3">Tenant</th>
                                    <th class="px-4 py-3">Import</th>
                                    <th class="px-4 py-3">Connections</th>
                                    <th class="px-4 py-3">Modules / mobile</th>
                                    <th class="px-4 py-3">Review</th>
                                    <th class="px-4 py-3">Next action / notes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200">
                                @forelse($setupRows as $setupRow)
                                    @php
                                        $tenantPayload = is_array($setupRow['tenant'] ?? null) ? (array) $setupRow['tenant'] : [];
                                        $tenantId = (int) ($tenantPayload['id'] ?? 0);
                                        $moduleLabels = (array) ($setupRow['module_interest_labels'] ?? []);
                                    @endphp
                                    <tr class="align-top text-zinc-700">
                                        <td class="px-4 py-3">
                                            <a href="{{ route('landlord.tenants.show', ['tenant' => $tenantId]) }}" class="font-semibold text-zinc-950 hover:text-zinc-600">
                                                {{ $tenantPayload['name'] ?? 'Tenant' }}
                                            </a>
                                            <div class="mt-1 font-mono text-xs text-zinc-500">{{ $tenantPayload['slug'] ?? '' }}</div>
                                            <div class="mt-2 text-xs text-zinc-600">Business: {{ $setupRow['business_profile_label'] ?? 'Not started' }}</div>
                                            @if (! empty($setupRow['source_access_request_label']))
                                                <div class="mt-1 text-xs font-semibold text-zinc-700">{{ $setupRow['source_access_request_label'] }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="font-semibold text-zinc-950">{{ $setupRow['import_path_label'] ?? 'Undecided' }}</div>
                                            <div class="mt-1 text-xs text-zinc-600">CSV/manual: {{ $setupRow['csv_manual_label'] ?? 'Not started' }}</div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div>Shopify: <span class="font-semibold text-zinc-950">{{ $setupRow['shopify_connection_label'] ?? 'Not connected' }}</span></div>
                                            <div class="mt-1">Square: <span class="font-semibold text-zinc-950">{{ $setupRow['square_label'] ?? 'Not requested' }}</span></div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div>Mobile: <span class="font-semibold text-zinc-950">{{ $setupRow['mobile_interest_label'] ?? 'Undecided' }}</span></div>
                                            <div class="mt-2 flex max-w-sm flex-wrap gap-1">
                                                @forelse($moduleLabels as $label)
                                                    <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 text-xs">{{ $label }}</span>
                                                @empty
                                                    <span class="text-xs text-zinc-500">No module interests yet</span>
                                                @endforelse
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-700">
                                                {{ $setupRow['landlord_review_label'] ?? 'Pending review' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <form method="POST" action="{{ route('landlord.onboarding.setup-status.update', ['tenant' => $tenantId]) }}" class="space-y-2">
                                                @csrf
                                                <label class="block text-xs font-semibold text-zinc-600">
                                                    Review status
                                                    <select name="landlord_review_status" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-xs text-zinc-900">
                                                    @foreach($reviewStatuses as $key => $label)
                                                        <option value="{{ $key }}" @selected(($setupRow['landlord_review_status'] ?? 'pending_review') === $key)>{{ $label }}</option>
                                                    @endforeach
                                                    </select>
                                                </label>
                                                <label class="block text-xs font-semibold text-zinc-600">
                                                    Next action
                                                    <input
                                                        type="text"
                                                        name="next_recommended_action"
                                                        value="{{ $setupRow['next_recommended_action'] ?? '' }}"
                                                        class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-xs text-zinc-900"
                                                    />
                                                </label>
                                                <label class="mt-2 block text-xs font-semibold text-zinc-600">
                                                    Internal notes
                                                    <textarea name="internal_notes" rows="2" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-xs text-zinc-900">{{ $setupRow['internal_notes'] ?? '' }}</textarea>
                                                </label>
                                                <button type="submit" class="mt-2 rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800">
                                                    Save review
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-6 text-sm text-zinc-600">No tenants found for setup status review.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <form method="GET" action="{{ route('landlord.onboarding.journey') }}" class="grid gap-3 lg:grid-cols-6">
                    <div class="lg:col-span-1">
                        <label class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">Tenant ID</label>
                        <input
                            type="number"
                            name="tenant_id"
                            value="{{ $filters['tenant_id'] ?? '' }}"
                            class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                            min="1"
                        />
                    </div>
                    <div class="lg:col-span-1">
                        <label class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">Blueprint ID</label>
                        <input
                            type="number"
                            name="final_blueprint_id"
                            value="{{ $filters['final_blueprint_id'] ?? '' }}"
                            class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                            min="1"
                        />
                    </div>
                    <div class="lg:col-span-1">
                        <label class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">From</label>
                        <input
                            type="date"
                            name="from"
                            value="{{ $filters['from'] ?? '' }}"
                            class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                        />
                    </div>
                    <div class="lg:col-span-1">
                        <label class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">To</label>
                        <input
                            type="date"
                            name="to"
                            value="{{ $filters['to'] ?? '' }}"
                            class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                        />
                    </div>
                    <div class="lg:col-span-1">
                        <label class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">Stuck point</label>
                        <select
                            name="stuck_point"
                            class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                        >
                            <option value="">Any</option>
                            @foreach (['waiting_for_first_open','waiting_for_import','waiting_for_activation','progressing','completed_first_value'] as $option)
                                <option value="{{ $option }}" @selected($stuckPoint === $option)>{{ str_replace('_', ' ', $option) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-1">
                        <label class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">Latest phase</label>
                        <select
                            name="phase"
                            class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                        >
                            <option value="">Any</option>
                            @foreach (['handoff','first_session','ongoing_setup'] as $option)
                                <option value="{{ $option }}" @selected($phase === $option)>{{ str_replace('_', ' ', $option) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-6 flex flex-wrap items-center justify-between gap-2 pt-1">
                        <div class="text-xs text-zinc-600">
                            Window: <span class="font-mono">{{ $meta['from'] ?? 'n/a' }}</span> → <span class="font-mono">{{ $meta['to'] ?? 'n/a' }}</span>
                            · Results: {{ (int) ($meta['result_count'] ?? count($rows)) }}
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="submit"
                                class="inline-flex items-center rounded-lg bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800"
                            >
                                Apply filters
                            </button>
                            <a
                                href="{{ route('landlord.onboarding.journey') }}"
                                class="inline-flex items-center rounded-lg border border-zinc-300 bg-white px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                            >
                                Reset
                            </a>
                        </div>
                    </div>
                </form>

                <div class="overflow-hidden rounded-xl border border-zinc-200">
                    <table class="w-full divide-y divide-zinc-200 text-sm">
                        <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-600">
                            <tr>
                                <th class="px-4 py-3">Tenant</th>
                                <th class="px-4 py-3">Blueprint</th>
                                <th class="px-4 py-3">Phase</th>
                                <th class="px-4 py-3">Stuck point</th>
                                <th class="px-4 py-3">Handoff</th>
                                <th class="px-4 py-3">First open</th>
                                <th class="px-4 py-3">Import start</th>
                                <th class="px-4 py-3">Import done</th>
                                <th class="px-4 py-3">First active module</th>
                                <th class="px-4 py-3">Durations</th>
                                <th class="px-4 py-3">Latest event</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 bg-white">
                            @forelse ($rows as $row)
                                <tr class="text-zinc-700">
                                    <td class="px-4 py-3">
                                        <a
                                            href="{{ route('landlord.tenants.show', ['tenant' => $row['tenant_id']]) }}"
                                            class="font-semibold text-zinc-900 hover:text-zinc-600"
                                        >
                                            {{ $row['tenant_name'] ?? ('Tenant #'.($row['tenant_id'] ?? '')) }}
                                        </a>
                                        <div class="mt-1 font-mono text-xs text-zinc-500">
                                            #{{ $row['tenant_id'] ?? '' }} @if(! empty($row['tenant_slug'])) · {{ $row['tenant_slug'] }} @endif
                                        </div>
                                        @if (! empty($row['final_blueprint_id']))
                                            <div class="mt-2">
                                                <a
                                                    href="{{ route('landlord.tenants.show', ['tenant' => $row['tenant_id'], 'tab' => 'onboarding_journey', 'final_blueprint_id' => $row['final_blueprint_id']]) }}"
                                                    class="text-xs font-semibold text-zinc-900 underline decoration-dotted underline-offset-2"
                                                >
                                                    View details
                                                </a>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs">
                                        {{ $row['final_blueprint_id'] ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-zinc-900">{{ $row['latest_phase'] ?? '—' }}</div>
                                        <div class="mt-1 font-mono text-xs text-zinc-500">{{ $row['latest_phase_changed_at'] ?? '—' }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-700">
                                            {{ str_replace('_', ' ', (string) ($row['stuck_point'] ?? 'unknown')) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $row['handoff_viewed_at'] ?? '—' }}</td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $row['first_open_acknowledged_at'] ?? '—' }}</td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $row['import_started_at'] ?? '—' }}</td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $row['import_completed_at'] ?? '—' }}</td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $row['first_active_module_reached_at'] ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        @php($durations = is_array($row['durations'] ?? null) ? (array) $row['durations'] : [])
                                        <div class="space-y-1 font-mono text-xs text-zinc-600">
                                            <div>H→Open: {{ $formatDuration($durations['handoff_to_first_open_seconds'] ?? null) }}</div>
                                            <div>Open→Import: {{ $formatDuration($durations['first_open_to_import_complete_seconds'] ?? null) }}</div>
                                            <div>Import→Active: {{ $formatDuration($durations['import_complete_to_first_active_module_seconds'] ?? null) }}</div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $row['latest_event_at'] ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="px-4 py-6 text-sm text-zinc-600">
                                        No journey events found for the current filter window.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</x-app-layout>
