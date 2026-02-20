<section class="rounded-3xl border border-emerald-200/10 bg-[#0f1412]/70 p-5">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div>
      <div class="text-lg font-semibold text-white">Oil Blend Recipes</div>
      <div class="text-sm text-emerald-50/70">Define global blend recipes and component weights.</div>
    </div>
    <div class="flex items-center gap-2">
      <button wire:click="openCreate" class="rounded-full border border-emerald-400/40 bg-emerald-500/20 px-4 py-2 text-xs font-semibold text-white">
        Add blend
      </button>
    </div>
  </div>

  @if($showCreate)
    <div class="mt-4 grid gap-3 rounded-2xl border border-white/10 bg-white/5 p-4">
      <div>
        <flux:input wire:model.defer="create.name" label="Blend name" />
        @error('create.name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>

      <div class="space-y-2">
        <div class="text-xs text-white/60 uppercase tracking-[0.2em]">Components</div>
        @foreach($create['components'] ?? [] as $index => $component)
          <div class="grid gap-2 md:grid-cols-6" wire:key="create-component-{{ $index }}">
            <div class="md:col-span-4">
              <select wire:model.defer="create.components.{{ $index }}.base_oil_id" class="w-full h-10 rounded-xl border border-white/10 bg-white/5 px-3 text-white/90">
                <option value="">Select base oil</option>
                @foreach($baseOils as $oil)
                  <option value="{{ $oil->id }}">{{ $oil->name }}</option>
                @endforeach
              </select>
              @error("create.components.$index.base_oil_id") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            </div>
            <div class="md:col-span-1">
              <flux:input type="number" min="1" wire:model.defer="create.components.{{ $index }}.ratio_weight" label="Weight" />
              @error("create.components.$index.ratio_weight") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            </div>
            <div class="md:col-span-1 flex items-center">
              <button type="button" wire:click="removeComponent('create', {{ $index }})" class="rounded-full border border-red-400/30 bg-red-500/10 px-3 py-1 text-[11px] text-red-100">Remove</button>
            </div>
          </div>
        @endforeach
        <button type="button" wire:click="addComponent('create')" class="rounded-full border border-emerald-400/30 bg-emerald-500/10 px-3 py-1 text-[11px] text-emerald-100">Add component</button>
      </div>

      <div class="flex items-center gap-2">
        <button wire:click="create" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-white">Save</button>
        <button wire:click="openCreate" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/70">Cancel</button>
      </div>
    </div>
  @endif

  <div class="mt-4 flex flex-wrap items-center gap-2">
    <flux:input wire:model.live="search" placeholder="Search blends..." />
    <div class="flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-2">
      <span class="text-[11px] uppercase tracking-wide text-white/50">Rows</span>
      <select wire:model.live="perPage" class="bg-transparent text-xs text-white/80 focus:outline-none">
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  </div>

  <div class="mt-4 space-y-3">
    @foreach($blends as $blend)
      <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-white font-semibold">{{ $blend->name }}</div>
            <div class="text-xs text-white/60">{{ $blend->components->count() }} components</div>
          </div>
          <div class="flex items-center gap-2">
            <button type="button" wire:click="openEdit({{ $blend->id }})" class="rounded-full border border-emerald-400/30 bg-emerald-500/10 px-3 py-1 text-[11px] text-emerald-100">Edit</button>
            <button type="button" wire:click="openDelete({{ $blend->id }})" class="rounded-full border border-red-400/30 bg-red-500/10 px-3 py-1 text-[11px] text-red-100">Delete</button>
          </div>
        </div>
        <div class="mt-3 grid gap-2 md:grid-cols-2">
          @foreach($blend->components as $component)
            <div class="flex items-center justify-between rounded-xl border border-emerald-200/10 bg-black/30 px-3 py-2 text-xs text-white/80">
              <div>{{ $component->baseOil?->name ?? 'Unknown oil' }}</div>
              <div class="text-emerald-100/70">Weight {{ $component->ratio_weight }}</div>
            </div>
          @endforeach
          @if($blend->components->isEmpty())
            <div class="text-xs text-white/60">No components yet.</div>
          @endif
        </div>
      </div>
    @endforeach
  </div>

  <div class="mt-4">{{ $blends->links() }}</div>
</section>

@if($showEdit)
  <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
    <div class="w-full max-w-3xl rounded-2xl border border-white/10 bg-zinc-950 p-6">
      <div class="text-lg font-semibold text-white">Edit Blend</div>
      <div class="mt-4 grid gap-3">
        <flux:input wire:model.defer="edit.name" label="Blend name" />
        @error('edit.name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror

        <div class="space-y-2">
          <div class="text-xs text-white/60 uppercase tracking-[0.2em]">Components</div>
          @foreach($edit['components'] ?? [] as $index => $component)
            <div class="grid gap-2 md:grid-cols-6" wire:key="edit-component-{{ $index }}">
              <div class="md:col-span-4">
                <select wire:model.defer="edit.components.{{ $index }}.base_oil_id" class="w-full h-10 rounded-xl border border-white/10 bg-white/5 px-3 text-white/90">
                  <option value="">Select base oil</option>
                  @foreach($baseOils as $oil)
                    <option value="{{ $oil->id }}">{{ $oil->name }}</option>
                  @endforeach
                </select>
                @error("edit.components.$index.base_oil_id") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
              </div>
              <div class="md:col-span-1">
                <flux:input type="number" min="1" wire:model.defer="edit.components.{{ $index }}.ratio_weight" label="Weight" />
                @error("edit.components.$index.ratio_weight") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
              </div>
              <div class="md:col-span-1 flex items-center">
                <button type="button" wire:click="removeComponent('edit', {{ $index }})" class="rounded-full border border-red-400/30 bg-red-500/10 px-3 py-1 text-[11px] text-red-100">Remove</button>
              </div>
            </div>
          @endforeach
          <button type="button" wire:click="addComponent('edit')" class="rounded-full border border-emerald-400/30 bg-emerald-500/10 px-3 py-1 text-[11px] text-emerald-100">Add component</button>
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
      <div class="text-lg font-semibold text-white">Delete Blend</div>
      <div class="mt-2 text-sm text-white/70">Are you sure? This cannot be undone.</div>
      <div class="mt-4 flex items-center gap-2">
        <button type="button" wire:click="destroy" class="rounded-full border border-red-400/40 bg-red-500/30 px-4 py-2 text-xs font-semibold text-white">Delete</button>
        <button type="button" wire:click="$set('showDelete', false)" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/70">Cancel</button>
      </div>
    </div>
  </div>
@endif
