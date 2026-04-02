<x-layouts::app :title="'Marketing Group Detail'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Group Detail"
            description="Manage members, imports, and internal send controls for this manual marketing group."
            hint-title="How group membership works"
            hint-text="Groups are flat, manual lists. Profiles can belong to multiple groups, and group membership can be layered with segments during campaign prep."
        />

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-semibold text-zinc-950">{{ $group->name }}</h2>
                    <div class="mt-1 text-sm text-zinc-600">{{ $group->description ?: 'No description' }}</div>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-zinc-600">
                        <span class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-2.5 py-1">
                            Type: {{ $group->is_internal ? 'Internal' : 'Standard' }}
                        </span>
                        <span class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-2.5 py-1">
                            Members: {{ $members->total() }}
                        </span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if($group->is_internal)
                        <a href="{{ route('marketing.groups.send', $group) }}" wire:navigate class="inline-flex rounded-full border border-amber-300/35 bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-900">
                            Send To Group
                        </a>
                    @endif
                    <a href="{{ route('marketing.groups.edit', $group) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-800">Edit</a>
                    <a href="{{ route('marketing.groups') }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700">Back</a>
                </div>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            <article class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
                <h3 class="text-sm font-semibold text-zinc-950">Add Members</h3>
                <form method="GET" action="{{ route('marketing.groups.show', $group) }}" class="grid gap-2 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <input type="text" name="candidate_search" value="{{ $candidateSearch }}" placeholder="Search profiles not yet in this group"
                               class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                    </div>
                    <button type="submit" class="rounded-xl border border-zinc-300 bg-zinc-50 px-3 py-2 text-sm font-semibold text-zinc-700">Search</button>
                </form>

                <div class="space-y-2">
                    @forelse($candidates as $candidate)
                        <form method="POST" action="{{ route('marketing.groups.members.add', $group) }}" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                            @csrf
                            <input type="hidden" name="marketing_profile_id" value="{{ $candidate->id }}">
                            <div class="text-sm text-zinc-800">
                                {{ trim((string) ($candidate->first_name . ' ' . $candidate->last_name)) ?: ('Profile #' . $candidate->id) }}
                                <div class="text-xs text-zinc-500">{{ $candidate->email ?: $candidate->phone ?: 'No contact' }}</div>
                            </div>
                            <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-900">
                                Add
                            </button>
                        </form>
                    @empty
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-500">No candidate profiles found.</div>
                    @endforelse
                </div>
            </article>

            <article class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
                <h3 class="text-sm font-semibold text-zinc-950">CSV Import</h3>
                <x-admin.help-hint tone="neutral" title="Expected columns">
                    Upload CSV files with columns: <code>email</code>, <code>phone</code>, <code>first_name</code>, <code>last_name</code>. Email/phone are normalized for matching.
                </x-admin.help-hint>
                <form method="POST" action="{{ route('marketing.groups.import-csv', $group) }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <input type="file" name="csv_file" accept=".csv,text/csv" required
                           class="block w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1 file:text-xs file:text-zinc-950">
                    <label class="inline-flex items-center gap-2 text-sm text-zinc-700">
                        <input type="checkbox" name="dry_run" value="1" class="rounded border-zinc-300 bg-zinc-50">
                        Dry run (no membership changes)
                    </label>
                    <button type="submit" class="inline-flex rounded-full border border-sky-300/35 bg-sky-100 px-4 py-2 text-sm font-semibold text-sky-900">Run Import</button>
                </form>

                <div class="space-y-2">
                    <h4 class="text-xs uppercase tracking-[0.2em] text-zinc-500">Recent Import Runs</h4>
                    @forelse($recentRuns as $run)
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-700">
                            <div>Run #{{ $run->id }} · {{ $run->status }} · {{ $run->file_name ?: 'n/a' }}</div>
                            <div class="mt-1 text-zinc-500">
                                Started: {{ optional($run->started_at)->format('Y-m-d H:i') ?: '—' }}
                                · Finished: {{ optional($run->finished_at)->format('Y-m-d H:i') ?: '—' }}
                            </div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-500">No import runs yet.</div>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-zinc-950">Group Members</h3>
            <form method="GET" action="{{ route('marketing.groups.show', $group) }}" class="grid gap-2 sm:grid-cols-4">
                <div class="sm:col-span-3">
                    <input type="text" name="member_search" value="{{ $memberSearch }}" placeholder="Search current members"
                           class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                </div>
                <button type="submit" class="rounded-xl border border-zinc-300 bg-zinc-50 px-3 py-2 text-sm font-semibold text-zinc-700">Filter</button>
            </form>

            <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left">Profile</th>
                            <th class="px-4 py-3 text-left">Email</th>
                            <th class="px-4 py-3 text-left">Phone</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($members as $member)
                            <tr>
                                <td class="px-4 py-3 text-zinc-800">
                                    {{ trim((string) ($member->first_name . ' ' . $member->last_name)) ?: ('Profile #' . $member->id) }}
                                </td>
                                <td class="px-4 py-3 text-zinc-700">{{ $member->email ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-700">{{ $member->phone ?: '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex gap-2">
                                        <a href="{{ route('marketing.customers.show', $member) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-700">Open</a>
                                        <form method="POST" action="{{ route('marketing.groups.members.remove', [$group, $member]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex rounded-full border border-rose-300/35 bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-900">
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-zinc-500">No members currently in this group.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>
                {{ $members->links() }}
            </div>
        </section>
    </div>
</x-layouts::app>

