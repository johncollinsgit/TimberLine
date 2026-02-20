<div class="space-y-6">
  <div class="flex items-center justify-between">
    <div>
      <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Catalog Options</div>
      <div class="text-sm text-zinc-600 dark:text-zinc-300">Manage locked Scent + Size dropdown lists.</div>
    </div>

    <div class="flex gap-2">
      <button type="button" wire:click="$set('tab','scents')"
        class="px-3 py-1.5 rounded-lg text-sm border
        {{ $tab === 'scents' ? 'border-emerald-400/40 bg-emerald-500/15 text-emerald-700 dark:text-emerald-200' : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-300' }}">
        Scents
      </button>
      <button type="button" wire:click="$set('tab','sizes')"
        class="px-3 py-1.5 rounded-lg text-sm border
        {{ $tab === 'sizes' ? 'border-emerald-400/40 bg-emerald-500/15 text-emerald-700 dark:text-emerald-200' : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-300' }}">
        Sizes
      </button>
    </div>
  </div>

  {{-- SCENTS --}}
  @if($tab === 'scents')
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950/40 p-4 space-y-4">
      <div class="flex items-end gap-3">
        <div class="flex-1">
          <label class="block text-xs text-zinc-600 dark:text-zinc-300 mb-1">New scent</label>
          <input type="text" wire:model.defer="newScentName"
            class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white/70 dark:bg-black/30 px-3 py-2 text-sm"
            placeholder="Rosemary" />
          @error('newScentName') <div class="mt-1 text-xs text-red-500">{{ $message }}</div> @enderror
        </div>
        <button type="button" wire:click="createScent"
          class="px-3 py-2 rounded-lg text-sm border border-emerald-400/40 bg-emerald-500/15 hover:bg-emerald-500/20">
          + Add
        </button>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="text-xs text-zinc-500 dark:text-zinc-400">
            <tr class="[&>th]:text-left [&>th]:py-2">
              <th class="pr-4">Name</th>
              <th class="pr-4">Active</th>
              <th class="text-right">Save</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach($editScent as $id => $row)
              <tr>
                <td class="py-2 pr-4">
                  <input type="text" wire:model.defer="editScent.{{ $id }}.name"
                    class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white/70 dark:bg-black/30 px-3 py-2 text-sm" />
                </td>
                <td class="py-2 pr-4">
                  <label class="inline-flex items-center gap-2">
                    <input type="checkbox" wire:model.defer="editScent.{{ $id }}.is_active" />
                    <span class="text-xs text-zinc-600 dark:text-zinc-300">Active</span>
                  </label>
                </td>
                <td class="py-2 text-right">
                  <button type="button" wire:click="saveScent({{ $id }})"
                    class="px-3 py-2 rounded-lg text-xs border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-white/5">
                    Save
                  </button>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endif

  {{-- SIZES --}}
  @if($tab === 'sizes')
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950/40 p-4 space-y-4">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
        <div>
          <label class="block text-xs text-zinc-600 dark:text-zinc-300 mb-1">New size code</label>
          <input type="text" wire:model.defer="newSizeCode"
            class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white/70 dark:bg-black/30 px-3 py-2 text-sm"
            placeholder="8oz" />
          @error('newSizeCode') <div class="mt-1 text-xs text-red-500">{{ $message }}</div> @enderror
        </div>

        <div>
          <label class="block text-xs text-zinc-600 dark:text-zinc-300 mb-1">Label (optional)</label>
          <input type="text" wire:model.defer="newSizeLabel"
            class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white/70 dark:bg-black/30 px-3 py-2 text-sm"
            placeholder="8 oz Jar" />
        </div>

        <div class="flex justify-end">
          <button type="button" wire:click="createSize"
            class="px-3 py-2 rounded-lg text-sm border border-emerald-400/40 bg-emerald-500/15 hover:bg-emerald-500/20">
            + Add
          </button>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="text-xs text-zinc-500 dark:text-zinc-400">
            <tr class="[&>th]:text-left [&>th]:py-2">
              <th class="pr-4">Code</th>
              <th class="pr-4">Label</th>
              <th class="pr-4">Active</th>
              <th class="text-right">Save</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach($editSize as $id => $row)
              <tr>
                <td class="py-2 pr-4">
                  <input type="text" wire:model.defer="editSize.{{ $id }}.code"
                    class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white/70 dark:bg-black/30 px-3 py-2 text-sm" />
                </td>
                <td class="py-2 pr-4">
                  <input type="text" wire:model.defer="editSize.{{ $id }}.label"
                    class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white/70 dark:bg-black/30 px-3 py-2 text-sm" />
                </td>
                <td class="py-2 pr-4">
                  <label class="inline-flex items-center gap-2">
                    <input type="checkbox" wire:model.defer="editSize.{{ $id }}.is_active" />
                    <span class="text-xs text-zinc-600 dark:text-zinc-300">Active</span>
                  </label>
                </td>
                <td class="py-2 text-right">
                  <button type="button" wire:click="saveSize({{ $id }})"
                    class="px-3 py-2 rounded-lg text-xs border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-white/5">
                    Save
                  </button>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <div class="text-xs text-zinc-500 dark:text-zinc-400">
        Tip: set Label if you want a friendlier dropdown than Code.
      </div>
    </div>
  @endif
</div>
