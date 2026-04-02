<section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div>
      <div class="text-lg font-semibold text-zinc-950">Wholesale Custom Scents</div>
      <div class="text-sm text-zinc-600">Account-specific scent names mapped to canonical scents.</div>
      <div class="mt-1 text-xs text-emerald-800">Customer-specific naming and mapping. Use the wizard for manual adds; master CSV sync can create canonical blends when missing.</div>
    </div>
    <div class="flex items-center gap-2">
      <label class="relative inline-flex h-10 cursor-pointer items-center rounded-full border border-amber-300/40 bg-amber-100 px-4 text-xs font-semibold text-amber-900 hover:bg-amber-500/30">
        <input
          type="file"
          wire:model="masterCsvUpload"
          accept=".csv,text/csv"
          class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
        />
        <span wire:loading.remove wire:target="masterCsvUpload,syncMasterCsv">Upload + Sync Master CSV</span>
        <span wire:loading wire:target="masterCsvUpload,syncMasterCsv">Syncing master CSV…</span>
      </label>
      <button wire:click="openCreate" class="rounded-full border border-emerald-400/40 bg-emerald-100 px-4 py-2 text-xs font-semibold text-zinc-950">
        Add custom scent
      </button>
      <a
        href="{{ route('admin.scent-wizard', ['source_context' => 'wholesale-custom', 'channel_hint' => 'wholesale', 'store' => 'wholesale', 'return_to' => route('admin.index', ['tab' => 'wholesale-custom'])]) }}"
        wire:navigate
        class="rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-xs font-semibold text-emerald-900"
      >
        New Scent Wizard
      </a>
    </div>
  </div>
  @error('masterCsvUpload')
    <div class="mt-3 rounded-xl border border-red-300/35 bg-red-950/25 px-3 py-2 text-xs text-red-100">{{ $message }}</div>
  @enderror

  @if($showCreate)
    <div class="mt-4 grid gap-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 md:grid-cols-6">
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.account_name" label="Account name" />
        @error('create.account_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.custom_scent_name" label="Custom scent name" />
        @error('create.custom_scent_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div class="md:col-span-2">
        <label class="text-xs text-zinc-600">Canonical scent (optional)</label>
        <div class="mt-1">
          <livewire:components.scent-combobox
            wire:model.live="create.canonical_scent_id"
            :emit-key="'wholesale-create'"
            :allow-wholesale-custom="true"
            :include-inactive="true"
            wire:key="wholesale-create-combo"
          />
        </div>
        @error('create.canonical_scent_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.oil_1" label="Oil #1" />
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.oil_2" label="Oil #2" />
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.oil_3" label="Oil #3" />
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.total_oils" label="Total oils" type="number" />
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.abbreviation" label="Abbreviation" />
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.notes" label="Notes" />
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" wire:model.defer="create.active" class="rounded border-zinc-300 bg-zinc-100" />
        <span class="text-sm text-zinc-700">Active</span>
      </div>
      <div class="md:col-span-6 flex items-center gap-2">
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
    <div class="mr-auto text-xs text-zinc-500">
      {{ $records->total() }} {{ \Illuminate\Support\Str::plural('custom scent', $records->total()) }}
    </div>
    <flux:input wire:model.live="search" placeholder="Search account or scent..." />
    <div class="flex items-center gap-2 rounded-full border border-zinc-200 bg-zinc-50 px-3 py-2">
      <span class="text-[11px] uppercase tracking-wide text-zinc-500">Filter</span>
      <select wire:model.live="filter" class="bg-transparent text-xs text-zinc-700 focus:outline-none">
        <option value="all">All</option>
        <option value="mapped">Mapped</option>
        <option value="unmapped">Unmapped</option>
      </select>
    </div>
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
          <th class="px-4 py-3 text-left">Wholesale Account Name</th>
          <th class="px-4 py-3 text-left">Scent Name</th>
          <th class="px-4 py-3 text-left">Oil #1</th>
          <th class="px-4 py-3 text-left">Oil #2</th>
          <th class="px-4 py-3 text-left">Oil #3</th>
          <th class="px-4 py-3 text-left">Total Oils</th>
          <th class="px-4 py-3 text-left">Abbreviation</th>
          <th class="px-4 py-3 text-left">Canonical Scent</th>
          <th class="px-4 py-3 text-left">Oils Used</th>
          <th class="px-4 py-3 text-left">Status</th>
          <th class="px-4 py-3 text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-zinc-200">
        @forelse($records as $record)
          <tr class="{{ (int)$editingId === (int)$record->id ? 'bg-emerald-100' : 'hover:bg-zinc-50' }}">
            <td class="px-4 py-3 text-zinc-950">{{ $record->account_name }}</td>
            <td class="px-4 py-3 text-zinc-950">{{ $record->custom_scent_name }}</td>
            <td class="px-4 py-3 text-zinc-700">{{ $record->oil_1 ?: '—' }}</td>
            <td class="px-4 py-3 text-zinc-700">{{ $record->oil_2 ?: '—' }}</td>
            <td class="px-4 py-3 text-zinc-700">{{ $record->oil_3 ?: '—' }}</td>
            <td class="px-4 py-3 text-zinc-700">{{ $record->total_oils ?? '—' }}</td>
            <td class="px-4 py-3 text-zinc-700">{{ $record->abbreviation ?: '—' }}</td>
            <td class="px-4 py-3 text-zinc-700">
              {{ $record->canonicalScent?->display_name ?: ($record->canonicalScent?->name ?? '—') }}
            </td>
            <td class="px-4 py-3 text-zinc-700">
              @php
                $resolved = is_array($record->resolved_recipe_json['components'] ?? null)
                  ? collect($record->resolved_recipe_json['components'])
                  : collect();
                if ($resolved->isEmpty()) {
                  $fallbackComponents = $record->canonicalScent?->oilBlend?->components
                    ? $record->canonicalScent->oilBlend->components->sortBy('id')->values()
                    : collect();
                  if ($fallbackComponents->isNotEmpty()) {
                    $resolved = $fallbackComponents->map(function ($component): array {
                      return [
                        'name' => (string) ($component->baseOil?->name ?? 'Unknown oil'),
                        'percent' => 0.0,
                      ];
                    });
                  }
                }
                $oilLines = $resolved->take(3)->values();
              @endphp

              @if($oilLines->isNotEmpty())
                <div class="space-y-0.5 text-xs text-zinc-700">
                  @foreach($oilLines as $index => $component)
                    <div class="truncate">
                      Oil {{ $index + 1 }}:
                      {{ (string)($component['name'] ?? 'Unknown oil') }}
                      @if(((float) ($component['percent'] ?? 0.0)) > 0)
                        ({{ rtrim(rtrim(number_format((float) $component['percent'], 4), '0'), '.') }}%)
                      @endif
                    </div>
                  @endforeach
                  @if($resolved->count() > 3)
                    <div class="text-[11px] text-zinc-500">+{{ $resolved->count() - 3 }} more</div>
                  @endif
                </div>
              @else
                <span class="text-zinc-500">—</span>
              @endif
            </td>
            <td class="px-4 py-3">
              @if($record->canonical_scent_id)
                <span class="inline-flex rounded-full px-2 py-1 text-xs bg-emerald-100 text-emerald-900">Mapped</span>
              @else
                <span class="inline-flex rounded-full px-2 py-1 text-xs bg-amber-100 text-amber-900">Unmapped</span>
              @endif
            </td>
            <td class="px-4 py-3 text-right space-x-2">
              <button
                type="button"
                wire:click.prevent.stop="openEdit({{ $record->id }})"
                class="inline-flex h-8 items-center rounded-lg border border-emerald-400/35 bg-emerald-100 px-3 text-[11px] font-semibold text-emerald-900 hover:bg-emerald-500/30"
              >
                {{ (int)$editingId === (int)$record->id && $showEdit ? 'Close Edit' : 'Edit Mapping' }}
              </button>
              <button
                type="button"
                wire:click.prevent.stop="openDelete({{ $record->id }})"
                class="inline-flex h-8 items-center rounded-lg border border-red-400/35 bg-red-500/15 px-3 text-[11px] font-semibold text-red-100 hover:bg-red-500/25"
              >
                Delete
              </button>
            </td>
          </tr>

          @if((int)$editingId === (int)$record->id && $showEdit)
            <tr class="bg-emerald-50">
              <td colspan="11" class="px-4 py-4">
                <div class="rounded-2xl border border-emerald-300/25 bg-emerald-950/20 p-4">
                  <div class="flex flex-wrap items-start justify-between gap-2 border-b border-zinc-200 pb-3">
                    <div>
                      <div class="text-[11px] uppercase tracking-[0.24em] text-emerald-800">Edit Mapping</div>
                      <div class="mt-1 text-sm font-semibold text-zinc-950">{{ $edit['custom_scent_name'] ?? '' }}</div>
                      <div class="mt-0.5 text-xs text-emerald-800">{{ $edit['account_name'] ?? '' }}</div>
                    </div>
                    <div class="flex items-center gap-2">
                      <button
                        type="button"
                        wire:click="save"
                        class="inline-flex h-9 items-center rounded-xl border border-emerald-300/45 bg-emerald-500/30 px-4 text-xs font-semibold text-zinc-950 hover:bg-emerald-500/40"
                      >
                        Save Changes
                      </button>
                      <button
                        type="button"
                        wire:click="closeEdit"
                        class="inline-flex h-9 items-center rounded-xl border border-zinc-300 bg-zinc-50 px-4 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                      >
                        Cancel
                      </button>
                    </div>
                  </div>

                  <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <flux:input wire:model.defer="edit.account_name" label="Account name" />
                    <flux:input wire:model.defer="edit.custom_scent_name" label="Custom scent name" />
                    <flux:input wire:model.defer="edit.oil_1" label="Oil #1" />
                    <flux:input wire:model.defer="edit.oil_2" label="Oil #2" />
                    <flux:input wire:model.defer="edit.oil_3" label="Oil #3" />
                    <flux:input wire:model.defer="edit.total_oils" label="Total oils" type="number" />
                    <flux:input wire:model.defer="edit.abbreviation" label="Abbreviation" />

                    <div class="md:col-span-2">
                      <label class="text-xs text-zinc-600">Canonical scent (optional)</label>
                      <div class="mt-1">
                        <livewire:components.scent-combobox
                          wire:model.live="edit.canonical_scent_id"
                          :emit-key="'wholesale-edit'"
                          :allow-wholesale-custom="true"
                          :include-inactive="true"
                          wire:key="wholesale-edit-combo-{{ $editingId }}"
                        />
                      </div>
                      <div class="mt-1 text-[11px] text-emerald-800">Search and select canonical scent/blend.</div>
                      @error('edit.canonical_scent_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
                    </div>

                    <flux:input wire:model.defer="edit.notes" label="Notes" />
                    <div class="flex items-center gap-2">
                      <input type="checkbox" wire:model.defer="edit.active" class="rounded border-zinc-300 bg-zinc-100" />
                      <span class="text-sm text-zinc-700">Active</span>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          @endif
        @empty
          <tr>
            <td colspan="11" class="px-4 py-8 text-center text-sm text-zinc-500">
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

@if($showDelete)
  <div class="fixed inset-0 z-[9999] flex items-center justify-center fb-overlay-soft p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
    <div class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6">
      <div class="text-lg font-semibold text-zinc-950">Delete Custom Scent</div>
      <div class="mt-2 text-sm text-zinc-600">Are you sure? This cannot be undone.</div>
      <div class="mt-4 flex items-center gap-2">
        <button type="button" wire:click="destroy" class="rounded-full border border-red-400/40 bg-red-500/30 px-4 py-2 text-xs font-semibold text-zinc-950">Delete</button>
        <button type="button" wire:click="$set('showDelete', false)" class="rounded-full border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-600">Cancel</button>
      </div>
    </div>
  </div>
@endif
