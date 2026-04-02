<section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div>
      <div class="text-lg font-semibold text-zinc-950">Oil Blend Recipes</div>
      <div class="text-sm text-zinc-600">Maintain reusable blend templates used inside scent recipes.</div>
    </div>
    <div class="flex items-center gap-2">
      <button wire:click="openCreate" class="rounded-full border border-emerald-400/40 bg-emerald-100 px-4 py-2 text-xs font-semibold text-zinc-950">
        Add blend
      </button>
    </div>
  </div>

  @if($showCreate)
    <div class="mt-4 grid gap-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
      <div>
        <flux:input wire:model.defer="create.name" label="Blend name" />
        @error('create.name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>

      <div class="space-y-2">
        <div class="text-xs text-zinc-500 uppercase tracking-[0.2em]">Components</div>
        @foreach($create['components'] ?? [] as $index => $component)
          <div class="grid gap-2 md:grid-cols-6" wire:key="create-component-{{ $index }}">
            <div class="md:col-span-4">
              <select wire:model.defer="create.components.{{ $index }}.base_oil_id" class="w-full h-10 rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-zinc-900">
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
        <button type="button" wire:click="addComponent('create')" class="rounded-full border border-emerald-400/30 bg-emerald-100 px-3 py-1 text-[11px] text-emerald-900">Add component</button>
      </div>

      <div class="flex items-center gap-2">
        <button wire:click="create" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-zinc-950">Save</button>
        <button wire:click="openCreate" class="rounded-full border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-600">Cancel</button>
      </div>
    </div>
  @endif

  <div class="mt-4 flex flex-wrap items-center gap-2">
    <flux:input wire:model.live="search" placeholder="Search blends..." />
    <div class="flex items-center gap-2 rounded-full border border-zinc-200 bg-zinc-50 px-3 py-2">
      <span class="text-[11px] uppercase tracking-wide text-zinc-500">Rows</span>
      <select wire:model.live="perPage" class="bg-transparent text-xs text-zinc-700 focus:outline-none">
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  </div>

  <div class="mt-4 space-y-3">
    @foreach($blends as $blend)
      <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-zinc-950 font-semibold">{{ $blend->name }}</div>
            <div class="text-xs text-zinc-500">{{ $blend->components->count() }} components</div>
          </div>
          <div class="flex items-center gap-2">
            <button type="button" wire:click="openEdit({{ $blend->id }})" class="rounded-full border border-emerald-400/30 bg-emerald-100 px-3 py-1 text-[11px] text-emerald-900">Edit</button>
            <button type="button" wire:click="openDelete({{ $blend->id }})" class="rounded-full border border-red-400/30 bg-red-500/10 px-3 py-1 text-[11px] text-red-100">Delete</button>
          </div>
        </div>
        <div class="mt-3 grid gap-2 md:grid-cols-2">
          @foreach($blend->components as $component)
            <div class="flex items-center justify-between rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-700">
              <div>{{ $component->baseOil?->name ?? 'Unknown oil' }}</div>
              <div class="text-emerald-800">Weight {{ $component->ratio_weight }}</div>
            </div>
          @endforeach
          @if($blend->components->isEmpty())
            <div class="text-xs text-zinc-500">No components yet.</div>
          @endif
        </div>
      </div>
    @endforeach
  </div>

  <div class="mt-4">{{ $blends->links() }}</div>
</section>

@if($showEdit)
  <div class="fixed inset-0 z-[9999] flex items-center justify-center fb-overlay-soft p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
    <div class="w-full max-w-3xl rounded-2xl border border-zinc-200 bg-white p-6">
      <div class="text-lg font-semibold text-zinc-950">Edit Blend</div>
      <div class="mt-4 grid gap-3">
        <flux:input wire:model.defer="edit.name" label="Blend name" />
        @error('edit.name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror

        <div class="space-y-2">
          <div class="text-xs text-zinc-500 uppercase tracking-[0.2em]">Components</div>
          @foreach($edit['components'] ?? [] as $index => $component)
            <div class="grid gap-2 md:grid-cols-6" wire:key="edit-component-{{ $index }}">
              <div class="md:col-span-4">
                <select wire:model.defer="edit.components.{{ $index }}.base_oil_id" class="w-full h-10 rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-zinc-900">
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
          <button type="button" wire:click="addComponent('edit')" class="rounded-full border border-emerald-400/30 bg-emerald-100 px-3 py-1 text-[11px] text-emerald-900">Add component</button>
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
      <div class="text-lg font-semibold text-zinc-950">Delete Blend</div>
      <div class="mt-2 text-sm text-zinc-600">Are you sure? This cannot be undone.</div>
      <div class="mt-4 flex items-center gap-2">
        <button type="button" wire:click="destroy" class="rounded-full border border-red-400/40 bg-red-500/30 px-4 py-2 text-xs font-semibold text-zinc-950">Delete</button>
        <button type="button" wire:click="$set('showDelete', false)" class="rounded-full border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-600">Cancel</button>
      </div>
    </div>
  </div>
@endif
