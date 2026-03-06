<section class="rounded-3xl border border-emerald-200/10 bg-[#0f1412]/70 p-5">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div>
      <div class="text-lg font-semibold text-white">Wholesale Custom Scents</div>
      <div class="text-sm text-emerald-50/70">Account-specific scent names mapped to canonical scents.</div>
    </div>
    <div class="flex items-center gap-2">
      <button wire:click="openCreate" class="rounded-full border border-emerald-400/40 bg-emerald-500/20 px-4 py-2 text-xs font-semibold text-white">
        Add custom scent
      </button>
    </div>
  </div>

  @if($showCreate)
    <div class="mt-4 grid gap-3 rounded-2xl border border-white/10 bg-white/5 p-4 md:grid-cols-6">
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.account_name" label="Account name" />
        @error('create.account_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.custom_scent_name" label="Custom scent name" />
        @error('create.custom_scent_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div class="md:col-span-2">
        <label class="text-xs text-white/70">Canonical scent (optional)</label>
        <div class="mt-1">
          <livewire:components.scent-combobox
            :emit-key="'wholesale-create'"
            :selected-id="(int)($create['canonical_scent_id'] ?? 0)"
            :allow-wholesale-custom="true"
            :include-inactive="true"
            wire:key="wholesale-create-combo"
          />
        </div>
        @error('create.canonical_scent_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div class="md:col-span-4">
        <flux:input wire:model.defer="create.notes" label="Notes" />
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" wire:model.defer="create.active" class="rounded border-white/20 bg-white/10" />
        <span class="text-sm text-white/80">Active</span>
      </div>
      <div class="md:col-span-6 flex items-center gap-2">
        <button wire:click="create" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-white">
          Save
        </button>
        <button wire:click="openCreate" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/70">
          Cancel
        </button>
      </div>
    </div>
  @endif

  <div class="mt-4 flex flex-wrap items-center gap-2">
    <div class="mr-auto text-xs text-white/50">
      {{ $records->total() }} {{ \Illuminate\Support\Str::plural('custom scent', $records->total()) }}
    </div>
    <flux:input wire:model.live="search" placeholder="Search account or scent..." />
    <div class="flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-2">
      <span class="text-[11px] uppercase tracking-wide text-white/50">Filter</span>
      <select wire:model.live="filter" class="bg-transparent text-xs text-white/80 focus:outline-none">
        <option value="all">All</option>
        <option value="mapped">Mapped</option>
        <option value="unmapped">Unmapped</option>
      </select>
    </div>
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
          <th class="px-4 py-3 text-left">Account</th>
          <th class="px-4 py-3 text-left">Custom Scent</th>
          <th class="px-4 py-3 text-left">Canonical Scent</th>
          <th class="px-4 py-3 text-left">Status</th>
          <th class="px-4 py-3 text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-white/5">
        @forelse($records as $record)
          <tr class="hover:bg-white/5">
            <td class="px-4 py-3 text-white">{{ $record->account_name }}</td>
            <td class="px-4 py-3 text-white">{{ $record->custom_scent_name }}</td>
            <td class="px-4 py-3 text-white/80">
              {{ $record->canonicalScent?->name ?? '—' }}
            </td>
            <td class="px-4 py-3">
              @if($record->canonical_scent_id)
                <span class="inline-flex rounded-full px-2 py-1 text-xs bg-emerald-500/20 text-emerald-100">Mapped</span>
              @else
                <span class="inline-flex rounded-full px-2 py-1 text-xs bg-amber-500/20 text-amber-100">Unmapped</span>
              @endif
            </td>
            <td class="px-4 py-3 text-right space-x-2">
              <button type="button" wire:click="openEdit({{ $record->id }})" class="rounded-full border border-emerald-400/30 bg-emerald-500/10 px-3 py-1 text-[11px] text-emerald-100">Edit</button>
              <button type="button" wire:click="openDelete({{ $record->id }})" class="rounded-full border border-red-400/30 bg-red-500/10 px-3 py-1 text-[11px] text-red-100">Delete</button>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="5" class="px-4 py-8 text-center text-sm text-white/55">
              @if($search !== '' || $filter !== 'all')
                No wholesale custom scents match the current search/filter.
              @else
                No wholesale custom scents yet. Add one manually or import the wholesale custom scent data first.
              @endif
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="mt-4">{{ $records->links() }}</div>
</section>

@if($showEdit)
  <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
    <div class="w-full max-w-2xl rounded-2xl border border-white/10 bg-zinc-950 p-6">
      <div class="text-lg font-semibold text-white">Edit Custom Scent</div>
      <div class="mt-4 grid gap-3 md:grid-cols-2">
        <flux:input wire:model.defer="edit.account_name" label="Account name" />
        <flux:input wire:model.defer="edit.custom_scent_name" label="Custom scent name" />
        <div class="md:col-span-2">
          <label class="text-xs text-white/70">Canonical scent (optional)</label>
          <div class="mt-1">
            <livewire:components.scent-combobox
              :emit-key="'wholesale-edit'"
              :selected-id="(int)($edit['canonical_scent_id'] ?? 0)"
              :allow-wholesale-custom="true"
              :include-inactive="true"
              wire:key="wholesale-edit-combo-{{ $editingId }}"
            />
          </div>
        </div>
        <flux:input wire:model.defer="edit.notes" label="Notes" />
        <div class="flex items-center gap-2">
          <input type="checkbox" wire:model.defer="edit.active" class="rounded border-white/20 bg-white/10" />
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
      <div class="text-lg font-semibold text-white">Delete Custom Scent</div>
      <div class="mt-2 text-sm text-white/70">Are you sure? This cannot be undone.</div>
      <div class="mt-4 flex items-center gap-2">
        <button type="button" wire:click="destroy" class="rounded-full border border-red-400/40 bg-red-500/30 px-4 py-2 text-xs font-semibold text-white">Delete</button>
        <button type="button" wire:click="$set('showDelete', false)" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/70">Cancel</button>
      </div>
    </div>
  </div>
@endif
