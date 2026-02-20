<section class="rounded-3xl border border-emerald-200/10 bg-[#0f1412]/70 p-5">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div>
      <div class="text-lg font-semibold text-white">Sizes</div>
      <div class="text-sm text-emerald-50/70">Canonical sizes and pricing.</div>
    </div>
    <div class="flex items-center gap-2">
      <button wire:click="openCreate" class="rounded-full border border-emerald-400/40 bg-emerald-500/20 px-4 py-2 text-xs font-semibold text-white">
        Add new size
      </button>
    </div>
  </div>

  @if($showCreate)
    <form wire:submit.prevent="create" class="mt-4 grid gap-3 rounded-2xl border border-white/10 bg-white/5 p-4 md:grid-cols-6">
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.code" label="Code" />
        @error('create.code') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.label" label="Label" />
      </div>
      <div>
        <flux:input wire:model.defer="create.wholesale_price" label="Wholesale" type="number" step="0.01" min="0" />
      </div>
      <div>
        <flux:input wire:model.defer="create.retail_price" label="Retail" type="number" step="0.01" min="0" />
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" wire:model.defer="create.is_active" class="rounded border-white/20 bg-white/10" />
        <span class="text-sm text-white/80">Active</span>
      </div>
      <div class="md:col-span-6 flex items-center gap-2">
        <button type="submit" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-white">
          Save
        </button>
        <button type="button" wire:click="openCreate" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/70">
          Cancel
        </button>
      </div>
    </form>
  @endif

  <div class="mt-4 flex flex-wrap items-center gap-2">
    <flux:input wire:model.live="search" placeholder="Search sizes..." />
    <div class="flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-2">
      <span class="text-[11px] uppercase tracking-wide text-white/50">Rows</span>
      <select wire:model.live="perPage" class="bg-transparent text-xs text-white/80 focus:outline-none">
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  </div>

  <div class="mt-4 overflow-hidden rounded-2xl border border-white/10">
    <table class="min-w-full text-sm">
      <thead class="bg-white/5 text-white/70">
        <tr>
          <th class="px-4 py-3 text-left cursor-pointer" wire:click="setSort('label')">Label</th>
          <th class="px-4 py-3 text-left">Code</th>
          <th class="px-4 py-3 text-left">Wholesale</th>
          <th class="px-4 py-3 text-left">Retail</th>
          <th class="px-4 py-3 text-left">Active</th>
          <th class="px-4 py-3 text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-white/5">
        @foreach($sizes as $size)
          <tr class="hover:bg-white/5">
            <td class="px-4 py-3 text-white">{{ $size->label ?: '—' }}</td>
            <td class="px-4 py-3 text-white/80">{{ $size->code }}</td>
            <td class="px-4 py-3 text-white/80">{{ $size->wholesale_price !== null ? '$'.number_format($size->wholesale_price, 2) : '—' }}</td>
            <td class="px-4 py-3 text-white/80">{{ $size->retail_price !== null ? '$'.number_format($size->retail_price, 2) : '—' }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex rounded-full px-2 py-1 text-xs {{ $size->is_active ? 'bg-emerald-500/20 text-emerald-100' : 'bg-white/10 text-white/60' }}">
                {{ $size->is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-4 py-3 text-right space-x-2">
              <button type="button" wire:click="openEdit({{ $size->id }})" class="rounded-full border border-emerald-400/30 bg-emerald-500/10 px-3 py-1 text-[11px] text-emerald-100">Edit</button>
              <button type="button" wire:click="openDelete({{ $size->id }})" class="rounded-full border border-red-400/30 bg-red-500/10 px-3 py-1 text-[11px] text-red-100">Delete</button>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="mt-4">{{ $sizes->links() }}</div>

</section>

  @if($showEdit)
    <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
      <div class="w-full max-w-2xl rounded-2xl border border-white/10 bg-zinc-950 p-6">
        <div class="text-lg font-semibold text-white">Edit Size</div>
        <div class="mt-4 grid gap-3 md:grid-cols-2">
          <flux:input wire:model.defer="edit.label" label="Label" />
          <flux:input wire:model.defer="edit.code" label="Code" />
          <flux:input wire:model.defer="edit.wholesale_price" label="Wholesale" type="number" step="0.01" min="0" />
          <flux:input wire:model.defer="edit.retail_price" label="Retail" type="number" step="0.01" min="0" />
          <div class="flex items-center gap-2">
            <input type="checkbox" wire:model.defer="edit.is_active" class="rounded border-white/20 bg-white/10" />
            <span class="text-sm text-white/80">Active</span>
          </div>
        </div>
        <div class="mt-4 flex items-center gap-2">
          <button type="button" wire:click="save" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-white">Save</button>
          <button type="button" wire:click="$set('showEdit', false)" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/70">Cancel</button>
        </div>
      </div>
    </div>
  @endif

  @if($showDelete)
    <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
      <div class="w-full max-w-md rounded-2xl border border-white/10 bg-zinc-950 p-6">
        <div class="text-lg font-semibold text-white">Delete Size</div>
        <div class="mt-2 text-sm text-white/70">Are you sure? This cannot be undone.</div>
        <div class="mt-4 flex items-center gap-2">
          <button type="button" wire:click="destroy" class="rounded-full border border-red-400/40 bg-red-500/30 px-4 py-2 text-xs font-semibold text-white">Delete</button>
          <button type="button" wire:click="$set('showDelete', false)" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/70">Cancel</button>
        </div>
      </div>
    </div>
  @endif
