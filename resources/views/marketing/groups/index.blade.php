<x-layouts::app :title="'Groups'">
    <div class="mx-auto w-full max-w-[1700px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Groups"
            description="Manual curated lists for targeted outreach and operational list management outside rule-based segments."
            hint-title="Groups vs segments"
            hint-text="Groups are explicit, hand-curated lists. Segments remain rule-based and dynamic. Campaign recipient preparation can combine both."
        />

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <form method="GET" action="{{ route('marketing.groups') }}" class="grid gap-3 md:grid-cols-6">
                    <div class="md:col-span-4">
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Search</label>
                        <input type="text" name="search" value="{{ $search }}" placeholder="Group name or description"
                               class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Internal</label>
                        <select name="internal" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                            <option value="" @selected($internal === '')>Any</option>
                            <option value="yes" @selected($internal === 'yes')>Internal only</option>
                            <option value="no" @selected($internal === 'no')>External only</option>
                        </select>
                    </div>
                    <div class="md:col-span-6">
                        <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-4 py-2 text-sm font-semibold text-zinc-800">
                            Apply Filters
                        </button>
                    </div>
                </form>

                <a href="{{ route('marketing.groups.create') }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-emerald-900">
                    Create Group
                </a>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-2 sm:p-3">
            <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left">Group</th>
                            <th class="px-4 py-3 text-left">Type</th>
                            <th class="px-4 py-3 text-left">Members</th>
                            <th class="px-4 py-3 text-left">Description</th>
                            <th class="px-4 py-3 text-left">Updated</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($groups as $group)
                            <tr>
                                <td class="px-4 py-3 text-zinc-950 font-semibold">{{ $group->name }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] {{ $group->is_internal ? 'bg-amber-100 text-amber-900' : 'bg-zinc-100 text-zinc-600' }}">
                                        {{ $group->is_internal ? 'Internal' : 'Standard' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-zinc-700">{{ (int) $group->members_count }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ \Illuminate\Support\Str::limit((string) $group->description, 110) ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-500">{{ optional($group->updated_at)->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex gap-2">
                                        <a href="{{ route('marketing.groups.show', $group) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-700">Open</a>
                                        <a href="{{ route('marketing.groups.edit', $group) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-600">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-zinc-500">No groups found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-2 pt-4">
                {{ $groups->links() }}
            </div>
        </section>
    </div>
</x-layouts::app>
