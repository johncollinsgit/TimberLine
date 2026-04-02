<x-layouts::app :title="$mode === 'create' ? 'Create Group' : 'Edit Group'">
    <div class="mx-auto w-full max-w-[1200px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            :title="$mode === 'create' ? 'Create Group' : 'Edit Group'"
            description="Create and maintain manual marketing lists with optional internal-send bypass capability."
            hint-title="Group governance"
            hint-text="Internal groups unlock direct send actions for admin and marketing_manager users. Consent gating still applies at send time."
        />

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6">
            <form method="POST" action="{{ $mode === 'create' ? route('marketing.groups.store') : route('marketing.groups.update', $group) }}" class="space-y-5">
                @csrf
                @if($mode === 'edit')
                    @method('PATCH')
                @endif

                @if($mode === 'create' && $initialProfileId > 0)
                    <input type="hidden" name="initial_profile_id" value="{{ $initialProfileId }}">
                @endif

                <div>
                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Name</label>
                    <input type="text" name="name" required value="{{ old('name', $group->name) }}"
                           class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                </div>

                <div>
                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Description</label>
                    <textarea name="description" rows="3"
                              class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">{{ old('description', $group->description) }}</textarea>
                </div>

                <label class="inline-flex items-center gap-2 text-sm text-zinc-800">
                    <input type="checkbox" name="is_internal" value="1" @checked((bool) old('is_internal', $group->is_internal))
                           class="rounded border-zinc-300 bg-zinc-50">
                    Mark as internal group (allows direct SMS/email send bypass actions)
                </label>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-emerald-900">
                        {{ $mode === 'create' ? 'Create Group' : 'Save Group' }}
                    </button>
                    <a href="{{ $mode === 'create' ? route('marketing.groups') : route('marketing.groups.show', $group) }}" wire:navigate
                       class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-4 py-2 text-sm font-semibold text-zinc-800">
                        Cancel
                    </a>
                </div>
            </form>
        </section>
    </div>
</x-layouts::app>

