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

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-950">Review #{{ $review->id }}</h2>
                    <div class="text-xs text-zinc-500">
                        Status: {{ ucfirst($review->status) }}
                        · Source: {{ $review->source_type }}/{{ $review->source_id }}
                        · Created: {{ optional($review->created_at)->format('Y-m-d H:i') }}
                    </div>
                </div>
                <a href="{{ route('marketing.identity-review') }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                    Back to Queue
                </a>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Raw Name</div>
                    <div class="mt-2 text-sm text-zinc-950">{{ trim(($review->raw_first_name ?? '') . ' ' . ($review->raw_last_name ?? '')) ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Raw Email</div>
                    <div class="mt-2 text-sm text-zinc-950">{{ $review->raw_email ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Raw Phone</div>
                    <div class="mt-2 text-sm text-zinc-950">{{ $review->raw_phone ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Conflict Reasons</div>
                    <div class="mt-2 text-sm text-zinc-950">{{ is_array($review->conflict_reasons) ? implode(', ', $review->conflict_reasons) : '—' }}</div>
                </article>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <h2 class="text-lg font-semibold text-zinc-950">Action A: Link to Existing Profile</h2>
                <form method="GET" action="{{ route('marketing.identity-review.show', $review) }}" class="flex items-center gap-2">
                    <input
                        type="text"
                        name="profile_search"
                        value="{{ $profileSearch }}"
                        placeholder="Search profiles"
                        class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 placeholder:text-zinc-500"
                    />
                    <button type="submit" class="rounded-xl border border-zinc-300 bg-zinc-50 px-3 py-2 text-sm font-semibold text-zinc-700">
                        Find
                    </button>
                </form>
            </div>

            <div class="mt-4 overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Profile</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Email</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Phone</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Channels</th>
                            <th class="px-4 py-3 text-right whitespace-nowrap">Resolve</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($candidateProfiles as $profile)
                            <tr>
                                <td class="px-4 py-3 text-zinc-700">
                                    {{ trim(($profile->first_name ?? '') . ' ' . ($profile->last_name ?? '')) ?: 'Unnamed profile' }}
                                    <div class="text-xs text-zinc-500">#{{ $profile->id }}</div>
                                </td>
                                <td class="px-4 py-3 text-zinc-600">{{ $profile->email ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ $profile->phone ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach((array) $profile->source_channels as $channel)
                                            <span class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-2 py-0.5 text-[11px] text-zinc-600">{{ $channel }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('marketing.identity-review.resolve-existing', $review) }}" class="inline-flex items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="profile_id" value="{{ $profile->id }}" />
                                        <input type="text" name="resolution_notes" placeholder="Notes (optional)" class="rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1 text-xs text-zinc-950 placeholder:text-zinc-500" />
                                        <button type="submit" class="rounded-full border border-zinc-300 bg-emerald-100 px-3 py-1 text-xs font-semibold text-zinc-950">
                                            Link
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-zinc-500">No candidate profiles available.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6">
                <h2 class="text-lg font-semibold text-zinc-950">Action B: Create New Profile</h2>
                <form method="POST" action="{{ route('marketing.identity-review.resolve-new', $review) }}" class="mt-4 grid gap-3">
                    @csrf
                    <div class="grid gap-3 md:grid-cols-2">
                        <input type="text" name="first_name" value="{{ old('first_name', $review->raw_first_name) }}" placeholder="First name" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 placeholder:text-zinc-500" />
                        <input type="text" name="last_name" value="{{ old('last_name', $review->raw_last_name) }}" placeholder="Last name" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 placeholder:text-zinc-500" />
                    </div>
                    <input type="email" name="email" value="{{ old('email', $review->raw_email) }}" placeholder="Email" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 placeholder:text-zinc-500" />
                    <input type="text" name="phone" value="{{ old('phone', $review->raw_phone) }}" placeholder="Phone" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 placeholder:text-zinc-500" />
                    <textarea name="resolution_notes" rows="3" placeholder="Resolution notes (optional)" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 placeholder:text-zinc-500">{{ old('resolution_notes') }}</textarea>
                    <button type="submit" class="inline-flex w-fit rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950">
                        Create profile and resolve
                    </button>
                </form>
            </article>

            <article class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6">
                <h2 class="text-lg font-semibold text-zinc-950">Action C: Dismiss / Ignore</h2>
                <p class="mt-2 text-sm text-zinc-600">Use only when the review is non-actionable or intentionally not mergeable right now.</p>
                <form method="POST" action="{{ route('marketing.identity-review.ignore', $review) }}" class="mt-4 grid gap-3">
                    @csrf
                    <textarea name="resolution_notes" rows="4" required placeholder="Reason for dismissal" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 placeholder:text-zinc-500">{{ old('resolution_notes') }}</textarea>
                    <button type="submit" class="inline-flex w-fit rounded-full border border-amber-300/35 bg-amber-100 px-4 py-2 text-sm font-semibold text-zinc-950">
                        Dismiss review
                    </button>
                </form>
            </article>
        </section>
    </div>
</x-layouts::app>
