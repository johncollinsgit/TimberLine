<x-layouts::app :title="'Marketing Reconciliation Operations'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Reconciliation Operations"
            description="Operational queue for unresolved storefront/widget/public-flow and redemption reconciliation issues."
            hint-title="How this queue works"
            hint-text="Backstage is the engine, Shopify is the online UI. This queue highlights unresolved link/reward/verification issues and supports safe manual resolution."
        />

        <section class="grid gap-4 md:grid-cols-3">
            <article class="rounded-2xl border border-white/10 bg-black/15 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Open Issues</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) $openIssueCount) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-black/15 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Issued Codes (Unreconciled)</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) $issuedCodeCount) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-black/15 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Redeemed Today</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) $reconciledToday) }}</div>
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <x-admin.help-hint title="Operational notes">
                Shopify widget requests are signed/verified. Public Laravel pages are minimal event utilities only. Unresolved states may require manual review before customer timelines are fully linked.
            </x-admin.help-hint>
            <form method="GET" action="{{ route('marketing.operations.reconciliation') }}" class="grid gap-3 md:grid-cols-12">
                <div class="md:col-span-3">
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Status</label>
                    <select name="status" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="open" @selected($status === 'open')>Open</option>
                        <option value="resolved" @selected($status === 'resolved')>Resolved</option>
                        <option value="ignored" @selected($status === 'ignored')>Ignored</option>
                    </select>
                </div>
                <div class="md:col-span-3">
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Issue Type</label>
                    <select name="issue_type" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="all">All</option>
                        @foreach($issueTypes as $row)
                            <option value="{{ $row }}" @selected($issueType === $row)>{{ $row }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Platform</label>
                    <select name="platform" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="all" @selected($platform === 'all')>All</option>
                        <option value="shopify" @selected($platform === 'shopify')>Shopify</option>
                        <option value="square" @selected($platform === 'square')>Square</option>
                    </select>
                </div>
                <div class="md:col-span-3">
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Search</label>
                    <input type="text" name="search" value="{{ $search }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" placeholder="event type, endpoint, source id">
                </div>
                <div class="md:col-span-1 flex items-end">
                    <button type="submit" class="w-full rounded-xl border border-emerald-300/35 bg-emerald-500/15 px-3 py-2 text-sm font-semibold text-white">Apply</button>
                </div>
            </form>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-semibold text-white">Unresolved Storefront/Public Issues</h2>
                <form method="POST" action="{{ route('marketing.operations.reconciliation.retry') }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="hidden" name="source" value="{{ $platform === 'all' ? 'all' : $platform }}">
                    <input type="hidden" name="limit" value="500">
                    <button type="submit" class="inline-flex rounded-full border border-amber-300/35 bg-amber-500/15 px-4 py-2 text-sm font-semibold text-amber-100">
                        Retry Reconciliation Scan
                    </button>
                </form>
            </div>

            <x-admin.help-hint tone="neutral" title="Why issues appear">
                Common causes: unresolved redemption code references during ingestion, storefront signature failures, ambiguous customer linking, and pending verification flows.
            </x-admin.help-hint>

            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Occurred</th>
                            <th class="px-4 py-3 text-left">Event</th>
                            <th class="px-4 py-3 text-left">Issue</th>
                            <th class="px-4 py-3 text-left">Profile</th>
                            <th class="px-4 py-3 text-left">Context</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($events as $event)
                            <tr>
                                <td class="px-4 py-3 text-white/65">{{ optional($event->occurred_at ?: $event->created_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/80">
                                    {{ $event->event_type }}
                                    <div class="text-xs text-white/45">{{ $event->endpoint ?: '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-white/70">
                                    {{ $event->issue_type ?: '—' }}
                                    <div class="text-xs text-white/50">{{ $event->source_type ?: '—' }}{{ $event->source_id ? (':' . $event->source_id) : '' }}</div>
                                </td>
                                <td class="px-4 py-3 text-white/70">
                                    @if($event->profile)
                                        <a href="{{ route('marketing.customers.show', $event->profile) }}" wire:navigate class="underline decoration-dotted">
                                            {{ trim(($event->profile->first_name ?? '') . ' ' . ($event->profile->last_name ?? '')) ?: ($event->profile->email ?: ($event->profile->phone ?: ('Profile #' . $event->profile->id))) }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-white/60">
                                    @if($event->redemption)
                                        <div>Code: <span class="font-mono">{{ $event->redemption->redemption_code }}</span></div>
                                        <div class="text-xs text-white/50">Platform: {{ strtoupper((string) ($event->redemption->platform ?: 'n/a')) }}</div>
                                    @else
                                        <div class="text-xs">{{ \Illuminate\Support\Str::limit(json_encode($event->meta), 120) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-white/70">
                                    {{ strtoupper((string) $event->status) }}
                                    <div class="text-xs text-white/50">{{ strtoupper((string) $event->resolution_status) }}</div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <form method="POST" action="{{ route('marketing.operations.reconciliation.issues.resolve', $event) }}">
                                            @csrf
                                            <input type="hidden" name="resolution_status" value="resolved">
                                            <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-3 py-1 text-xs font-semibold text-emerald-100">
                                                Mark Resolved
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('marketing.operations.reconciliation.issues.resolve', $event) }}">
                                            @csrf
                                            <input type="hidden" name="resolution_status" value="ignored">
                                            <button type="submit" class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/75">
                                                Ignore
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-white/55">No issues found for current filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="pt-2">{{ $events->links() }}</div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h2 class="text-lg font-semibold text-white">Issued Reward Codes Pending Reconciliation</h2>
            <x-admin.help-hint tone="neutral" title="Reward lifecycle">
                Issued codes are single-use. Shopify usage is validated during ingestion; Square/in-person usage may require staff-assisted reconciliation.
            </x-admin.help-hint>
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Issued</th>
                            <th class="px-4 py-3 text-left">Code</th>
                            <th class="px-4 py-3 text-left">Reward</th>
                            <th class="px-4 py-3 text-left">Profile</th>
                            <th class="px-4 py-3 text-left">Platform</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($issuedRedemptions as $redemption)
                            <tr>
                                <td class="px-4 py-3 text-white/65">{{ optional($redemption->issued_at ?: $redemption->created_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/80 font-mono">{{ $redemption->redemption_code }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $redemption->reward?->name ?: ('Reward #' . $redemption->reward_id) }}</td>
                                <td class="px-4 py-3 text-white/70">
                                    @if($redemption->profile)
                                        <a href="{{ route('marketing.customers.show', $redemption->profile) }}" wire:navigate class="underline decoration-dotted">
                                            {{ trim(($redemption->profile->first_name ?? '') . ' ' . ($redemption->profile->last_name ?? '')) ?: ($redemption->profile->email ?: ($redemption->profile->phone ?: ('Profile #' . $redemption->marketing_profile_id))) }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-white/70">{{ strtoupper((string) ($redemption->platform ?: 'n/a')) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('marketing.operations.reconciliation.redemptions.mark-redeemed', $redemption) }}" class="inline-flex items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="platform" value="{{ $redemption->platform ?: 'square' }}">
                                        <input type="hidden" name="external_order_source" value="{{ $redemption->platform === 'shopify' ? 'order' : 'square_manual' }}">
                                        <input type="text" name="external_order_id" placeholder="Order ID" class="w-28 rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-xs text-white">
                                        <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-3 py-1 text-xs font-semibold text-emerald-100">
                                            Mark Redeemed
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-white/55">No issued codes pending reconciliation.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts::app>

