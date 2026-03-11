<x-layouts::app :title="'Marketing Segments'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Segments"
            description="Rule-based targeting segments built from marketing profile, source, event, consent, and recency signals."
            hint-title="How segment rules work"
            hint-text="Segments are explainable rule groups (AND/OR). Audience previews are derived live from profile data and can change as customer state changes."
        />

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-white">Segment Library</h2>
                <a href="{{ route('marketing.segments.create') }}" wire:navigate class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                    Create Segment
                </a>
            </div>

            <div class="mt-4 overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Name</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Status</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Channel Scope</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Type</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Last Previewed</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Updated</th>
                            <th class="px-4 py-3 text-right whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($segments as $segment)
                            <tr class="hover:bg-white/5">
                                <td class="px-4 py-3 text-white/85">
                                    {{ $segment->name }}
                                    <div class="text-xs text-white/55">{{ $segment->description ?: '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-white/75">{{ $segment->status }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $segment->channel_scope ?: 'any' }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $segment->is_system ? 'System' : 'Custom' }}</td>
                                <td class="px-4 py-3 text-white/65">{{ optional($segment->last_previewed_at)->format('Y-m-d H:i') ?: 'Never' }}</td>
                                <td class="px-4 py-3 text-white/65">{{ optional($segment->updated_at)->format('Y-m-d') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('marketing.segments.preview', $segment) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs text-white/85">Preview</a>
                                        <a href="{{ route('marketing.segments.edit', $segment) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs text-white/85">Edit</a>
                                        <form method="POST" action="{{ route('marketing.segments.duplicate', $segment) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs text-white/85">Duplicate</button>
                                        </form>
                                        @if(!$segment->is_system)
                                            <form method="POST" action="{{ route('marketing.segments.archive', $segment) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex rounded-full border border-amber-300/30 bg-amber-500/15 px-3 py-1 text-xs text-amber-100">Archive</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-white/55">No segments found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="pt-3">{{ $segments->links() }}</div>
        </section>
    </div>
</x-layouts::app>
