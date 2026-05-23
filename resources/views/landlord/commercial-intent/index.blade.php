<x-app-layout>
    @php
        $summary = is_array($summary ?? null) ? $summary : [];
        $planCounts = is_array($planCounts ?? null) ? $planCounts : [];
        $billingLaneCounts = is_array($billingLaneCounts ?? null) ? $billingLaneCounts : [];
        $rows = is_array($rows ?? null) ? $rows : [];
        $setupOptions = is_array($setupOptions ?? null) ? $setupOptions : [];
        $commercialReviewStatuses = is_array($setupOptions['commercial_review_statuses'] ?? null) ? $setupOptions['commercial_review_statuses'] : [];
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
                    <h1 class="mt-1 text-2xl font-semibold text-zinc-950">Commercial Intent Gate</h1>
                    <p class="mt-2 max-w-4xl text-sm text-zinc-600">
                        Operator-only summary for plan interest, billing lane intent, implementation help, module request context, and readiness blockers.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('landlord.onboarding.intake') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                        Intake Queue
                    </a>
                    <a href="{{ route('landlord.commercial.index') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                        Commercial Config
                    </a>
                    <a href="{{ route('landlord.custom-module-requests.index') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                        Custom Requests
                    </a>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
            <div class="grid gap-2 md:grid-cols-2">
                <p><span class="font-semibold">This gate does not charge the tenant.</span></p>
                <p><span class="font-semibold">Billing activation requires a future explicit PR and evidence.</span></p>
                <p>Shopify App Store merchants require Shopify Billing/App Pricing lane.</p>
                <p>Stripe is reserved for direct/custom/non-Shopify/manual-contract lanes unless future policy changes.</p>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['label' => 'Commercial intent', 'value' => $summary['tenants_with_commercial_intent'] ?? 0],
                ['label' => 'Needs commercial review', 'value' => $summary['needs_commercial_review'] ?? 0],
                ['label' => 'Implementation help', 'value' => $summary['wants_implementation_help'] ?? 0],
                ['label' => 'Custom requests', 'value' => $summary['with_custom_module_requests'] ?? 0],
                ['label' => 'Missing plan or lane', 'value' => $summary['missing_plan_or_lane'] ?? 0],
                ['label' => 'Shopify evidence blocked', 'value' => $summary['blocked_by_shopify_evidence'] ?? 0],
                ['label' => 'Billing disabled blocked', 'value' => $summary['blocked_by_billing_disabled'] ?? 0],
                ['label' => 'Total tenants', 'value' => $summary['total_tenants'] ?? 0],
            ] as $card)
                <article class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $card['label'] }}</p>
                    <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) $card['value']) }}</p>
                </article>
            @endforeach
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-zinc-950">Tenants by plan interest</h2>
                <dl class="mt-4 space-y-2">
                    @forelse ($planCounts as $label => $count)
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-zinc-600">{{ $label }}</dt>
                            <dd class="font-semibold text-zinc-950">{{ number_format((int) $count) }}</dd>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">No tenant plan intent has been captured yet.</p>
                    @endforelse
                </dl>
            </article>

            <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-zinc-950">Tenants by billing lane interest</h2>
                <dl class="mt-4 space-y-2">
                    @forelse ($billingLaneCounts as $label => $count)
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-zinc-600">{{ $label }}</dt>
                            <dd class="font-semibold text-zinc-950">{{ number_format((int) $count) }}</dd>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">No tenant billing lane intent has been captured yet.</p>
                    @endforelse
                </dl>
            </article>
        </section>

        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1480px] divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-600">
                        <tr>
                            <th class="px-4 py-3">Tenant</th>
                            <th class="px-4 py-3">Plan</th>
                            <th class="px-4 py-3">Billing lane</th>
                            <th class="px-4 py-3">Implementation</th>
                            <th class="px-4 py-3">Modules / requests</th>
                            <th class="px-4 py-3">Decision gate</th>
                            <th class="px-4 py-3">Next action</th>
                            <th class="px-4 py-3">Review update</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse ($rows as $row)
                            @php
                                $tenant = is_array($row['tenant'] ?? null) ? (array) $row['tenant'] : [];
                                $tenantId = (int) ($tenant['id'] ?? 0);
                                $commercialReviewStatus = (string) ($row['commercial_review_status'] ?? 'pending_review');
                                $blockers = is_array($row['billing_lane_blockers'] ?? null) ? $row['billing_lane_blockers'] : [];
                            @endphp
                            <tr class="align-top text-zinc-700">
                                <td class="px-4 py-4">
                                    <a href="{{ route('landlord.tenants.show', ['tenant' => $tenantId]) }}" class="font-semibold text-zinc-950 underline decoration-dotted underline-offset-2">
                                        {{ $tenant['name'] ?? 'Tenant' }}
                                    </a>
                                    <div class="mt-1 font-mono text-xs text-zinc-500">{{ $tenant['slug'] ?? '' }}</div>
                                    @if (! empty($row['updated_at']))
                                        <div class="mt-2 text-xs text-zinc-500">Updated {{ $row['updated_at'] }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    <div class="font-semibold text-zinc-950">{{ $row['plan_interest_label'] ?? 'Undecided' }}</div>
                                    <p class="mt-1 max-w-xs text-xs text-zinc-600">{{ $row['plan_selection_guidance'] ?? 'Plan intent is a planning signal only.' }}</p>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="font-semibold text-zinc-950">{{ $row['billing_lane_interest_label'] ?? 'Undecided' }}</div>
                                    <p class="mt-1 max-w-xs text-xs text-zinc-600">{{ $row['billing_lane_guidance'] ?? 'Billing lane is undecided.' }}</p>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="font-semibold text-zinc-950">{{ $row['implementation_help_label'] ?? 'No implementation help requested' }}</div>
                                    <div class="mt-2 rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1 text-xs text-zinc-700">
                                        Commercial review: {{ $row['commercial_review_label'] ?? 'Pending review' }}
                                    </div>
                                    @if (! empty($row['commercial_notes']))
                                        <details class="mt-2">
                                            <summary class="cursor-pointer text-xs font-semibold text-zinc-600">Commercial notes</summary>
                                            <p class="mt-1 whitespace-pre-line text-xs text-zinc-600">{{ \Illuminate\Support\Str::limit((string) $row['commercial_notes'], 900) }}</p>
                                        </details>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    <div class="font-semibold text-zinc-950">{{ $row['module_interest_summary'] ?? 'No module interests selected.' }}</div>
                                    <div class="mt-2 text-xs text-zinc-600">Custom module requests: <span class="font-semibold text-zinc-950">{{ number_format((int) ($row['custom_module_request_count'] ?? 0)) }}</span></div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-800">
                                        {{ $row['billing_lane_decision_label'] ?? 'Not ready' }}
                                    </div>
                                    <ul class="mt-3 max-w-sm list-disc space-y-1 pl-4 text-xs text-zinc-600">
                                        @forelse ($blockers as $blocker)
                                            <li>{{ $blocker }}</li>
                                        @empty
                                            <li>No blockers are cleared for billing activation in this PR; use manual follow-up only.</li>
                                        @endforelse
                                    </ul>
                                </td>
                                <td class="px-4 py-4">
                                    <p class="max-w-xs text-sm text-zinc-700">{{ $row['commercial_next_action'] ?? 'Review commercial intent before any activation work.' }}</p>
                                </td>
                                <td class="px-4 py-4">
                                    <form method="POST" action="{{ route('landlord.commercial-intent.update', ['tenant' => $tenantId]) }}" class="space-y-2">
                                        @csrf
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
                                            Commercial notes
                                            <textarea name="commercial_notes" rows="2" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-xs text-zinc-900">{{ $row['commercial_notes'] ?? '' }}</textarea>
                                        </label>
                                        <button type="submit" class="rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800">
                                            Save commercial review
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-sm text-zinc-500">No tenants are available for commercial intent review.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
