<x-layouts::app :title="'Segment Preview'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Segment Preview"
            description="Live audience preview for the selected rule set. Counts and samples update as profile data changes."
            hint-title="Preview behavior"
            hint-text="Previews are derived from current profile state. Preparing campaign recipients later creates a materialized snapshot for approvals."
        />

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-5">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Segment</div>
                    <h2 class="mt-1 text-xl font-semibold text-zinc-950">{{ $segment->name }}</h2>
                    <p class="mt-1 text-sm text-zinc-600">{{ $segment->description ?: 'No segment description yet.' }}</p>
                    <div class="mt-2 text-xs text-zinc-500">Status: {{ $segment->status }} · Channel scope: {{ $segment->channel_scope ?: 'any' }}</div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('marketing.segments.edit', $segment) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700">Edit Segment</a>
                    <a href="{{ route('marketing.campaigns.create', ['segment_id' => $segment->id]) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-zinc-950">Start Campaign</a>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Estimated Audience</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) $preview['count']) }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Sample Size</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format($preview['profiles']->count()) }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Last Previewed</div>
                    <div class="mt-2 text-sm text-zinc-950">{{ optional($segment->last_previewed_at)->format('Y-m-d H:i') ?: 'Just now' }}</div>
                </article>
            </div>

            <form method="GET" action="{{ route('marketing.segments.preview', $segment) }}" class="grid gap-3 md:grid-cols-12">
                <div class="md:col-span-8">
                    <label for="search" class="text-xs uppercase tracking-[0.2em] text-zinc-500">Search Profiles</label>
                    <input id="search" type="text" name="search" value="{{ $search }}" placeholder="Name, email, or phone" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 placeholder:text-zinc-500" />
                </div>
                <div class="md:col-span-2">
                    <label for="sample_size" class="text-xs uppercase tracking-[0.2em] text-zinc-500">Sample Rows</label>
                    <input id="sample_size" type="number" name="sample_size" min="5" max="100" value="{{ $sampleSize }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                </div>
                <div class="md:col-span-2 flex items-end">
                    <button type="submit" class="w-full rounded-xl border border-zinc-300 bg-emerald-100 px-3 py-2 text-sm font-semibold text-zinc-950">Refresh</button>
                </div>
            </form>

            <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Profile</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Email</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Phone</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Reasons</th>
                            <th class="px-4 py-3 text-right whitespace-nowrap">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($preview['profiles'] as $profile)
                            @php
                                $match = collect($preview['matches'])->firstWhere('profile_id', (int) $profile->id);
                                $name = trim((string) ($profile->first_name . ' ' . $profile->last_name));
                            @endphp
                            <tr>
                                <td class="px-4 py-3 text-zinc-800">
                                    {{ $name !== '' ? $name : 'Unnamed profile' }}
                                    <div class="text-xs text-zinc-500">#{{ $profile->id }}</div>
                                </td>
                                <td class="px-4 py-3 text-zinc-700">{{ $profile->email ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-700">{{ $profile->phone ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach(($match['reasons'] ?? []) as $reason)
                                            <span class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-2 py-0.5 text-[11px] text-zinc-600">{{ $reason }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('marketing.customers.show', $profile) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-700">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-zinc-500">No matching profiles for the current preview query.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts::app>
