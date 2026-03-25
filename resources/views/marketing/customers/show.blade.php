<x-layouts::app :title="'Customer'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Customer"
            description="Detailed marketing identity view with linked source records, campaign touches, consent history, and conversion context."
            hint-title="How to use this detail page"
            hint-text="This profile is a marketing-layer identity record. Source links and communication history are additive overlays on operational data, not replacements."
        />

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    @php
                        $name = trim((string) ($profile->first_name . ' ' . $profile->last_name));
                    @endphp
                    <h2 class="text-xl font-semibold text-white">{{ $name !== '' ? $name : 'Unnamed profile' }}</h2>
                    <div class="mt-1 text-xs text-white/50">Marketing Profile #{{ $profile->id }}</div>
                </div>
                <a href="{{ route('marketing.customers') }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/75 hover:bg-white/10">
                    Back to Customers
                </a>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Email</div>
                    <div class="mt-2 text-sm text-white">{{ $profile->email ?: '—' }}</div>
                    <div class="mt-1 text-xs text-white/45">Normalized: {{ $profile->normalized_email ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Phone</div>
                    <div class="mt-2 text-sm text-white">{{ $profile->phone ?: '—' }}</div>
                    <div class="mt-1 text-xs text-white/45">Normalized: {{ $profile->normalized_phone ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Consent Status</div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] {{ $profile->accepts_email_marketing ? 'bg-emerald-500/20 text-emerald-100' : 'bg-white/10 text-white/60' }}">
                            Email {{ $profile->accepts_email_marketing ? 'Opt-In' : 'Opt-Out' }}
                        </span>
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] {{ $profile->accepts_sms_marketing ? 'bg-emerald-500/20 text-emerald-100' : 'bg-white/10 text-white/60' }}">
                            SMS {{ $profile->accepts_sms_marketing ? 'Opt-In' : 'Opt-Out' }}
                        </span>
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Profile Meta</div>
                    <div class="mt-2 text-xs text-white/70">Created: {{ optional($profile->created_at)->format('Y-m-d H:i') }}</div>
                    <div class="mt-1 text-xs text-white/70">Updated: {{ optional($profile->updated_at)->format('Y-m-d H:i') }}</div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach((array) $profile->source_channels as $channel)
                            <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-[11px] text-white/70">{{ $channel }}</span>
                        @endforeach
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Marketing Likelihood</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ $profile->marketing_score !== null ? number_format((float) $profile->marketing_score, 0) . '%' : 'Pending' }}</div>
                    <div class="mt-1 text-xs text-white/55">Updated: {{ optional($profile->last_marketing_score_at)->format('Y-m-d H:i') ?: '—' }}</div>
                </article>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-white">Identity + Address Update</h2>
            <p class="mt-1 text-sm text-white/65">Primary profile fields for location-aware segmentation and future localized campaigns.</p>
            <form method="POST" action="{{ route('marketing.customers.update', $profile) }}" class="mt-4 space-y-3">
                @csrf
                @method('PATCH')
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <input type="text" name="first_name" value="{{ old('first_name', $profile->first_name) }}" placeholder="First name" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                    <input type="text" name="last_name" value="{{ old('last_name', $profile->last_name) }}" placeholder="Last name" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                    <input type="email" name="email" value="{{ old('email', $profile->email) }}" placeholder="Email" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                    <input type="text" name="phone" value="{{ old('phone', $profile->phone) }}" placeholder="Phone" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                    <input type="text" name="address_line_1" value="{{ old('address_line_1', $profile->address_line_1) }}" placeholder="Address line 1" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white xl:col-span-2">
                    <input type="text" name="address_line_2" value="{{ old('address_line_2', $profile->address_line_2) }}" placeholder="Address line 2" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white xl:col-span-2">
                    <input type="text" name="city" value="{{ old('city', $profile->city) }}" placeholder="City" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                    <input type="text" name="state" value="{{ old('state', $profile->state) }}" placeholder="State" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                    <input type="text" name="postal_code" value="{{ old('postal_code', $profile->postal_code) }}" placeholder="Postal code" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                    <input type="text" name="country" value="{{ old('country', $profile->country) }}" placeholder="Country" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                </div>
                <textarea name="notes" rows="2" placeholder="Notes" class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">{{ old('notes', $profile->notes) }}</textarea>
                <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                    Save Profile
                </button>
            </form>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h2 class="text-lg font-semibold text-white">External Enrichment (Read-Only)</h2>
            <p class="text-sm text-white/65">These values come from linked external systems and are displayed for context. Edit core identity fields in the section above.</p>

            <div class="flex flex-wrap gap-2 text-xs">
                <span class="inline-flex rounded-full border border-blue-300/35 bg-blue-500/15 px-2.5 py-1 text-blue-100">Shopify Source</span>
                <span class="inline-flex rounded-full border border-cyan-300/35 bg-cyan-500/15 px-2.5 py-1 text-cyan-100">Backstage Native Reviews</span>
                <span class="inline-flex rounded-full border border-amber-300/35 bg-amber-500/15 px-2.5 py-1 text-amber-100">Backstage Native Wishlist</span>
                @if($latestGrowaveExternal || $latestGrowaveReviewSummary)
                    <span class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-2.5 py-1 text-emerald-100">Legacy Growave Source</span>
                @endif
            </div>

            <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-8">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Active Review Source</div>
                    <div class="mt-2 text-sm text-white">
                        @if(($preferredReviewDataSource ?? 'none') === 'native')
                            Native Backstage
                        @elseif(($preferredReviewDataSource ?? 'none') === 'legacy_growave')
                            Legacy Growave
                        @else
                            No review data
                        @endif
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Active Reviews</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($preferredReviewSummary['review_count'] ?? 0)) }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Active Avg Rating</div>
                    <div class="mt-2 text-2xl font-semibold text-white">
                        @if(($preferredReviewSummary['average_rating'] ?? null) !== null)
                            {{ number_format((float) $preferredReviewSummary['average_rating'], 2) }}
                        @else
                            —
                        @endif
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Active Review Rewards</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($preferredReviewRewardStatus['count'] ?? 0)) }}</div>
                    <div class="mt-1 text-[11px] text-white/55">
                        Last reward: {{ $preferredReviewRewardStatus['last_rewarded_at'] ?? '—' }}
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Legacy Growave Balance</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($latestGrowaveExternal?->points_balance ?? 0)) }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Legacy Growave Tier</div>
                    <div class="mt-2 text-sm text-white">{{ $latestGrowaveExternal?->vip_tier ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Legacy Referral Link</div>
                    @if($latestGrowaveExternal?->referral_link)
                        <a href="{{ $latestGrowaveExternal->referral_link }}" target="_blank" rel="noreferrer" class="mt-2 inline-flex text-xs text-emerald-100 underline decoration-dotted">
                            Open Legacy Link
                        </a>
                    @else
                        <div class="mt-2 text-sm text-white/60">—</div>
                    @endif
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Legacy Growave Reviews</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($latestGrowaveReviewSummary?->review_count ?? 0)) }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Active Wishlist Source</div>
                    <div class="mt-2 text-sm text-white">
                        @if(($preferredWishlistDataSource ?? 'none') === 'native')
                            Native Backstage
                        @elseif(($preferredWishlistDataSource ?? 'none') === 'legacy')
                            Legacy Import
                        @else
                            No wishlist data
                        @endif
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Active Wishlist Items</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($preferredWishlistSummary['active_count'] ?? 0)) }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Wishlist Adds (30d)</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($preferredWishlistSummary['recent_additions_30d'] ?? 0)) }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Wishlist Last Added</div>
                    <div class="mt-2 text-sm text-white">{{ $preferredWishlistSummary['last_added_at'] ?? '—' }}</div>
                    <div class="mt-1 text-[11px] text-white/55">
                        Removed items: {{ number_format((int) ($preferredWishlistSummary['removed_count'] ?? 0)) }}
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Last Growave Sync</div>
                    <div class="mt-2 text-sm text-white">{{ $growaveSourceMeta['last_synced_at'] ?? '—' }}</div>
                    <div class="mt-1 text-[11px] text-white/55">Profile row update: {{ optional($latestGrowaveExternal?->updated_at)->format('Y-m-d H:i') ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4 xl:col-span-2">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Source Metadata</div>
                    <div class="mt-2 text-xs text-white/75">Provider: {{ $growaveSourceMeta['provider'] ?: '—' }}</div>
                    <div class="mt-1 text-xs text-white/75">Integration: {{ $growaveSourceMeta['integration'] ?: '—' }}</div>
                    <div class="mt-1 text-xs text-white/75">Store: {{ $growaveSourceMeta['store_key'] ?: '—' }}</div>
                    <div class="mt-1 text-xs text-white/75">External ID: {{ $growaveSourceMeta['external_customer_id'] ?: '—' }}</div>
                </article>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <div class="border-b border-white/10 bg-white/5 px-4 py-3 text-xs uppercase tracking-[0.2em] text-white/55">
                    External Profile Records (Read-Only)
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Provider</th>
                            <th class="px-4 py-3 text-left">Integration</th>
                            <th class="px-4 py-3 text-left">Store</th>
                            <th class="px-4 py-3 text-left">External ID</th>
                            <th class="px-4 py-3 text-left">Legacy Balance</th>
                            <th class="px-4 py-3 text-left">Tier</th>
                            <th class="px-4 py-3 text-left">Synced</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($externalProfiles as $externalProfile)
                            <tr>
                                <td class="px-4 py-3 text-white/80">{{ $externalProfile->provider ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $externalProfile->integration ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $externalProfile->store_key ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $externalProfile->external_customer_id ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $externalProfile->points_balance !== null ? number_format((int) $externalProfile->points_balance) : '—' }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $externalProfile->vip_tier ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($externalProfile->synced_at)->format('Y-m-d H:i') ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-6 text-center text-white/55">No external enrichment rows linked yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <div class="border-b border-white/10 bg-white/5 px-4 py-3 text-xs uppercase tracking-[0.2em] text-white/55">
                    Native Backstage Reviews
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Review ID</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Rating</th>
                            <th class="px-4 py-3 text-left">Product</th>
                            <th class="px-4 py-3 text-left">Submitted</th>
                            <th class="px-4 py-3 text-left">Reward Event</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($nativeReviewHistory as $review)
                            <tr>
                                <td class="px-4 py-3 text-white/80">{{ $review->external_review_id }}</td>
                                <td class="px-4 py-3 text-white/75">{{ strtoupper((string) ($review->status ?: 'approved')) }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $review->rating !== null ? number_format((int) $review->rating) : '—' }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $review->product_title ?: ($review->product_id ?: '—') }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($review->submitted_at ?: $review->created_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/60">
                                    @if($review->candle_cash_task_completion_id)
                                        Completion #{{ $review->candle_cash_task_completion_id }}
                                    @elseif($review->candle_cash_task_event_id)
                                        Event #{{ $review->candle_cash_task_event_id }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-white/55">No native Backstage review history yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <div class="border-b border-white/10 bg-white/5 px-4 py-3 text-xs uppercase tracking-[0.2em] text-white/55">
                    Legacy Growave Reviews (Read-Only)
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Review ID</th>
                            <th class="px-4 py-3 text-left">Rating</th>
                            <th class="px-4 py-3 text-left">Product</th>
                            <th class="px-4 py-3 text-left">Published</th>
                            <th class="px-4 py-3 text-left">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($growaveReviewHistory as $review)
                            <tr>
                                <td class="px-4 py-3 text-white/80">{{ $review->external_review_id }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $review->rating !== null ? number_format((int) $review->rating) : '—' }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $review->product_title ?: ($review->product_id ?: '—') }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $review->is_published === null ? '—' : ($review->is_published ? 'yes' : 'no') }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($review->reviewed_at)->format('Y-m-d H:i') ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-white/55">No legacy Growave review history synced yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <div class="border-b border-white/10 bg-white/5 px-4 py-3 text-xs uppercase tracking-[0.2em] text-white/55">
                    Native Backstage Wishlist
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Product</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Store</th>
                            <th class="px-4 py-3 text-left">Last Added</th>
                            <th class="px-4 py-3 text-left">Removed</th>
                            <th class="px-4 py-3 text-left">Provenance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($nativeWishlistItems as $item)
                            <tr>
                                <td class="px-4 py-3 text-white/80">{{ $item->product_title ?: ($item->product_handle ?: $item->product_id) }}</td>
                                <td class="px-4 py-3 text-white/75">{{ strtoupper((string) $item->status) }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $item->store_key ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($item->last_added_at ?: $item->added_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($item->removed_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ $item->provider }}/{{ $item->integration }}{{ $item->source ? ' · ' . $item->source : '' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-white/55">No native Backstage wishlist items recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <div class="border-b border-white/10 bg-white/5 px-4 py-3 text-xs uppercase tracking-[0.2em] text-white/55">
                    Legacy Wishlist Rows (Read-Only)
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Product</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Store</th>
                            <th class="px-4 py-3 text-left">Synced</th>
                            <th class="px-4 py-3 text-left">Provenance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($legacyWishlistItems as $item)
                            <tr>
                                <td class="px-4 py-3 text-white/80">{{ $item->product_title ?: ($item->product_handle ?: $item->product_id) }}</td>
                                <td class="px-4 py-3 text-white/75">{{ strtoupper((string) $item->status) }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $item->store_key ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($item->source_synced_at ?: $item->updated_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ $item->provider }}/{{ $item->integration }}{{ $item->source ? ' · ' . $item->source : '' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-white/55">No legacy wishlist rows synced yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <div class="border-b border-white/10 bg-white/5 px-4 py-3 text-xs uppercase tracking-[0.2em] text-white/55">
                    Legacy Growave Loyalty Activity (Read-Only)
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Occurred</th>
                            <th class="px-4 py-3 text-left">Category</th>
                            <th class="px-4 py-3 text-left">Provider Event</th>
                            <th class="px-4 py-3 text-left">Legacy Balance Change</th>
                            <th class="px-4 py-3 text-left">Source ID</th>
                            <th class="px-4 py-3 text-left">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($growaveLoyaltyTransactions as $activity)
                            <tr>
                                <td class="px-4 py-3 text-white/65">{{ $activity['occurred_at'] ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/85">{{ $activity['category'] }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $activity['provider_activity'] }}</td>
                                <td class="px-4 py-3">
                                    <span class="{{ (int) $activity['candle_cash_delta'] >= 0 ? 'text-emerald-100' : 'text-rose-200' }}">
                                        {{ (int) $activity['candle_cash_delta'] > 0 ? '+' : '' }}{{ number_format((int) $activity['candle_cash_delta']) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-white/65 font-mono text-xs">{{ $activity['source_id'] ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ $activity['note'] ?: ($activity['description'] ?: '—') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-white/55">No legacy Growave loyalty activity transactions synced yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h2 class="text-lg font-semibold text-white">Birthday Management</h2>
            <p class="text-sm text-white/65">Backstage is the canonical source for customer birthdays and annual reward issuance guardrails.</p>

            <div class="grid gap-3 md:grid-cols-4">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Birthday</div>
                    <div class="mt-2 text-sm text-white">
                        @if($birthdayProfile && $birthdayProfile->birth_month && $birthdayProfile->birth_day)
                            {{ sprintf('%02d/%02d', (int) $birthdayProfile->birth_month, (int) $birthdayProfile->birth_day) }}
                            @if($birthdayProfile->birth_year)
                                /{{ (int) $birthdayProfile->birth_year }}
                            @endif
                        @else
                            Missing
                        @endif
                    </div>
                    <div class="mt-1 text-xs text-white/55">Full date: {{ optional($birthdayProfile?->birthday_full_date)->toDateString() ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Source</div>
                    <div class="mt-2 text-sm text-white">{{ $birthdayProfile?->source ?: '—' }}</div>
                    <div class="mt-1 text-xs text-white/55">Captured: {{ optional($birthdayProfile?->source_captured_at)->format('Y-m-d H:i') ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Reward Status</div>
                    <div class="mt-2 text-sm text-white">{{ str_replace('_', ' ', (string) ($birthdayRewardStatus['state'] ?? 'birthday_saved')) }}</div>
                    <div class="mt-1 text-xs text-white/55">Last issued: {{ optional($birthdayProfile?->reward_last_issued_at)->format('Y-m-d H:i') ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Last Issued Year</div>
                    <div class="mt-2 text-sm text-white">{{ $birthdayProfile?->reward_last_issued_year ?: '—' }}</div>
                    <div class="mt-1 text-xs text-white/55">Updated: {{ optional($birthdayProfile?->updated_at)->format('Y-m-d H:i') ?: '—' }}</div>
                </article>
            </div>

            <form method="POST" action="{{ route('marketing.customers.update-birthday', $profile) }}" class="grid gap-3 md:grid-cols-5">
                @csrf
                <div>
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Birth Month</label>
                    <input type="number" min="1" max="12" name="birth_month" value="{{ old('birth_month', $birthdayProfile?->birth_month) }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                </div>
                <div>
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Birth Day</label>
                    <input type="number" min="1" max="31" name="birth_day" value="{{ old('birth_day', $birthdayProfile?->birth_day) }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                </div>
                <div>
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Birth Year (optional)</label>
                    <input type="number" min="1900" max="{{ now()->year + 1 }}" name="birth_year" value="{{ old('birth_year', $birthdayProfile?->birth_year) }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                </div>
                <div>
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Source</label>
                    <input type="text" name="source" value="{{ old('source', $birthdayProfile?->source ?: 'admin_backstage') }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                        Save Birthday
                    </button>
                </div>
                <div class="md:col-span-5 flex flex-wrap gap-3 text-sm text-white/75">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="issue_reward_now" value="1" class="rounded border-white/20 bg-white/5" />
                        Issue reward now if eligible
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="sync_shopify" value="1" checked class="rounded border-white/20 bg-white/5" />
                        Sync to Shopify metafields
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="clear" value="1" class="rounded border-white/20 bg-white/5" />
                        Clear birthday fields
                    </label>
                </div>
            </form>

            <div class="grid gap-4 lg:grid-cols-2">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <h3 class="text-sm font-semibold text-white">Reward Issuance History</h3>
                    <div class="mt-3 space-y-2">
                        @forelse(($birthdayProfile?->rewardIssuances ?? collect()) as $issuance)
                            <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-white/75">
                                <div>{{ $issuance->cycle_year }} · {{ $issuance->reward_type }} · {{ $issuance->status }}</div>
                                <div class="mt-1">Issued: {{ optional($issuance->issued_at)->format('Y-m-d H:i') ?: '—' }} · Claimed: {{ optional($issuance->claimed_at)->format('Y-m-d H:i') ?: '—' }}</div>
                                @if($issuance->reward_code)
                                    <div class="mt-1">Code: <span class="font-semibold text-white">{{ $issuance->reward_code }}</span></div>
                                @endif
                            </div>
                        @empty
                            <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-white/60">No birthday reward issuance history yet.</div>
                        @endforelse
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <h3 class="text-sm font-semibold text-white">Birthday Audit History</h3>
                    <div class="mt-3 space-y-2">
                        @forelse(($birthdayProfile?->audits ?? collect()) as $audit)
                            <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-white/75">
                                <div>{{ $audit->action }} · {{ optional($audit->created_at)->format('Y-m-d H:i') }}</div>
                                <div class="mt-1">Source: {{ $audit->source ?: '—' }}{{ $audit->is_uncertain ? ' · uncertain' : '' }}</div>
                            </div>
                        @empty
                            <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-white/60">No birthday audit records yet.</div>
                        @endforelse
                    </div>
                </article>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-white">Linked Source Records</h2>
            <div class="mt-4 overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Source Type</th>
                            <th class="px-4 py-3 text-left">Source ID</th>
                            <th class="px-4 py-3 text-left">Match Method</th>
                            <th class="px-4 py-3 text-left">Confidence</th>
                            <th class="px-4 py-3 text-left">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($profile->links as $link)
                            <tr>
                                <td class="px-4 py-3 text-white/80">{{ $link->source_type }}</td>
                                <td class="px-4 py-3 text-white/80">{{ $link->source_id }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $link->match_method ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $link->confidence !== null ? number_format((float) $link->confidence, 2) : '—' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($link->created_at)->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-white/50">No linked source records yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-white">Orders</h2>
            <p class="mt-1 text-sm text-white/65">Operational orders linked through marketing profile source links.</p>
            <div class="mt-4 overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Order</th>
                            <th class="px-4 py-3 text-left">Source/Channel</th>
                            <th class="px-4 py-3 text-left">Order Date</th>
                            <th class="px-4 py-3 text-left">Customer Snapshot</th>
                            <th class="px-4 py-3 text-right">Operational Detail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($orders as $order)
                            <tr>
                                <td class="px-4 py-3 text-white/80">
                                    {{ $order->order_number ?: ('Order #' . $order->id) }}
                                    <div class="text-xs text-white/45">ID #{{ $order->id }}</div>
                                </td>
                                <td class="px-4 py-3 text-white/70">
                                    {{ $order->source ?: '—' }} / {{ $order->order_type ?: $order->channel }}
                                </td>
                                <td class="px-4 py-3 text-white/70">{{ optional($order->ordered_at)->toDateString() ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/65">
                                    {{ $order->customer_name ?: ($order->shipping_name ?: $order->billing_name ?: '—') }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if((auth()->user()?->isAdmin() ?? false) || (auth()->user()?->isManager() ?? false))
                                        <a href="{{ route('pouring.order', $order) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/75 hover:bg-white/10">
                                            Open
                                        </a>
                                    @else
                                        <span class="text-xs text-white/40">Restricted</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-white/50">No linked operational orders available yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-white">Events Purchased At</h2>
            <x-admin.help-hint tone="neutral" title="Event attribution notes">
                Event attribution uses explicit source mappings from Square tax/source values. Unresolved values stay visible until mapped by admin.
            </x-admin.help-hint>

            @if($eventSummary !== [])
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach($eventSummary as $row)
                        <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <div class="text-sm font-semibold text-white">{{ $row['event_title'] }}</div>
                            <div class="mt-1 text-xs text-white/65">Date: {{ $row['event_date'] ?: '—' }}</div>
                            <div class="mt-1 text-xs text-white/65">Linked source orders: {{ $row['source_count'] }}</div>
                            <div class="mt-1 text-xs text-white/65">Confidence: {{ $row['confidence'] !== null ? number_format((float) $row['confidence'], 2) : '—' }}</div>
                            <div class="mt-1 text-xs text-white/65">Method: {{ implode(', ', $row['attribution_methods']) }}</div>
                        </article>
                    @endforeach
                </div>
            @elseif($eventOrders->isNotEmpty())
                <div class="mt-3 space-y-2">
                    @foreach($eventOrders as $order)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/75">
                            {{ $order->order_number ?: ('Order #' . $order->id) }}
                            @if($order->event)
                                · Event: {{ $order->event->display_name ?: $order->event->name }}
                            @elseif($order->order_type === 'event')
                                · Event attribution pending explicit mapping
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-2 text-sm text-white/65">No event-attributed records available yet for this profile.</p>
            @endif

            @if($unresolvedAttributionValues !== [])
                <div class="mt-4">
                    <h3 class="text-sm font-semibold text-white">Unresolved Event Source Values</h3>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach($unresolvedAttributionValues as $value)
                            <span class="inline-flex rounded-full border border-amber-300/25 bg-amber-500/15 px-2.5 py-1 text-xs text-amber-100">
                                {{ $value['source_system'] }}: {{ $value['raw_value'] }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-white">Square Linked Records</h3>
                <p class="mt-1 text-xs text-white/65">Square orders and payments linked through marketing profile links.</p>
                <div class="mt-3 space-y-2 text-sm text-white/75">
                    @if($squareOrders->isEmpty() && $squarePayments->isEmpty())
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-white/60">No Square-linked records yet.</div>
                    @endif
                    @foreach($squareOrders as $row)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                            Order {{ $row->square_order_id }} · {{ optional($row->closed_at)->toDateString() ?: 'open' }} · {{ $row->source_name ?: '—' }}
                        </div>
                    @endforeach
                    @foreach($squarePayments as $row)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                            Payment {{ $row->square_payment_id }} · {{ $row->status ?: '—' }} · {{ $row->amount_money !== null ? '$' . number_format(((int) $row->amount_money) / 100, 2) : '—' }}
                        </div>
                    @endforeach
                </div>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-white">Imported Legacy Contact Links</h3>
                <div class="mt-3 space-y-2 text-sm text-white/75">
                    @forelse($legacyLinks as $link)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                            {{ $link->source_type }} · {{ $link->source_id }}
                        </div>
                    @empty
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-white/60">No legacy contact imports linked yet.</div>
                    @endforelse
                </div>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-white">Consent Summary</h3>
                <x-admin.help-hint tone="neutral" title="Consent precedence">
                    Consent is checked again at send time. Explicit opt-outs block outbound sends even if a recipient was previously approved.
                </x-admin.help-hint>
                <div class="mt-3 text-sm text-white/75 space-y-1">
                    <div>Email: {{ $profile->accepts_email_marketing ? 'Opt-In' : 'Opt-Out' }}</div>
                    <div>SMS: {{ $profile->accepts_sms_marketing ? 'Opt-In' : 'Opt-Out' }}</div>
                    <div>Email opted out at: {{ optional($profile->email_opted_out_at)->format('Y-m-d H:i') ?: '—' }}</div>
                    <div>SMS opted out at: {{ optional($profile->sms_opted_out_at)->format('Y-m-d H:i') ?: '—' }}</div>
                </div>

                <div class="mt-4 space-y-2">
                    <form method="POST" action="{{ route('marketing.customers.update-consent', $profile) }}" class="grid gap-2 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="channel" value="sms" />
                        <input type="hidden" name="consented" value="1" />
                        <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-3 py-1.5 text-xs font-semibold text-emerald-100">Mark SMS Consented</button>
                    </form>
                    <form method="POST" action="{{ route('marketing.customers.update-consent', $profile) }}" class="grid gap-2 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="channel" value="sms" />
                        <input type="hidden" name="consented" value="0" />
                        <button type="submit" class="inline-flex rounded-full border border-amber-300/35 bg-amber-500/15 px-3 py-1.5 text-xs font-semibold text-amber-100">Revoke SMS Consent</button>
                    </form>
                </div>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-white">Legacy Campaign Activity Summary</h3>
                @if($campaignStats->isNotEmpty())
                    <div class="mt-3 space-y-2 text-sm text-white/75">
                        @foreach($campaignStats as $stat)
                            <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                                {{ $stat->source_type }} · sends {{ $stat->sends_count }} · opens {{ $stat->opens_count }} · clicks {{ $stat->clicks_count }}
                                <div class="text-xs text-white/55">Last engaged: {{ optional($stat->last_engaged_at)->format('Y-m-d') ?: '—' }}</div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="mt-2 text-xs text-white/65">No legacy campaign summaries linked yet.</p>
                @endif
            </article>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-white">Customer Communication Timeline</h3>
                <x-admin.help-hint tone="neutral" title="Timeline behavior">
                    Delivery statuses may change after initial send as Twilio callbacks arrive. Each retry creates a separate attempt in this timeline.
                </x-admin.help-hint>
                <div class="mt-3 space-y-2">
                    @forelse($deliveries as $delivery)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                            <div class="text-sm text-white">
                                {{ $delivery->campaign?->name ?: ('Campaign #' . $delivery->campaign_id) }} · {{ strtoupper($delivery->channel) }} · {{ $delivery->send_status }}
                            </div>
                            <div class="mt-1 text-xs text-white/60">
                                Attempt #{{ (int) $delivery->attempt_number }} · Sent {{ optional($delivery->sent_at)->format('Y-m-d H:i') ?: '—' }} · Delivered {{ optional($delivery->delivered_at)->format('Y-m-d H:i') ?: '—' }}
                            </div>
                            <div class="mt-1 text-xs text-white/50">SID: {{ $delivery->provider_message_id ?: '—' }}</div>
                            <div class="mt-1 text-xs text-white/55">{{ \Illuminate\Support\Str::limit((string) $delivery->rendered_message, 120) }}</div>
                            @if($delivery->error_code || $delivery->error_message)
                                <div class="mt-1 text-xs text-rose-200">{{ $delivery->error_code ?: 'error' }} · {{ \Illuminate\Support\Str::limit((string) $delivery->error_message, 90) }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/60">No SMS touches logged yet.</div>
                    @endforelse
                </div>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-white">Email Delivery Timeline</h3>
                <x-admin.help-hint tone="neutral" title="Provider callback behavior">
                    Delivery/open/click/bounce events are applied idempotently from provider webhooks. Duplicate callbacks do not create duplicate state transitions.
                </x-admin.help-hint>
                @php
                    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator|null $emailTimelinePaginator */
                    $emailTimelinePaginator = $emailDeliveryTimelinePaginator ?? null;
                    $emailTimelineRows = collect($emailDeliveryTimelineRows ?? []);
                    $emailProviderContextSummary = (array) ($emailDeliveryProviderContextSummary ?? []);
                    $emailTimelineFilters = (array) ($emailDeliveryTimelineFilters ?? []);
                    $emailTimelineFilterOptions = (array) ($emailDeliveryTimelineFilterOptions ?? []);
                    $timelineCurrentPage = (int) ($emailTimelinePaginator?->currentPage() ?? 1);
                    $timelinePerPage = (int) ($emailTimelinePaginator?->perPage() ?? max(1, $emailTimelineRows->count()));
                    $timelineTotal = (int) ($emailTimelinePaginator?->total() ?? $emailTimelineRows->count());
                    $timelineFrom = $timelineTotal > 0 ? (($timelineCurrentPage - 1) * $timelinePerPage) + 1 : 0;
                    $timelineTo = $timelineTotal > 0 ? min($timelineCurrentPage * $timelinePerPage, $timelineTotal) : 0;
                    $selectedResolutionSource = (string) ($emailTimelineFilters['provider_resolution_source'] ?? '');
                    $selectedReadinessStatus = (string) ($emailTimelineFilters['provider_readiness_status'] ?? '');
                    $selectedDateFrom = (string) ($emailTimelineFilters['date_from'] ?? '');
                    $selectedDateTo = (string) ($emailTimelineFilters['date_to'] ?? '');
                    $selectedStatus = (string) ($emailTimelineFilters['status'] ?? '');
                    $hasTimelineFilters = $selectedResolutionSource !== ''
                        || $selectedReadinessStatus !== ''
                        || $selectedDateFrom !== ''
                        || $selectedDateTo !== ''
                        || $selectedStatus !== '';
                    $resolutionFilterOptions = collect((array) ($emailTimelineFilterOptions['provider_resolution_sources'] ?? []));
                    $readinessFilterOptions = collect((array) ($emailTimelineFilterOptions['provider_readiness_statuses'] ?? []));
                    $statusFilterOptions = collect((array) ($emailTimelineFilterOptions['statuses'] ?? []));
                    $timelineExportQuery = array_filter([
                        'provider_resolution_source' => $selectedResolutionSource,
                        'provider_readiness_status' => $selectedReadinessStatus,
                        'date_from' => $selectedDateFrom,
                        'date_to' => $selectedDateTo,
                        'status' => $selectedStatus,
                    ], fn ($value) => is_string($value) && trim($value) !== '');
                    $timelineExportUrl = route('marketing.customers.email-deliveries.export', array_merge(
                        ['marketingProfile' => $profile],
                        $timelineExportQuery
                    ));
                    $resolutionSummaryRows = collect((array) ($emailProviderContextSummary['by_resolution_source'] ?? []))->take(4);
                    $readinessSummaryRows = collect((array) ($emailProviderContextSummary['by_readiness_status'] ?? []))->take(4);
                @endphp

                <form method="GET" action="{{ route('marketing.customers.show', $profile) }}" class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_160px_160px_minmax(0,1fr)_auto]">
                    <label class="text-xs text-white/75">
                        <span class="mb-1 block font-semibold text-white/80">Provider Resolution Source</span>
                        <select name="provider_resolution_source" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                            <option value="">All resolution sources</option>
                            @foreach($resolutionFilterOptions as $option)
                                <option value="{{ (string) ($option['key'] ?? '') }}" @selected($selectedResolutionSource === (string) ($option['key'] ?? ''))>
                                    {{ (string) ($option['label'] ?? 'Legacy / unavailable') }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-xs text-white/75">
                        <span class="mb-1 block font-semibold text-white/80">Provider Readiness Status</span>
                        <select name="provider_readiness_status" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                            <option value="">All readiness statuses</option>
                            @foreach($readinessFilterOptions as $option)
                                <option value="{{ (string) ($option['key'] ?? '') }}" @selected($selectedReadinessStatus === (string) ($option['key'] ?? ''))>
                                    {{ (string) ($option['label'] ?? 'Legacy / unavailable') }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-xs text-white/75">
                        <span class="mb-1 block font-semibold text-white/80">Date From</span>
                        <input type="date" name="date_from" value="{{ $selectedDateFrom }}" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white" />
                    </label>
                    <label class="text-xs text-white/75">
                        <span class="mb-1 block font-semibold text-white/80">Date To</span>
                        <input type="date" name="date_to" value="{{ $selectedDateTo }}" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white" />
                    </label>
                    <label class="text-xs text-white/75">
                        <span class="mb-1 block font-semibold text-white/80">Delivery Status</span>
                        <select name="status" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                            <option value="">All statuses</option>
                            @foreach($statusFilterOptions as $option)
                                <option value="{{ (string) ($option['key'] ?? '') }}" @selected($selectedStatus === (string) ($option['key'] ?? ''))>
                                    {{ (string) ($option['label'] ?? 'Unknown') }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex flex-wrap items-end gap-2">
                        <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-3 py-2 text-xs font-semibold text-emerald-100">
                            Apply filters
                        </button>
                        @if($hasTimelineFilters)
                            <a href="{{ route('marketing.customers.show', $profile) }}" class="inline-flex rounded-full border border-white/20 bg-white/5 px-3 py-2 text-xs font-semibold text-white/85">
                                Reset
                            </a>
                        @endif
                        <a href="{{ $timelineExportUrl }}" class="inline-flex rounded-full border border-sky-300/35 bg-sky-500/15 px-3 py-2 text-xs font-semibold text-sky-100">
                            Export CSV
                        </a>
                    </div>
                </form>

                @if($hasTimelineFilters)
                    <div class="mt-2 rounded-xl border border-emerald-300/25 bg-emerald-500/10 px-3 py-2 text-xs text-emerald-100">
                        Filtered results active. Timeline cards, summary chips, and CSV export all reflect the same active filters.
                    </div>
                @endif

                @if($timelineTotal > 0)
                    <div class="mt-2 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-xs text-white/75">
                        Showing <span class="font-semibold text-white">{{ $timelineFrom }}-{{ $timelineTo }}</span> of
                        <span class="font-semibold text-white">{{ $timelineTotal }}</span> filtered attempts.
                        Summary chips reflect the full filtered result set.
                    </div>
                @endif

                @if((int) ($emailProviderContextSummary['total_attempts'] ?? 0) > 0)
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 text-xs text-white/75">
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                            Tenant path attempts: <span class="font-semibold text-white">{{ (int) ($emailProviderContextSummary['tenant_path_attempts'] ?? 0) }}</span>
                            · Fallback path attempts: <span class="font-semibold text-white">{{ (int) ($emailProviderContextSummary['fallback_path_attempts'] ?? 0) }}</span>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                            Unsupported/incomplete attempts: <span class="font-semibold text-white">{{ (int) ($emailProviderContextSummary['unsupported_or_blocked_attempts'] ?? 0) }}</span>
                            · Legacy context rows: <span class="font-semibold text-white">{{ (int) ($emailProviderContextSummary['unknown_context_attempts'] ?? 0) }}</span>
                        </div>
                    </div>

                    <div class="mt-2 grid gap-2 sm:grid-cols-2 text-[11px] text-white/70">
                        <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                            <div class="font-semibold text-white/85">Resolution source mix</div>
                            <div class="mt-1">
                                @foreach($resolutionSummaryRows as $row)
                                    <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 mr-1 mb-1">
                                        {{ $row['label'] ?? 'Legacy / unavailable' }}: {{ (int) ($row['count'] ?? 0) }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                            <div class="font-semibold text-white/85">Readiness mix</div>
                            <div class="mt-1">
                                @foreach($readinessSummaryRows as $row)
                                    <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 mr-1 mb-1">
                                        {{ $row['label'] ?? 'Legacy / unavailable' }}: {{ (int) ($row['count'] ?? 0) }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                <div class="mt-3 space-y-2">
                    @forelse($emailTimelineRows as $row)
                        @php
                            /** @var \App\Models\MarketingEmailDelivery $delivery */
                            $delivery = $row['delivery'];
                            $providerContext = (array) ($row['provider_context'] ?? []);
                            $providerResolutionLabel = (string) ($providerContext['provider_resolution_source_label'] ?? 'Legacy / unavailable');
                            $providerReadinessLabel = (string) ($providerContext['provider_readiness_status_label'] ?? 'Legacy / unavailable');
                            $providerRuntimeLabel = (string) ($providerContext['provider_runtime_path_label'] ?? 'Legacy / unavailable');
                            $providerKey = (string) ($providerContext['provider'] ?? ($delivery->provider ?: 'unknown'));
                        @endphp
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                            <div class="text-sm text-white">
                                {{ $delivery->recipient?->campaign?->name ?: 'Campaign email' }} · {{ strtoupper((string) $delivery->status) }}
                            </div>
                            <div class="mt-1 text-xs text-white/60">
                                {{ $delivery->email }} · Sent {{ optional($delivery->sent_at)->format('Y-m-d H:i') ?: '—' }}
                            </div>
                            <div class="mt-1 text-xs text-white/55">
                                Delivered {{ optional($delivery->delivered_at)->format('Y-m-d H:i') ?: '—' }}
                                · Opened {{ optional($delivery->opened_at)->format('Y-m-d H:i') ?: '—' }}
                                · Clicked {{ optional($delivery->clicked_at)->format('Y-m-d H:i') ?: '—' }}
                            </div>
                            <div class="mt-1 text-xs text-white/55">
                                Provider {{ strtoupper($providerKey) }} · {{ $providerResolutionLabel }} · {{ $providerReadinessLabel }}
                                @if((bool) ($providerContext['provider_using_fallback_config'] ?? false))
                                    · fallback config active
                                @endif
                            </div>
                            <div class="mt-1 text-xs text-white/50">
                                Canonical status: {{ strtoupper((string) ($row['normalized_status'] ?? 'attempted')) }}
                            </div>
                            <div class="mt-1 text-xs text-sky-100">
                                {{ (string) ($row['context_label'] ?? 'Provider context unavailable.') }}
                            </div>
                            <div class="mt-1 text-xs text-white/50">Runtime path: {{ $providerRuntimeLabel }}</div>
                            @if(! empty($row['failure_context_hint']))
                                <div class="mt-1 text-xs text-rose-200">{{ (string) $row['failure_context_hint'] }}</div>
                            @endif
                            <div class="mt-1 text-xs text-white/45">Provider ID: {{ $delivery->provider_message_id ?: $delivery->sendgrid_message_id ?: '—' }}</div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/60">
                            {{ $hasTimelineFilters ? 'No email touches match the active timeline filters.' : 'No email touches logged yet.' }}
                        </div>
                    @endforelse
                </div>

                @if($emailTimelinePaginator && $emailTimelinePaginator->hasPages())
                    <div class="mt-3">
                        {{ $emailTimelinePaginator->onEachSide(1)->links() }}
                    </div>
                @endif
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-white">Campaign Conversion History</h3>
                <x-admin.help-hint tone="neutral" title="Attribution summary">
                    Conversion rows can be `code_based`, `last_touch`, or `assisted`. Attribution is conservative and may be partial when source order data is incomplete.
                </x-admin.help-hint>
                <div class="mt-3 space-y-2">
                    @forelse($conversions as $conversion)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/80">
                            <div>{{ $conversion->campaign?->name ?: ('Campaign #' . $conversion->campaign_id) }} · {{ $conversion->attribution_type }}</div>
                            <div class="mt-1 text-xs text-white/60">{{ $conversion->source_type }}:{{ $conversion->source_id }} · {{ optional($conversion->converted_at)->format('Y-m-d H:i') ?: '—' }}</div>
                            <div class="mt-1 text-xs text-white/60">Order total: {{ $conversion->order_total !== null ? '$' . number_format((float) $conversion->order_total, 2) : '—' }}</div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/60">No attributed conversions linked yet.</div>
                    @endforelse
                </div>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 lg:col-span-2">
                <h3 class="text-sm font-semibold text-white">Consent Event History</h3>
                <div class="mt-3 overflow-x-auto rounded-2xl border border-white/10">
                    <table class="min-w-full text-sm">
                        <thead class="bg-white/5 text-white/65">
                            <tr>
                                <th class="px-4 py-3 text-left">Occurred</th>
                                <th class="px-4 py-3 text-left">Channel</th>
                                <th class="px-4 py-3 text-left">Event</th>
                                <th class="px-4 py-3 text-left">Source</th>
                                <th class="px-4 py-3 text-left">Details</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @forelse($consentEvents as $event)
                                <tr>
                                    <td class="px-4 py-3 text-white/75">{{ optional($event->occurred_at)->format('Y-m-d H:i') ?: optional($event->created_at)->format('Y-m-d H:i') }}</td>
                                    <td class="px-4 py-3 text-white/75">{{ strtoupper($event->channel) }}</td>
                                    <td class="px-4 py-3 text-white/75">{{ $event->event_type }}</td>
                                    <td class="px-4 py-3 text-white/60">{{ $event->source_type ?: '—' }}{{ $event->source_id ? (':' . $event->source_id) : '' }}</td>
                                    <td class="px-4 py-3 text-white/60">{{ \Illuminate\Support\Str::limit(json_encode($event->details), 100) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-6 text-center text-white/55">No consent events recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-white">Groups</h3>
            <div class="flex flex-wrap gap-2">
                @forelse($profile->groups as $group)
                    <a href="{{ route('marketing.groups.show', $group) }}" wire:navigate
                       class="inline-flex rounded-full border {{ $group->is_internal ? 'border-amber-300/35 bg-amber-500/20 text-amber-100' : 'border-white/15 bg-white/5 text-white/80' }} px-3 py-1 text-xs font-semibold">
                        {{ $group->name }}{{ $group->is_internal ? ' · internal' : '' }}
                    </a>
                @empty
                    <span class="text-sm text-white/60">No groups assigned.</span>
                @endforelse
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <form method="POST" action="{{ route('marketing.groups.members.add', ['group' => $allGroups->first()?->id ?? 0]) }}" class="rounded-2xl border border-white/10 bg-white/5 p-4 space-y-3" id="add_to_group_form">
                    @csrf
                    <input type="hidden" name="marketing_profile_id" value="{{ $profile->id }}">
                    <input type="hidden" name="return_to" value="customer:{{ $profile->id }}">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Add to Group</div>
                    <select id="customer_group_select" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                        @forelse($allGroups as $groupOption)
                            <option value="{{ $groupOption->id }}">{{ $groupOption->name }}{{ $groupOption->is_internal ? ' (internal)' : '' }}</option>
                        @empty
                            <option value="">No groups available</option>
                        @endforelse
                    </select>
                    <button type="submit" @disabled($allGroups->isEmpty()) class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">
                        Add to group
                    </button>
                </form>

                <div class="rounded-2xl border border-white/10 bg-white/5 p-4 space-y-3">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Create New Group</div>
                    <a href="{{ route('marketing.groups.create', ['marketing_profile_id' => $profile->id]) }}" wire:navigate
                       class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/85">
                        Create new group
                    </a>
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-white">Storefront / Public Flow Links</h3>
            <x-admin.help-hint tone="neutral" title="Touchpoint tracking">
                These links show where this profile was touched by Shopify widget calls or minimal public event routes. They improve attribution and help diagnose cross-channel identity history.
            </x-admin.help-hint>
            <div class="flex flex-wrap items-center gap-2 text-xs text-white/70">
                <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2.5 py-1">Open issues: {{ (int) ($openStorefrontIssues ?? 0) }}</span>
                <a href="{{ route('marketing.operations.reconciliation') }}" wire:navigate class="inline-flex rounded-full border border-amber-300/35 bg-amber-500/15 px-2.5 py-1 font-semibold text-amber-100">
                    Open Reconciliation Ops
                </a>
            </div>
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Source Type</th>
                            <th class="px-4 py-3 text-left">Source ID</th>
                            <th class="px-4 py-3 text-left">Match Method</th>
                            <th class="px-4 py-3 text-left">Meta</th>
                            <th class="px-4 py-3 text-left">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($storefrontTouchpoints as $touchpoint)
                            <tr>
                                <td class="px-4 py-3 text-white/75">{{ $touchpoint->source_type }}</td>
                                <td class="px-4 py-3 text-white/65 font-mono">{{ $touchpoint->source_id }}</td>
                                <td class="px-4 py-3 text-white/60">{{ $touchpoint->match_method ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/55">{{ \Illuminate\Support\Str::limit(json_encode($touchpoint->source_meta), 110) }}</td>
                                <td class="px-4 py-3 text-white/55">{{ optional($touchpoint->created_at)->format('Y-m-d H:i') ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-5 text-center text-white/55">No storefront/public flow links recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <h4 class="text-sm font-semibold text-white">Widget/Public Event Timeline</h4>
            <x-admin.help-hint tone="neutral" title="State + recovery visibility">
                Widget/public events store explicit status and unresolved issue signals (for example `verification_required`, `issued_not_reconciled`, `signature_verification_failed`). Use this timeline for storefront troubleshooting.
            </x-admin.help-hint>
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Occurred</th>
                            <th class="px-4 py-3 text-left">Event</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Issue</th>
                            <th class="px-4 py-3 text-left">Resolution</th>
                            <th class="px-4 py-3 text-left">Meta</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse(($storefrontEvents ?? collect()) as $eventRow)
                            <tr>
                                <td class="px-4 py-3 text-white/65">{{ optional($eventRow->occurred_at ?: $eventRow->created_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/80">
                                    {{ $eventRow->event_type }}
                                    <div class="text-xs text-white/50">{{ $eventRow->endpoint ?: '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-white/70">{{ strtoupper((string) $eventRow->status) }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $eventRow->issue_type ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/60">
                                    {{ strtoupper((string) $eventRow->resolution_status) }}
                                    @if($eventRow->resolved_at)
                                        <div class="text-xs text-white/45">{{ optional($eventRow->resolved_at)->format('Y-m-d H:i') }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-white/55">{{ \Illuminate\Support\Str::limit(json_encode($eventRow->meta), 110) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-5 text-center text-white/55">No widget/public event timeline rows yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 space-y-4">
                <h3 class="text-sm font-semibold text-white">Candle Cash</h3>
                <div class="text-2xl font-semibold text-white">{{ app(\App\Services\Marketing\CandleCashService::class)->formatRewardCurrency(app(\App\Services\Marketing\CandleCashService::class)->amountFromPoints((int) $candleBalance)) }}</div>
                <x-admin.help-hint tone="neutral" title="Rewards ledger behavior">
                    All Candle Cash changes are appended to the transaction ledger. Redemptions are issued first, then reconciled as redeemed, canceled, or expired. Shopify code usage is validated during ingestion, while Square event usage can be staff-reconciled.
                </x-admin.help-hint>
                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-white/75">Issued: {{ (int) ($redemptionSummary['issued'] ?? 0) }}</span>
                    <span class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/20 px-2 py-0.5 text-emerald-100">Redeemed: {{ (int) ($redemptionSummary['redeemed'] ?? 0) }}</span>
                    <span class="inline-flex rounded-full border border-amber-300/35 bg-amber-500/20 px-2 py-0.5 text-amber-100">Canceled: {{ (int) ($redemptionSummary['canceled'] ?? 0) }}</span>
                    <span class="inline-flex rounded-full border border-rose-300/35 bg-rose-500/20 px-2 py-0.5 text-rose-100">Expired: {{ (int) ($redemptionSummary['expired'] ?? 0) }}</span>
                </div>

                <form method="POST" action="{{ route('marketing.customers.candle-cash.grant', $profile) }}" class="space-y-2 rounded-2xl border border-white/10 bg-white/5 p-3">
                    @csrf
                    <div class="grid gap-2 sm:grid-cols-3">
                        <select name="type" class="rounded-xl border border-white/10 bg-black/20 px-2.5 py-2 text-xs text-white">
                            <option value="earn">Earn</option>
                            <option value="adjust">Adjust</option>
                        </select>
                        <input type="number" step="0.01" name="amount" required placeholder="Candle Cash (+/-)" class="rounded-xl border border-white/10 bg-black/20 px-2.5 py-2 text-xs text-white">
                        <input type="text" name="description" placeholder="Description" class="rounded-xl border border-white/10 bg-black/20 px-2.5 py-2 text-xs text-white">
                    </div>
                    <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-3 py-1.5 text-xs font-semibold text-white">
                        Save Candle Cash Entry
                    </button>
                </form>

                <form method="POST" action="{{ route('marketing.customers.candle-cash.redeem', $profile) }}" class="space-y-2 rounded-2xl border border-white/10 bg-white/5 p-3">
                    @csrf
                    <div class="grid gap-2 sm:grid-cols-3">
                        <select name="reward_id" class="rounded-xl border border-white/10 bg-black/20 px-2.5 py-2 text-xs text-white">
                            @foreach($activeRewards as $reward)
                                <option value="{{ $reward->id }}">{{ $reward->name }} ({{ app(\App\Services\Marketing\CandleCashService::class)->formatRewardCurrency(app(\App\Services\Marketing\CandleCashService::class)->amountFromPoints((int) $reward->candle_cash_cost)) }})</option>
                            @endforeach
                        </select>
                        <select name="platform" class="rounded-xl border border-white/10 bg-black/20 px-2.5 py-2 text-xs text-white">
                            <option value="shopify">Shopify</option>
                            <option value="square">Square</option>
                        </select>
                        <button type="submit" @disabled($activeRewards->isEmpty()) class="inline-flex rounded-full border border-amber-300/35 bg-amber-500/15 px-3 py-1.5 text-xs font-semibold text-amber-100 disabled:opacity-40">
                            Redeem
                        </button>
                    </div>
                </form>

                <div class="grid gap-3 md:grid-cols-2">
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                        <h4 class="text-xs font-semibold uppercase tracking-[0.2em] text-white/55">Recent Transactions</h4>
                        <div class="mt-2 space-y-1.5 text-xs text-white/75">
                            @forelse($candleTransactions as $transaction)
                                <div>
                                    {{ strtoupper($transaction->type) }} {{ app(\App\Services\Marketing\CandleCashService::class)->candleCashAmountLabelFromPoints((int) $transaction->candle_cash_delta, true) }}
                                    · {{ $transaction->source }}
                                    <div class="text-white/55">{{ optional($transaction->created_at)->format('Y-m-d H:i') }} · {{ $transaction->description ?: '—' }}</div>
                                </div>
                            @empty
                                <div class="text-white/55">No transactions yet.</div>
                            @endforelse
                        </div>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                        <h4 class="text-xs font-semibold uppercase tracking-[0.2em] text-white/55">Issued/Redemption Lifecycle</h4>
                        <div class="mt-2 space-y-1.5 text-xs text-white/75">
                            @forelse($candleRedemptions as $redemption)
                                <div class="rounded-xl border border-white/10 bg-black/20 p-2.5 space-y-1.5">
                                    <div class="font-semibold text-white/90">
                                        {{ $redemption->reward?->name ?: ('Reward #' . $redemption->reward_id) }}
                                        <span class="text-[10px] uppercase tracking-[0.18em] text-white/55">· {{ strtoupper((string) ($redemption->status ?: 'issued')) }}</span>
                                    </div>
                                    <div class="text-white/65">{{ app(\App\Services\Marketing\CandleCashService::class)->formatRewardCurrency(app(\App\Services\Marketing\CandleCashService::class)->amountFromPoints((int) $redemption->candle_cash_spent)) }} · <span class="font-mono">{{ $redemption->redemption_code }}</span></div>
                                    <div class="text-white/55">
                                        {{ strtoupper((string) ($redemption->platform ?: 'n/a')) }}
                                        · {{ optional($redemption->issued_at ?: $redemption->created_at)->format('Y-m-d H:i') ?: '—' }}
                                        @if($redemption->redeemed_at)
                                            · redeemed {{ optional($redemption->redeemed_at)->format('Y-m-d H:i') }}
                                        @endif
                                    </div>
                                    @if($redemption->external_order_source || $redemption->external_order_id)
                                        <div class="text-white/55">Order link: {{ $redemption->external_order_source ?: '—' }}{{ $redemption->external_order_id ? (' · ' . $redemption->external_order_id) : '' }}</div>
                                    @endif
                                    @if($redemption->reconciliation_notes)
                                        <div class="text-white/55">Notes: {{ $redemption->reconciliation_notes }}</div>
                                    @endif
                                    @if(($redemption->status ?? 'issued') === 'issued')
                                        <div class="flex flex-wrap gap-2">
                                            <form method="POST" action="{{ route('marketing.customers.candle-cash.redemptions.mark-redeemed', [$profile, $redemption]) }}" class="flex flex-wrap items-center gap-1.5">
                                                @csrf
                                                <input type="hidden" name="platform" value="square" />
                                                <input type="hidden" name="external_order_source" value="square_manual" />
                                                <input type="text" name="external_order_id" placeholder="Order ID" class="w-28 rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-[11px] text-white" />
                                                <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/20 px-2.5 py-1 text-[11px] font-semibold text-emerald-100">
                                                    Mark Redeemed
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('marketing.customers.candle-cash.redemptions.cancel', [$profile, $redemption]) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex rounded-full border border-rose-300/35 bg-rose-500/20 px-2.5 py-1 text-[11px] font-semibold text-rose-100">
                                                    Cancel
                                                </button>
                                            </form>
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <div class="text-white/55">No redemptions yet.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </article>
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 xl:col-span-2">
                <h3 class="text-sm font-semibold text-white">Marketing Likelihood</h3>
                <x-admin.help-hint tone="neutral" title="Score explainability">
                    Score is a transparent 0–100 weighted sum using recency, order frequency, spend signals, consent, source diversity, event activity, and legacy engagement.
                </x-admin.help-hint>
                <div class="mt-3 text-sm text-white/80">
                    Current score: <span class="font-semibold text-white">{{ $profile->marketing_score !== null ? number_format((float) $profile->marketing_score, 0) . '%' : 'Pending' }}</span>
                </div>
                @php
                    $scoreComponents = (array) data_get($latestScore?->reasons_json, 'components', []);
                @endphp
                @if($scoreComponents !== [])
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 text-xs text-white/70">
                        @foreach($scoreComponents as $component => $value)
                            <div class="rounded-xl border border-white/10 bg-white/5 px-2.5 py-1.5">
                                {{ str_replace('_', ' ', $component) }}: {{ $value }}
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="mt-2 text-xs text-white/65">Score breakdown is not available yet.</p>
                @endif
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-white">Quick Actions</h3>
            <x-admin.help-hint tone="neutral" title="Action safety">
                Quick actions create recommendations or queue campaign recipients. They do not directly send provider messages.
            </x-admin.help-hint>

            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <h4 class="text-sm font-semibold text-white">Next Best Action</h4>
                <x-admin.help-hint tone="neutral" title="Rule-based guidance">
                    This recommendation is computed from observed orders, consent, event activity, and reward state. It is suggestive only and does not auto-send.
                </x-admin.help-hint>
                <div class="mt-2 text-sm text-white">
                    {{ $nextBestAction['title'] ?? 'No action recommended right now' }}
                </div>
                <div class="mt-1 text-xs text-white/65">{{ $nextBestAction['summary'] ?? '—' }}</div>
                <div class="mt-2 flex flex-wrap gap-2 text-xs text-white/60">
                    <span class="inline-flex rounded-full border border-white/15 bg-black/20 px-2 py-0.5">Key: {{ $nextBestAction['action_key'] ?? 'none' }}</span>
                    <span class="inline-flex rounded-full border border-white/15 bg-black/20 px-2 py-0.5">Confidence: {{ number_format((float) ($nextBestAction['confidence'] ?? 0), 2) }}</span>
                    @if(!empty($nextBestAction['suggested_channel']))
                        <span class="inline-flex rounded-full border border-white/15 bg-black/20 px-2 py-0.5">Channel: {{ strtoupper((string) $nextBestAction['suggested_channel']) }}</span>
                    @endif
                </div>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach((array) ($nextBestAction['reasons'] ?? []) as $reason)
                        <span class="inline-flex rounded-full border border-white/15 bg-black/20 px-2 py-0.5 text-[11px] text-white/70">{{ $reason }}</span>
                    @endforeach
                </div>
            </article>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="space-y-3">
                    <form method="POST" action="{{ route('marketing.recommendations.create-for-profile', $profile) }}">
                        @csrf
                        <input type="hidden" name="redirect_to" value="profile" />
                        <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                            Create One-Off Recommendation
                        </button>
                    </form>
                    <a href="{{ route('marketing.message-templates.create', ['channel' => 'sms', 'profile_id' => $profile->id]) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/85">
                        Open Prefilled SMS Draft
                    </a>
                    <a href="{{ route('marketing.message-templates.create', ['channel' => 'email', 'profile_id' => $profile->id]) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/85">
                        Open Prefilled Email Draft
                    </a>
                </div>

                <div class="space-y-3">
                    <form method="POST" action="{{ route('marketing.campaigns.add-profile', ['campaign' => ($campaignOptions->first()?->id ?? 0)]) }}" class="grid gap-2">
                        @csrf
                        <input type="hidden" name="marketing_profile_id" value="{{ $profile->id }}" />
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Add To Campaign</label>
                        <select name="campaign_id" id="campaign_select" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            @forelse($campaignOptions as $campaignOption)
                                <option value="{{ $campaignOption->id }}">{{ $campaignOption->name }} ({{ $campaignOption->status }})</option>
                            @empty
                                <option value="">No campaign options</option>
                            @endforelse
                        </select>
                        <input type="text" name="notes" placeholder="Optional note" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                        <button type="submit" @disabled($campaignOptions->isEmpty()) class="inline-flex w-fit rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/85 disabled:opacity-40">
                            Add Profile To Selected Campaign
                        </button>
                    </form>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <h4 class="text-sm font-semibold text-white">Matching Segments</h4>
                    <div class="mt-2 space-y-2 text-sm text-white/80">
                        @forelse($matchingSegments as $segment)
                            <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                                <div class="font-semibold">{{ $segment['name'] }}</div>
                                <div class="mt-1 text-xs text-white/60">{{ implode(', ', $segment['reasons']) }}</div>
                            </div>
                        @empty
                            <div class="text-xs text-white/60">No active segments matched under current rules.</div>
                        @endforelse
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <h4 class="text-sm font-semibold text-white">Score Breakdown</h4>
                    <div class="mt-2 text-xs text-white/70">
                        @if($latestScore)
                            Calculated at {{ optional($latestScore->calculated_at)->format('Y-m-d H:i') }}.
                        @else
                            Score breakdown not stored yet.
                        @endif
                    </div>
                    <pre class="mt-3 whitespace-pre-wrap text-xs text-white/75">{{ json_encode(($latestScore?->reasons_json ?? $scoreResult['reasons'] ?? []), JSON_PRETTY_PRINT) }}</pre>
                </article>
            </div>
        </section>

        <script>
            (() => {
                const form = document.querySelector('form[action*="/marketing/campaigns/"][action*="/add-profile"]');
                const select = document.getElementById('campaign_select');
                if (!form || !select) return;
                const updateAction = () => {
                    const campaignId = select.value;
                    if (!campaignId) return;
                    form.action = "{{ route('marketing.campaigns.add-profile', ['campaign' => '__campaign__']) }}".replace('__campaign__', campaignId);
                };
                select.addEventListener('change', updateAction);
                updateAction();

                const groupForm = document.getElementById('add_to_group_form');
                const groupSelect = document.getElementById('customer_group_select');
                if (groupForm && groupSelect) {
                    const updateGroupAction = () => {
                        const groupId = groupSelect.value;
                        if (!groupId) return;
                        groupForm.action = "{{ route('marketing.groups.members.add', ['group' => '__group__']) }}".replace('__group__', groupId);
                    };
                    groupSelect.addEventListener('change', updateGroupAction);
                    updateGroupAction();
                }
            })();
        </script>
    </div>
</x-layouts::app>
