<section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div>
      <div class="text-lg font-semibold text-zinc-950">Wicks</div>
      <div class="text-sm text-zinc-600">Allowed wick types used in orders.</div>
    </div>
    <div class="flex items-center gap-2">
      <button wire:click="openCreate" class="rounded-full border border-emerald-400/40 bg-emerald-100 px-4 py-2 text-xs font-semibold text-zinc-950">
        Add new wick
      </button>
    </div>
  </div>

  @if($showCreate)
    <div class="mt-4 grid gap-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 md:grid-cols-4">
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.name" label="Name" />
        @error('create.name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" wire:model.defer="create.is_active" class="rounded border-zinc-300 bg-zinc-100" />
        <span class="text-sm text-zinc-700">Active</span>
      </div>
      <div class="md:col-span-4 flex items-center gap-2">
        <button wire:click="create" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-zinc-950">
          Save
        </button>
        <button wire:click="openCreate" class="rounded-full border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-600">
          Cancel
        </button>
      </div>
    </div>
  @endif

  <div class="mt-4 flex flex-wrap items-center gap-2">
    <flux:input wire:model.live="search" placeholder="Search wicks..." />
    <div class="flex items-center gap-2 rounded-full border border-zinc-200 bg-zinc-50 px-3 py-2">
      <span class="text-[11px] uppercase tracking-wide text-zinc-500">Rows</span>
      <select wire:model.live="perPage" class="bg-transparent text-xs text-zinc-700 focus:outline-none">
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  </div>

  <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200">
    <table class="min-w-full text-sm">
      <thead class="bg-zinc-50 text-zinc-600">
        <tr>
          <th class="px-4 py-3 text-left cursor-pointer" wire:click="setSort('name')">Name</th>
          <th class="px-4 py-3 text-left">Active</th>
          <th class="px-4 py-3 text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-zinc-200">
        @foreach($wicks as $wick)
          <tr class="hover:bg-zinc-50">
            <td class="px-4 py-3 text-zinc-950">{{ $wick->name }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex rounded-full px-2 py-1 text-xs {{ $wick->is_active ? 'bg-emerald-100 text-emerald-900' : 'bg-zinc-100 text-zinc-500' }}">
                {{ $wick->is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-4 py-3 text-right space-x-2">
              <button type="button" wire:click="openEdit({{ $wick->id }})" class="rounded-full border border-emerald-400/30 bg-emerald-100 px-3 py-1 text-[11px] text-emerald-900">Edit</button>
              <button type="button" wire:click="openDelete({{ $wick->id }})" class="rounded-full border border-red-400/30 bg-red-500/10 px-3 py-1 text-[11px] text-red-100">Delete</button>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="mt-4">{{ $wicks->links() }}</div>

</section>

  @if($showEdit)
    <div class="fixed inset-0 z-[9999] flex items-center justify-center fb-overlay-soft p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
      <div class="w-full max-w-xl rounded-2xl border border-zinc-200 bg-white p-6">
        <div class="text-lg font-semibold text-zinc-950">Edit Wick</div>
        <div class="mt-4 grid gap-3 md:grid-cols-2">
          <flux:input wire:model.defer="edit.name" label="Name" />
          <div class="flex items-center gap-2">
            <input type="checkbox" wire:model.defer="edit.is_active" class="rounded border-zinc-300 bg-zinc-100" />
            <span class="text-sm text-zinc-700">Active</span>
          </div>
        </div>
        <div class="mt-4 flex items-center gap-2">
          <button type="button" wire:click="save" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-zinc-950">Save</button>
          <button type="button" wire:click="$set('showEdit', false)" class="rounded-full border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-600">Cancel</button>
        </div>
      </div>
    </div>
  @endif

  @if($showDelete)
    <div class="fixed inset-0 z-[9999] flex items-center justify-center fb-overlay-soft p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
      <div class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6">
        <div class="text-lg font-semibold text-zinc-950">Delete Wick</div>
        <div class="mt-2 text-sm text-zinc-600">Are you sure? This cannot be undone.</div>
        <div class="mt-4 flex items-center gap-2">
          <button type="button" wire:click="destroy" class="rounded-full border border-red-400/40 bg-red-500/30 px-4 py-2 text-xs font-semibold text-zinc-950">Delete</button>
          <button type="button" wire:click="$set('showDelete', false)" class="rounded-full border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-600">Cancel</button>
        </div>
      </div>
    </div>
  @endif
