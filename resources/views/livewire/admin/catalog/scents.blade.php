<section class="rounded-3xl border border-emerald-200/10 bg-[#0f1412]/70 p-5">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div>
      <div class="text-lg font-semibold text-white">Scents</div>
      <div class="text-sm text-emerald-50/70">Canonical scent list used across Shipping + Pouring.</div>
    </div>
    <div class="flex items-center gap-2">
      <button wire:click="openCreate" class="rounded-full border border-emerald-400/40 bg-emerald-500/20 px-4 py-2 text-xs font-semibold text-white">
        {{ $showCreate ? 'Close form' : 'Add new scent' }}
      </button>
    </div>
  </div>

  @if($showCreate)
    <div class="mt-4 rounded-2xl border border-white/10 bg-white/5 p-4">
      @if($createErrorBanner)
        <div class="mb-4 rounded-xl border border-red-300/35 bg-red-950/30 px-3 py-2 text-xs text-red-100">
          {{ $createErrorBanner }}
        </div>
      @endif

      <div class="grid gap-3 md:grid-cols-6">
        <div class="md:col-span-2">
          <flux:input wire:model.defer="create.name" label="Name" />
          @error('create.name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div class="md:col-span-2">
          <flux:input wire:model.defer="create.display_name" label="Display Name" />
          @error('create.display_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div>
          <flux:input wire:model.defer="create.abbreviation" label="Abbrev" />
          @error('create.abbreviation') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div>
          <label class="text-xs text-white/70">Oil Ref</label>
          <input
            wire:model.defer="create.oil_reference_name"
            list="catalog-create-oil-ref-list"
            class="mt-1 h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-white/90"
            placeholder="Search/select primary oil or type custom"
          />
          <datalist id="catalog-create-oil-ref-list">
            @foreach($baseOils as $oil)
              <option value="{{ $oil->name }}">{{ $oil->name }}</option>
            @endforeach
          </datalist>
          @error('create.oil_reference_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div class="md:col-span-3">
          <label class="text-xs text-white/70">Map to canonical scent (optional)</label>
          <div class="mt-1">
            <livewire:components.scent-combobox
              wire:model.live="create.canonical_scent_id"
              :emit-key="'catalog-scent-create-canonical'"
              :allow-wholesale-custom="true"
              :include-inactive="true"
              wire:key="catalog-scent-create-canonical"
            />
          </div>
          @if($createCanonicalSuggestionId && $createCanonicalSuggestionLabel)
            <button type="button" wire:click="applyCanonicalSuggestion('create')" class="mt-2 rounded-full border border-emerald-300/30 bg-emerald-500/10 px-3 py-1 text-[11px] text-emerald-50">
              Suggested: map to {{ $createCanonicalSuggestionLabel }}
            </button>
          @endif
          @error('create.canonical_scent_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div class="md:col-span-3">
          <label class="text-xs text-white/70">Wholesale custom source (optional)</label>
          <div class="mt-1 flex items-center gap-2">
            <select wire:model.defer="create.source_wholesale_custom_scent_id" class="h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-white/90">
              <option value="">None</option>
              @foreach($wholesaleSources as $source)
                @php
                  $slotCount = collect([$source->oil_1, $source->oil_2, $source->oil_3])->filter(fn ($slot) => !blank($slot))->count();
                  $topLevelCount = is_array(data_get($source, 'top_level_recipe_json.components'))
                    ? count(data_get($source, 'top_level_recipe_json.components'))
                    : 0;
                  $isBlendLike = $slotCount > 1 || $topLevelCount > 1;
                @endphp
                <option value="{{ $source->id }}">
                  {{ $source->custom_scent_name }} · {{ $source->account_name }} · {{ $isBlendLike ? 'Wholesale custom blend' : 'Wholesale custom scent' }}
                </option>
              @endforeach
            </select>
            <button type="button" wire:click="applySelectedWholesaleSource('create')" class="shrink-0 rounded-full border border-emerald-300/35 bg-emerald-500/20 px-3 py-2 text-[11px] font-semibold text-white">
              Apply
            </button>
          </div>
          @error('create.source_wholesale_custom_scent_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div>
          <label class="text-xs text-white/70">Recipe-backed scent?</label>
          <div class="mt-2 flex h-10 items-center gap-2">
            <input type="checkbox" wire:model.defer="create.is_blend" class="rounded border-white/20 bg-white/10" />
            <span class="text-sm text-white/80">Blend / multi-source recipe</span>
          </div>
          @error('create.is_blend') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div class="flex items-center gap-2">
          <input type="checkbox" wire:model.defer="create.is_active" class="rounded border-white/20 bg-white/10" />
          <span class="text-sm text-white/80">Active</span>
        </div>
      </div>

      @if($create['is_blend'] ?? false)
        <div class="mt-4 rounded-2xl border border-emerald-200/20 bg-black/20 p-4">
          <div class="text-xs uppercase tracking-[0.24em] text-emerald-100/70">Blend Mapping</div>

          <div class="mt-3 grid gap-3 md:grid-cols-6">
            <div class="md:col-span-3">
              <label class="text-xs text-white/70">Existing blend</label>
              <select wire:model.defer="create.oil_blend_id" class="mt-1 h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-white/90">
                <option value="">None</option>
                @foreach($blends as $blend)
                  <option value="{{ $blend->id }}">{{ $blend->name }}</option>
                @endforeach
              </select>
              @error('create.oil_blend_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            </div>

            <div>
              <flux:input wire:model.defer="create.blend_oil_count" label="Oil count" type="number" min="1" />
              @error('create.blend_oil_count') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            </div>

            <div class="md:col-span-2">
              <label class="text-xs text-white/70">Create new blend from sources</label>
              <div class="mt-2 flex h-10 items-center gap-2">
                <input type="checkbox" wire:model.defer="create.create_inline_blend" class="rounded border-white/20 bg-white/10" />
                <span class="text-sm text-white/80">Create inline</span>
              </div>
              @error('create.create_inline_blend') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            </div>

            @if($create['create_inline_blend'] ?? false)
              <div class="md:col-span-3">
                <flux:input wire:model.defer="create.inline_blend_name" label="New blend name" />
                @error('create.inline_blend_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
              </div>
            @endif
          </div>

          <div class="mt-4 space-y-2">
            <div class="text-xs uppercase tracking-[0.2em] text-white/60">Recipe Sources</div>
            @foreach($create['recipe_components'] ?? [] as $index => $row)
              @php $rowType = $row['type'] ?? 'base_oil'; @endphp
              <div class="grid gap-2 md:grid-cols-8" wire:key="create-recipe-row-{{ $index }}">
                <div class="md:col-span-2">
                  <label class="mb-1 block text-[11px] text-white/60">Source type</label>
                  <select wire:model.defer="create.recipe_components.{{ $index }}.type" class="h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-white/90">
                    <option value="base_oil">Oil</option>
                    <option value="blend">Blend</option>
                  </select>
                </div>
                <div class="md:col-span-4">
                  <label class="mb-1 block text-[11px] text-white/60">Source</label>
                  <select wire:model.defer="create.recipe_components.{{ $index }}.id" class="h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-white/90">
                    <option value="">Select {{ $rowType === 'blend' ? 'blend' : 'oil' }}</option>
                    @if($rowType === 'blend')
                      @foreach($blends as $blend)
                        <option value="{{ $blend->id }}">{{ $blend->name }}</option>
                      @endforeach
                    @else
                      @foreach($baseOils as $oil)
                        <option value="{{ $oil->id }}">{{ $oil->name }}</option>
                      @endforeach
                    @endif
                  </select>
                  @error("create.recipe_components.$index.id") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
                </div>
                <div>
                  <flux:input wire:model.defer="create.recipe_components.{{ $index }}.ratio_weight" label="Weight" type="number" min="1" />
                  @error("create.recipe_components.$index.ratio_weight") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
                </div>
                <div class="flex items-end">
                  <button type="button" wire:click="removeRecipeComponent('create', {{ $index }})" class="h-10 w-full rounded-xl border border-red-400/30 bg-red-500/10 px-3 text-[11px] font-semibold text-red-100">
                    Remove
                  </button>
                </div>
              </div>
            @endforeach

            <button type="button" wire:click="addRecipeComponent('create')" class="rounded-full border border-emerald-300/35 bg-emerald-500/10 px-3 py-1 text-[11px] font-semibold text-emerald-50">
              Add oil/blend source
            </button>
            @error('create.recipe_components') <div class="text-xs text-red-300">{{ $message }}</div> @enderror
          </div>
        </div>
      @endif

      <div class="mt-4 flex items-center gap-2">
        <button wire:click="create" wire:loading.attr="disabled" wire:target="create" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60">
          <span wire:loading.remove wire:target="create">Save</span>
          <span wire:loading wire:target="create">Saving...</span>
        </button>
        <button wire:click="closeCreate" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/70">
          Cancel
        </button>
      </div>
    </div>
  @endif

  <div class="mt-4 flex flex-wrap items-center gap-2">
    <flux:input wire:model.live="search" placeholder="Search scents..." />
    <div class="flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-2">
      <span class="text-[11px] uppercase tracking-wide text-white/50">Rows</span>
      <select wire:model.live="perPage" class="bg-transparent text-xs text-white/80 focus:outline-none">
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  </div>

  <div class="mt-4 overflow-hidden rounded-2xl border border-white/10 bg-[#0c1210]/55">
    <div class="overflow-x-auto">
      <table class="min-w-[74rem] w-full table-fixed text-sm">
        <colgroup>
          <col class="w-[18rem]">
          <col class="w-[6rem]">
          <col class="w-[17rem]">
          <col class="w-[13rem]">
          <col class="w-[15rem]">
          <col class="w-[6rem]">
          <col class="w-[7rem]">
          <col class="w-[9.5rem]">
        </colgroup>
      <thead class="bg-white/10 text-white/75">
        <tr>
          <th class="cursor-pointer whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]" wire:click="setSort('name')">Name</th>
          <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]" title="Abbreviation">Abbrev</th>
          <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]" title="Oil Reference">Oil Ref</th>
          <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]">Canonical</th>
          <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]" title="Wholesale Source">Wholesale</th>
          <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]">Blend</th>
          <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]">Active</th>
          <th class="whitespace-nowrap px-3 py-2.5 text-right text-[11px] uppercase tracking-[0.2em]">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-white/5">
        @foreach($scents as $scent)
          <tr class="hover:bg-white/5">
            <td class="px-3 py-2.5 align-middle">
              <div class="truncate text-[14px] font-medium text-white" title="{{ $scent->name }}">{{ $scent->name }}</div>
              @if($scent->display_name && strtolower($scent->display_name) !== strtolower($scent->name))
                <div class="truncate text-xs text-white/60" title="{{ $scent->display_name }}">{{ $scent->display_name }}</div>
              @endif
            </td>
            <td class="px-3 py-2.5 align-middle text-sm text-white/80">
              <span class="whitespace-nowrap">{{ $scent->abbreviation ?: '—' }}</span>
            </td>
            <td class="px-3 py-2.5 align-middle text-sm text-white/80">
              @if($scent->oil_reference_name)
                <span class="block truncate" title="{{ $scent->oil_reference_name }}">{{ $scent->oil_reference_name }}</span>
              @else
                —
              @endif
            </td>
            <td class="px-3 py-2.5 align-middle text-sm text-white/80">
              @if($scent->canonicalScent)
                @php $canonicalLabel = $scent->canonicalScent->display_name ?: $scent->canonicalScent->name; @endphp
                <span class="block truncate" title="{{ $canonicalLabel }}">{{ $canonicalLabel }}</span>
              @else
                —
              @endif
            </td>
            <td class="px-3 py-2.5 align-middle text-sm text-white/80">
              @if($scent->sourceWholesaleCustomScent)
                @php
                  $wholesaleLabel = $scent->sourceWholesaleCustomScent->custom_scent_name.' · '.$scent->sourceWholesaleCustomScent->account_name;
                @endphp
                <span class="block truncate" title="{{ $wholesaleLabel }}">{{ $wholesaleLabel }}</span>
              @else
                —
              @endif
            </td>
            <td class="px-3 py-2.5 align-middle">
              @if($scent->is_blend)
                <span class="inline-flex h-6 items-center whitespace-nowrap rounded-md border border-amber-300/35 bg-amber-500/15 px-2 text-[11px] font-medium text-amber-100">
                  Blend
                </span>
              @else
                <span class="inline-flex h-6 items-center whitespace-nowrap rounded-md border border-white/15 bg-white/5 px-2 text-[11px] font-medium text-white/75">
                  Single
                </span>
              @endif
            </td>
            <td class="px-3 py-2.5 align-middle">
              <span class="inline-flex h-6 items-center whitespace-nowrap rounded-md border px-2 text-[11px] font-medium {{ $scent->is_active ? 'border-emerald-300/35 bg-emerald-500/18 text-emerald-100' : 'border-white/15 bg-white/5 text-white/65' }}">
                {{ $scent->is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-3 py-2.5 align-middle text-right">
              <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                <button type="button" wire:click="openEdit({{ $scent->id }})" class="inline-flex h-7 items-center rounded-full border border-emerald-400/30 bg-emerald-500/10 px-3 text-[11px] text-emerald-100">Edit</button>
                <button type="button" wire:click="openDelete({{ $scent->id }})" class="inline-flex h-7 items-center rounded-full border border-red-400/30 bg-red-500/10 px-3 text-[11px] text-red-100">Delete</button>
              </div>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  </div>

  <div class="mt-4">{{ $scents->links() }}</div>
</section>

@if($showEdit)
  <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/70 p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
    <div class="max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-2xl border border-white/10 bg-zinc-950 p-6">
      <div class="text-lg font-semibold text-white">Edit Scent</div>

      @if($editErrorBanner)
        <div class="mt-3 rounded-xl border border-red-300/35 bg-red-950/30 px-3 py-2 text-xs text-red-100">
          {{ $editErrorBanner }}
        </div>
      @endif

      <div class="mt-4 grid gap-3 md:grid-cols-6">
        <div class="md:col-span-2">
          <flux:input wire:model.defer="edit.name" label="Name" />
          @error('edit.name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div class="md:col-span-2">
          <flux:input wire:model.defer="edit.display_name" label="Display Name" />
          @error('edit.display_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div>
          <flux:input wire:model.defer="edit.abbreviation" label="Abbrev" />
          @error('edit.abbreviation') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div>
          <label class="text-xs text-white/70">Oil Ref</label>
          <input
            wire:model.defer="edit.oil_reference_name"
            list="catalog-edit-oil-ref-list"
            class="mt-1 h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-white/90"
            placeholder="Search/select primary oil or type custom"
          />
          <datalist id="catalog-edit-oil-ref-list">
            @foreach($baseOils as $oil)
              <option value="{{ $oil->name }}">{{ $oil->name }}</option>
            @endforeach
          </datalist>
          @error('edit.oil_reference_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div class="md:col-span-3">
          <label class="text-xs text-white/70">Map to canonical scent (optional)</label>
          <div class="mt-1">
            <livewire:components.scent-combobox
              wire:model.live="edit.canonical_scent_id"
              :emit-key="'catalog-scent-edit-canonical'"
              :allow-wholesale-custom="true"
              :include-inactive="true"
              wire:key="catalog-scent-edit-canonical-{{ $editingId }}"
            />
          </div>
          @if($editCanonicalSuggestionId && $editCanonicalSuggestionLabel)
            <button type="button" wire:click="applyCanonicalSuggestion('edit')" class="mt-2 rounded-full border border-emerald-300/30 bg-emerald-500/10 px-3 py-1 text-[11px] text-emerald-50">
              Suggested: map to {{ $editCanonicalSuggestionLabel }}
            </button>
          @endif
          @error('edit.canonical_scent_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div class="md:col-span-3">
          <label class="text-xs text-white/70">Wholesale custom source (optional)</label>
          <div class="mt-1 flex items-center gap-2">
            <select wire:model.defer="edit.source_wholesale_custom_scent_id" class="h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-white/90">
              <option value="">None</option>
              @foreach($wholesaleSources as $source)
                @php
                  $slotCount = collect([$source->oil_1, $source->oil_2, $source->oil_3])->filter(fn ($slot) => !blank($slot))->count();
                  $topLevelCount = is_array(data_get($source, 'top_level_recipe_json.components'))
                    ? count(data_get($source, 'top_level_recipe_json.components'))
                    : 0;
                  $isBlendLike = $slotCount > 1 || $topLevelCount > 1;
                @endphp
                <option value="{{ $source->id }}">
                  {{ $source->custom_scent_name }} · {{ $source->account_name }} · {{ $isBlendLike ? 'Wholesale custom blend' : 'Wholesale custom scent' }}
                </option>
              @endforeach
            </select>
            <button type="button" wire:click="applySelectedWholesaleSource('edit')" class="shrink-0 rounded-full border border-emerald-300/35 bg-emerald-500/20 px-3 py-2 text-[11px] font-semibold text-white">
              Apply
            </button>
          </div>
          @error('edit.source_wholesale_custom_scent_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div>
          <label class="text-xs text-white/70">Recipe-backed scent?</label>
          <div class="mt-2 flex h-10 items-center gap-2">
            <input type="checkbox" wire:model.defer="edit.is_blend" class="rounded border-white/20 bg-white/10" />
            <span class="text-sm text-white/80">Blend / multi-source recipe</span>
          </div>
          @error('edit.is_blend') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div class="flex items-center gap-2">
          <input type="checkbox" wire:model.defer="edit.is_active" class="rounded border-white/20 bg-white/10" />
          <span class="text-sm text-white/80">Active</span>
        </div>
      </div>

      @if($edit['is_blend'] ?? false)
        <div class="mt-4 rounded-2xl border border-emerald-200/20 bg-black/20 p-4">
          <div class="text-xs uppercase tracking-[0.24em] text-emerald-100/70">Blend Mapping</div>
          <div class="mt-3 grid gap-3 md:grid-cols-6">
            <div class="md:col-span-3">
              <label class="text-xs text-white/70">Existing blend</label>
              <select wire:model.defer="edit.oil_blend_id" class="mt-1 h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-white/90">
                <option value="">None</option>
                @foreach($blends as $blend)
                  <option value="{{ $blend->id }}">{{ $blend->name }}</option>
                @endforeach
              </select>
              @error('edit.oil_blend_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            </div>
            <div>
              <flux:input wire:model.defer="edit.blend_oil_count" label="Oil count" type="number" min="1" />
              @error('edit.blend_oil_count') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            </div>
            <div class="md:col-span-2">
              <label class="text-xs text-white/70">Create new blend from sources</label>
              <div class="mt-2 flex h-10 items-center gap-2">
                <input type="checkbox" wire:model.defer="edit.create_inline_blend" class="rounded border-white/20 bg-white/10" />
                <span class="text-sm text-white/80">Create inline</span>
              </div>
              @error('edit.create_inline_blend') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            </div>
            @if($edit['create_inline_blend'] ?? false)
              <div class="md:col-span-3">
                <flux:input wire:model.defer="edit.inline_blend_name" label="New blend name" />
                @error('edit.inline_blend_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
              </div>
            @endif
          </div>

          <div class="mt-4 space-y-2">
            <div class="text-xs uppercase tracking-[0.2em] text-white/60">Recipe Sources</div>
            @foreach($edit['recipe_components'] ?? [] as $index => $row)
              @php $rowType = $row['type'] ?? 'base_oil'; @endphp
              <div class="grid gap-2 md:grid-cols-8" wire:key="edit-recipe-row-{{ $index }}">
                <div class="md:col-span-2">
                  <label class="mb-1 block text-[11px] text-white/60">Source type</label>
                  <select wire:model.defer="edit.recipe_components.{{ $index }}.type" class="h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-white/90">
                    <option value="base_oil">Oil</option>
                    <option value="blend">Blend</option>
                  </select>
                </div>
                <div class="md:col-span-4">
                  <label class="mb-1 block text-[11px] text-white/60">Source</label>
                  <select wire:model.defer="edit.recipe_components.{{ $index }}.id" class="h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-white/90">
                    <option value="">Select {{ $rowType === 'blend' ? 'blend' : 'oil' }}</option>
                    @if($rowType === 'blend')
                      @foreach($blends as $blend)
                        <option value="{{ $blend->id }}">{{ $blend->name }}</option>
                      @endforeach
                    @else
                      @foreach($baseOils as $oil)
                        <option value="{{ $oil->id }}">{{ $oil->name }}</option>
                      @endforeach
                    @endif
                  </select>
                  @error("edit.recipe_components.$index.id") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
                </div>
                <div>
                  <flux:input wire:model.defer="edit.recipe_components.{{ $index }}.ratio_weight" label="Weight" type="number" min="1" />
                  @error("edit.recipe_components.$index.ratio_weight") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
                </div>
                <div class="flex items-end">
                  <button type="button" wire:click="removeRecipeComponent('edit', {{ $index }})" class="h-10 w-full rounded-xl border border-red-400/30 bg-red-500/10 px-3 text-[11px] font-semibold text-red-100">
                    Remove
                  </button>
                </div>
              </div>
            @endforeach
            <button type="button" wire:click="addRecipeComponent('edit')" class="rounded-full border border-emerald-300/35 bg-emerald-500/10 px-3 py-1 text-[11px] font-semibold text-emerald-50">
              Add oil/blend source
            </button>
            @error('edit.recipe_components') <div class="text-xs text-red-300">{{ $message }}</div> @enderror
          </div>
        </div>
      @endif

      <div class="mt-4 flex items-center gap-2">
        <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60">
          <span wire:loading.remove wire:target="save">Save</span>
          <span wire:loading wire:target="save">Saving...</span>
        </button>
        <button type="button" wire:click="closeEdit" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/70">Cancel</button>
      </div>
    </div>
  </div>
@endif

@if($showDelete)
  <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
    <div class="w-full max-w-md rounded-2xl border border-white/10 bg-zinc-950 p-6">
      <div class="text-lg font-semibold text-white">Delete Scent</div>
      <div class="mt-2 text-sm text-white/70">Are you sure? This cannot be undone.</div>
      <div class="mt-4 flex items-center gap-2">
        <button type="button" wire:click="destroy" class="rounded-full border border-red-400/40 bg-red-500/30 px-4 py-2 text-xs font-semibold text-white">Delete</button>
        <button type="button" wire:click="$set('showDelete', false)" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/70">Cancel</button>
      </div>
    </div>
  </div>
@endif

@once
  <script>
    window.addEventListener('catalog-scent-focus-invalid', (event) => {
      const payload = event?.detail?.[0] && typeof event.detail[0] === 'object' ? event.detail[0] : event.detail;
      const field = payload?.field;
      if (!field || typeof field !== 'string') return;

      const selectors = [
        `[wire\\:model\\.defer="${field}"]`,
        `[wire\\:model\\.live="${field}"]`,
        `[wire\\:model="${field}"]`,
      ];

      const target = document.querySelector(selectors.join(','));
      if (!target) return;

      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (typeof target.focus === 'function') {
        target.focus();
      }
    });
  </script>
@endonce
