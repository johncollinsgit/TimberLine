<x-app-layout>
    @php
        $rows = is_array($rows ?? null) ? $rows : [];
        $filterOptions = is_array($filterOptions ?? null) ? $filterOptions : [];
        $summary = is_array($summary ?? null) ? $summary : [];
        $activeFilter = (string) ($activeFilter ?? 'all');
        $setupOptions = is_array($setupOptions ?? null) ? $setupOptions : [];
        $reviewStatuses = is_array($setupOptions['landlord_review_statuses'] ?? null) ? $setupOptions['landlord_review_statuses'] : [];
        $commercialReviewStatuses = is_array($setupOptions['commercial_review_statuses'] ?? null) ? $setupOptions['commercial_review_statuses'] : $reviewStatuses;
    @endphp

    <div class="space-y-6">
        @if (session('status'))
            <section class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                {{ session('status') }}
            </section>
        @endif

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Landlord</p>
                    <h1 class="mt-1 text-2xl font-semibold text-zinc-950">Intake Queue</h1>
                    <p class="mt-2 max-w-4xl text-sm text-zinc-600">
                        Triage setup status for tenants that need import review, Shopify connection follow-up, mobile readiness review, or Everbranch operator action.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('landlord.onboarding.journey') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                        Diagnostics
                    </a>
                    <a href="{{ route('landlord.tenants.index') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                        Tenant Directory
                    </a>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap gap-2">
                @foreach ($filterOptions as $key => $label)
                    @php
                        $isActive = $activeFilter === $key;
                    @endphp
                    <a
                        href="{{ route('landlord.onboarding.intake', ['filter' => $key]) }}"
                        class="rounded-full border px-3 py-1.5 text-xs font-semibold {{ $isActive ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-100' }}"
                    >
                        {{ $label }}
                        <span class="{{ $isActive ? 'text-zinc-200' : 'text-zinc-500' }}">({{ (int) ($summary[$key] ?? 0) }})</span>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1360px] divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-600">
                        <tr>
                            <th class="px-4 py-3">Tenant</th>
                            <th class="px-4 py-3">Import path</th>
                            <th class="px-4 py-3">Connections</th>
                            <th class="px-4 py-3">Mobile</th>
                            <th class="px-4 py-3">Commercial intent</th>
                            <th class="px-4 py-3">Review</th>
                            <th class="px-4 py-3">Next action</th>
                            <th class="px-4 py-3">Operator update</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse ($rows as $row)
                            @php
                                $tenant = is_array($row['tenant'] ?? null) ? (array) $row['tenant'] : [];
                                $tenantId = (int) ($tenant['id'] ?? 0);
                                $updatedAt = (string) ($row['updated_at'] ?? '');
                                $reviewStatus = (string) ($row['landlord_review_status'] ?? 'pending_review');
                                $commercialReviewStatus = (string) ($row['commercial_review_status'] ?? 'pending_review');
                                $reviewClasses = match ($reviewStatus) {
                                    'reviewed' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
                                    'waiting_on_tenant' => 'border-amber-200 bg-amber-50 text-amber-800',
                                    'waiting_on_everbranch' => 'border-sky-200 bg-sky-50 text-sky-800',
                                    default => 'border-zinc-200 bg-zinc-50 text-zinc-700',
                                };
                            @endphp
                            <tr class="align-top text-zinc-700">
                                <td class="px-4 py-4">
                                    <a href="{{ route('landlord.tenants.show', ['tenant' => $tenantId]) }}" class="font-semibold text-zinc-950 underline decoration-dotted underline-offset-2">
                                        {{ $tenant['name'] ?? 'Tenant' }}
                                    </a>
                                    <div class="mt-1 font-mono text-xs text-zinc-500">{{ $tenant['slug'] ?? '' }}</div>
                                    @if (! empty($row['source_access_request_label']))
                                        <div class="mt-2 text-xs font-semibold text-zinc-700">{{ $row['source_access_request_label'] }}</div>
                                    @endif
                                    @if ($updatedAt !== '')
                                        <div class="mt-1 text-xs text-zinc-500">Updated {{ $updatedAt }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    <div class="font-semibold text-zinc-950">{{ $row['import_path_label'] ?? 'Undecided' }}</div>
                                    <div class="mt-1 text-xs text-zinc-600">CSV/manual: {{ $row['csv_manual_label'] ?? 'Not started' }}</div>
                                    @if (($row['import_path'] ?? '') === 'undecided')
                                        <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">Needs import decision</div>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    <div>Shopify: <span class="font-semibold text-zinc-950">{{ $row['shopify_connection_label'] ?? 'Not connected' }}</span></div>
                                    <div class="mt-1">Square: <span class="font-semibold text-zinc-950">{{ $row['square_label'] ?? 'Not requested' }}</span></div>
                                    @if (! empty($row['shopify_selected_not_connected']))
                                        <div class="mt-2 rounded-lg border border-sky-200 bg-sky-50 px-2 py-1 text-xs font-semibold text-sky-800">Shopify follow-up</div>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    <div class="font-semibold text-zinc-950">{{ $row['mobile_interest_label'] ?? 'Undecided' }}</div>
                                    @if (! empty($row['has_mobile_interest']))
                                        <div class="mt-2 rounded-lg border border-violet-200 bg-violet-50 px-2 py-1 text-xs font-semibold text-violet-800">Mobile review</div>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    <div class="font-semibold text-zinc-950">{{ $row['plan_interest_label'] ?? 'Undecided' }}</div>
                                    <div class="mt-1 text-xs text-zinc-600">{{ $row['billing_lane_interest_label'] ?? 'Undecided' }}</div>
                                    <div class="mt-1 text-xs text-zinc-600">{{ $row['implementation_help_label'] ?? 'No implementation help requested' }}</div>
                                    @if (! empty($row['commercial_notes']))
                                        <details class="mt-2">
                                            <summary class="cursor-pointer text-xs font-semibold text-zinc-600">Commercial notes</summary>
                                            <p class="mt-1 whitespace-pre-line text-xs text-zinc-600">{{ \Illuminate\Support\Str::limit((string) $row['commercial_notes'], 700) }}</p>
                                        </details>
                                    @endif
                                    <div class="mt-2 rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1 text-xs text-zinc-700">
                                        Commercial review: {{ $row['commercial_review_label'] ?? 'Pending review' }}
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold {{ $reviewClasses }}">
                                        {{ $row['landlord_review_label'] ?? 'Pending review' }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    <p class="max-w-xs text-sm text-zinc-700">{{ $row['next_recommended_action'] ?? 'Review setup status.' }}</p>
                                    <p class="mt-2 max-w-xs text-xs text-zinc-600">Commercial: {{ $row['commercial_next_action'] ?? 'Review plan intent without activating billing.' }}</p>
                                    @if (! empty($row['internal_notes']))
                                        <details class="mt-2">
                                            <summary class="cursor-pointer text-xs font-semibold text-zinc-600">Internal notes</summary>
                                            <p class="mt-1 whitespace-pre-line text-xs text-zinc-600">{{ \Illuminate\Support\Str::limit((string) $row['internal_notes'], 900) }}</p>
                                        </details>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    <form method="POST" action="{{ route('landlord.onboarding.setup-status.update', ['tenant' => $tenantId]) }}" class="space-y-2">
                                        @csrf
                                        <label class="block text-xs font-semibold text-zinc-600">
                                            Review status
                                            <select name="landlord_review_status" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-xs text-zinc-900">
                                                @foreach($reviewStatuses as $key => $label)
                                                    <option value="{{ $key }}" @selected($reviewStatus === $key)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block text-xs font-semibold text-zinc-600">
                                            Commercial review
                                            <select name="commercial_review_status" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-xs text-zinc-900">
                                                @foreach($commercialReviewStatuses as $key => $label)
                                                    <option value="{{ $key }}" @selected($commercialReviewStatus === $key)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block text-xs font-semibold text-zinc-600">
                                            Commercial next action
                                            <input type="text" name="commercial_next_action" value="{{ $row['commercial_next_action'] ?? '' }}" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-xs text-zinc-900">
                                        </label>
                                        <label class="block text-xs font-semibold text-zinc-600">
                                            Next action
                                            <input type="text" name="next_recommended_action" value="{{ $row['next_recommended_action'] ?? '' }}" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-xs text-zinc-900">
                                        </label>
                                        <label class="block text-xs font-semibold text-zinc-600">
                                            Internal notes
                                            <textarea name="internal_notes" rows="2" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-xs text-zinc-900">{{ $row['internal_notes'] ?? '' }}</textarea>
                                        </label>
                                        <button type="submit" class="rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800">
                                            Save review
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-sm text-zinc-500">No setup statuses match this intake filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
