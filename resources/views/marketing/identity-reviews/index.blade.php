<x-layouts::app :title="'Marketing Identity Review'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Identity Review Queue"
            description="Manual queue for ambiguous identity matches to prevent risky automatic merges."
            hint-title="Why this queue exists"
            hint-text="Exact normalized email/phone matches merge automatically. Conflicting matches are intentionally held here for reviewer decisions."
        />

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <form method="GET" action="{{ route('marketing.identity-review') }}" class="grid gap-3 md:grid-cols-12">
                <div class="md:col-span-7">
                    <label for="search" class="text-xs uppercase tracking-[0.2em] text-white/55">Search</label>
                    <input
                        id="search"
                        type="text"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Name, email, phone, source type, or source id"
                        class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-white/35"
                    />
                </div>
                <div class="md:col-span-2">
                    <label for="status" class="text-xs uppercase tracking-[0.2em] text-white/55">Status</label>
                    <select id="status" name="status" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="pending" @selected($status === 'pending')>Pending</option>
                        <option value="resolved" @selected($status === 'resolved')>Resolved</option>
                        <option value="ignored" @selected($status === 'ignored')>Ignored</option>
                        <option value="all" @selected($status === 'all')>All</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="per_page" class="text-xs uppercase tracking-[0.2em] text-white/55">Rows</label>
                    <select id="per_page" name="per_page" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        @foreach([25, 50, 100] as $rowCount)
                            <option value="{{ $rowCount }}" @selected($perPage === $rowCount)>{{ $rowCount }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-1 flex items-end">
                    <button type="submit" class="w-full rounded-xl border border-emerald-300/35 bg-emerald-500/15 px-3 py-2 text-sm font-semibold text-white">
                        Apply
                    </button>
                </div>
            </form>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-2 sm:p-3">
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Status</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Raw Identity</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Source</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Conflict Reasons</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Created</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Reviewed</th>
                            <th class="px-4 py-3 text-right whitespace-nowrap">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($reviews as $review)
                            @php
                                $reasonText = is_array($review->conflict_reasons) ? implode(', ', $review->conflict_reasons) : '—';
                            @endphp
                            <tr class="hover:bg-white/5">
                                <td class="px-4 py-3">
                                    <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] {{
                                        $review->status === 'pending'
                                            ? 'bg-amber-500/20 text-amber-100'
                                            : ($review->status === 'resolved'
                                                ? 'bg-emerald-500/20 text-emerald-100'
                                                : 'bg-white/10 text-white/65')
                                    }}">
                                        {{ ucfirst($review->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-white/80">
                                    {{ trim(($review->raw_first_name ?? '') . ' ' . ($review->raw_last_name ?? '')) ?: '—' }}
                                    <div class="text-xs text-white/55">{{ $review->raw_email ?: 'no-email' }} · {{ $review->raw_phone ?: 'no-phone' }}</div>
                                </td>
                                <td class="px-4 py-3 text-white/70">
                                    {{ $review->source_type }} / {{ $review->source_id }}
                                </td>
                                <td class="px-4 py-3 text-white/70">{{ $reasonText }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($review->created_at)->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($review->reviewed_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('marketing.identity-review.show', $review) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80 hover:bg-white/10">
                                        Open
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-white/55">No identity reviews for current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-2 pt-4">
                {{ $reviews->links() }}
            </div>
        </section>
    </div>
</x-layouts::app>
