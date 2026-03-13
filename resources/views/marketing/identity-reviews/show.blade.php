<x-layouts::app :title="'Review Match'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Review Match"
            description="Resolve a conflicting identity safely by linking to an existing profile, creating a new profile, or dismissing the review."
            hint-title="Review safety rule"
            hint-text="Do not force uncertain merges. Use this page to make explicit reviewer choices and keep an audit trail of the decision."
        />

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-white">Review #{{ $review->id }}</h2>
                    <div class="text-xs text-white/55">
                        Status: {{ ucfirst($review->status) }}
                        · Source: {{ $review->source_type }}/{{ $review->source_id }}
                        · Created: {{ optional($review->created_at)->format('Y-m-d H:i') }}
                    </div>
                </div>
                <a href="{{ route('marketing.identity-review') }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/75 hover:bg-white/10">
                    Back to Queue
                </a>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Raw Name</div>
                    <div class="mt-2 text-sm text-white">{{ trim(($review->raw_first_name ?? '') . ' ' . ($review->raw_last_name ?? '')) ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Raw Email</div>
                    <div class="mt-2 text-sm text-white">{{ $review->raw_email ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Raw Phone</div>
                    <div class="mt-2 text-sm text-white">{{ $review->raw_phone ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Conflict Reasons</div>
                    <div class="mt-2 text-sm text-white">{{ is_array($review->conflict_reasons) ? implode(', ', $review->conflict_reasons) : '—' }}</div>
                </article>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <h2 class="text-lg font-semibold text-white">Action A: Link to Existing Profile</h2>
                <form method="GET" action="{{ route('marketing.identity-review.show', $review) }}" class="flex items-center gap-2">
                    <input
                        type="text"
                        name="profile_search"
                        value="{{ $profileSearch }}"
                        placeholder="Search profiles"
                        class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-white/35"
                    />
                    <button type="submit" class="rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm font-semibold text-white/80">
                        Find
                    </button>
                </form>
            </div>

            <div class="mt-4 overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Profile</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Email</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Phone</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Channels</th>
                            <th class="px-4 py-3 text-right whitespace-nowrap">Resolve</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($candidateProfiles as $profile)
                            <tr>
                                <td class="px-4 py-3 text-white/80">
                                    {{ trim(($profile->first_name ?? '') . ' ' . ($profile->last_name ?? '')) ?: 'Unnamed profile' }}
                                    <div class="text-xs text-white/45">#{{ $profile->id }}</div>
                                </td>
                                <td class="px-4 py-3 text-white/70">{{ $profile->email ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $profile->phone ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach((array) $profile->source_channels as $channel)
                                            <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-[11px] text-white/70">{{ $channel }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('marketing.identity-review.resolve-existing', $review) }}" class="inline-flex items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="profile_id" value="{{ $profile->id }}" />
                                        <input type="text" name="resolution_notes" placeholder="Notes (optional)" class="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-xs text-white placeholder:text-white/35" />
                                        <button type="submit" class="rounded-full border border-emerald-300/35 bg-emerald-500/15 px-3 py-1 text-xs font-semibold text-white">
                                            Link
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-white/55">No candidate profiles available.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h2 class="text-lg font-semibold text-white">Action B: Create New Profile</h2>
                <form method="POST" action="{{ route('marketing.identity-review.resolve-new', $review) }}" class="mt-4 grid gap-3">
                    @csrf
                    <div class="grid gap-3 md:grid-cols-2">
                        <input type="text" name="first_name" value="{{ old('first_name', $review->raw_first_name) }}" placeholder="First name" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-white/35" />
                        <input type="text" name="last_name" value="{{ old('last_name', $review->raw_last_name) }}" placeholder="Last name" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-white/35" />
                    </div>
                    <input type="email" name="email" value="{{ old('email', $review->raw_email) }}" placeholder="Email" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-white/35" />
                    <input type="text" name="phone" value="{{ old('phone', $review->raw_phone) }}" placeholder="Phone" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-white/35" />
                    <textarea name="resolution_notes" rows="3" placeholder="Resolution notes (optional)" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-white/35">{{ old('resolution_notes') }}</textarea>
                    <button type="submit" class="inline-flex w-fit rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                        Create profile and resolve
                    </button>
                </form>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h2 class="text-lg font-semibold text-white">Action C: Dismiss / Ignore</h2>
                <p class="mt-2 text-sm text-white/65">Use only when the review is non-actionable or intentionally not mergeable right now.</p>
                <form method="POST" action="{{ route('marketing.identity-review.ignore', $review) }}" class="mt-4 grid gap-3">
                    @csrf
                    <textarea name="resolution_notes" rows="4" required placeholder="Reason for dismissal" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-white/35">{{ old('resolution_notes') }}</textarea>
                    <button type="submit" class="inline-flex w-fit rounded-full border border-amber-300/35 bg-amber-500/15 px-4 py-2 text-sm font-semibold text-white">
                        Dismiss review
                    </button>
                </form>
            </article>
        </section>
    </div>
</x-layouts::app>
