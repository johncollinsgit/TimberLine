<section class="mf-app-card rounded-3xl border border-zinc-200 p-5">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div>
      <div class="text-lg font-semibold text-zinc-950">Product Costs</div>
      <div class="text-sm text-zinc-600">Manage product cost rules for reporting and margin visibility.</div>
    </div>
    <div class="flex items-center gap-2">
      <button
        wire:click="openCreate"
        class="rounded-full border border-emerald-400/40 bg-emerald-100 px-4 py-2 text-xs font-semibold text-zinc-950 disabled:cursor-not-allowed disabled:opacity-50"
        @disabled(!($catalogCostsAvailable ?? true))
      >
        {{ $showCreate ? 'Hide form' : 'Add cost rule' }}
      </button>
    </div>
  </div>

  @if(!($catalogCostsAvailable ?? true))
    <div class="mt-4 rounded-2xl border border-amber-300/30 bg-amber-100 px-4 py-3 text-sm text-amber-800">
      <p class="font-semibold text-zinc-950">Catalog costs are unavailable in this environment.</p>
      <p class="mt-1">
        Run migrations to create the <code>catalog_item_costs</code> table, then reload this tab to continue.
      </p>
    </div>
  @else
    <div class="mt-4 rounded-2xl border border-emerald-300/20 bg-emerald-100 px-4 py-3 text-xs text-zinc-600">
      Resolver order: Shopify variant, SKU, Shopify product, scent + size, then size-only fallback. Add the most specific cost you know first.
    </div>
  @endif

  @if($showCreate && ($catalogCostsAvailable ?? true))
    <form wire:submit.prevent="create" class="mt-4 grid gap-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 md:grid-cols-12">
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.shopify_store_key" label="Store" placeholder="retail" />
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.shopify_variant_id" label="Variant ID" type="number" min="1" />
        @error('create.shopify_variant_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.shopify_product_id" label="Product ID" type="number" min="1" />
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.sku" label="SKU" />
      </div>
      <div class="md:col-span-2">
        <label class="mb-1 block text-xs font-medium uppercase tracking-[0.2em] text-zinc-500">Scent</label>
        <select wire:model.defer="create.scent_id" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 focus:border-emerald-300 focus:outline-none">
          <option value="">Any scent</option>
          @foreach($scentOptions as $scent)
            <option value="{{ $scent->id }}">{{ $scent->display_name ?: $scent->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="md:col-span-2">
        <label class="mb-1 block text-xs font-medium uppercase tracking-[0.2em] text-zinc-500">Size</label>
        <select wire:model.defer="create.size_id" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 focus:border-emerald-300 focus:outline-none">
          <option value="">Any size</option>
          @foreach($sizeOptions as $size)
            <option value="{{ $size->id }}">{{ $size->label ?: $size->code }}</option>
          @endforeach
        </select>
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.cost_amount" label="Cost" type="number" step="0.01" min="0" />
        @error('create.cost_amount') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div class="md:col-span-1">
        <flux:input wire:model.defer="create.currency_code" label="Currency" />
      </div>
      <div class="md:col-span-3">
        <flux:input wire:model.defer="create.effective_at" label="Effective at" type="datetime-local" />
      </div>
      <div class="md:col-span-6">
        <flux:input wire:model.defer="create.notes" label="Notes" />
      </div>
      <div class="md:col-span-2 flex items-center gap-2 pt-6">
        <input type="checkbox" wire:model.defer="create.is_active" class="rounded border-zinc-300 bg-zinc-100" />
        <span class="text-sm text-zinc-700">Active</span>
      </div>
      <div class="md:col-span-12 flex items-center gap-2">
        <button type="submit" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-zinc-950">
          Save cost
        </button>
        <button type="button" wire:click="openCreate" class="rounded-full border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-600">
          Cancel
        </button>
      </div>
    </form>
  @endif

  <div class="mt-4 flex flex-wrap items-center gap-2">
    <flux:input wire:model.live="search" placeholder="Search costs..." />
    <div class="flex items-center gap-2 rounded-full border border-zinc-200 bg-zinc-50 px-3 py-2">
      <span class="text-[11px] uppercase tracking-wide text-zinc-500">Rows</span>
      <select wire:model.live="perPage" class="bg-transparent text-xs text-zinc-700 focus:outline-none">
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  </div>

  <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200 bg-white">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-zinc-50 text-zinc-600">
          <tr>
            <th class="cursor-pointer px-4 py-3 text-left" wire:click="setSort('shopify_store_key')">Store</th>
            <th class="px-4 py-3 text-left">Matcher</th>
            <th class="px-4 py-3 text-left">Catalog fallback</th>
            <th class="cursor-pointer px-4 py-3 text-left" wire:click="setSort('cost_amount')">Cost</th>
            <th class="px-4 py-3 text-left">Currency</th>
            <th class="cursor-pointer px-4 py-3 text-left" wire:click="setSort('effective_at')">Effective</th>
            <th class="px-4 py-3 text-left">Status</th>
            <th class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200">
          @forelse($costs as $cost)
            <tr class="hover:bg-zinc-50">
              <td class="px-4 py-3 text-zinc-800">{{ $cost->shopify_store_key ?: 'All stores' }}</td>
              <td class="px-4 py-3 text-zinc-950">
                <div class="space-y-1">
                  @if($cost->shopify_variant_id)
                    <div>Variant {{ $cost->shopify_variant_id }}</div>
                  @endif
                  @if($cost->sku)
                    <div>SKU {{ $cost->sku }}</div>
                  @endif
                  @if($cost->shopify_product_id)
                    <div>Product {{ $cost->shopify_product_id }}</div>
                  @endif
                  @if(!$cost->shopify_variant_id && !$cost->sku && !$cost->shopify_product_id)
                    <div class="text-zinc-500">Catalog fallback only</div>
                  @endif
                </div>
              </td>
              <td class="px-4 py-3 text-zinc-700">
                <div class="space-y-1">
                  <div>{{ $cost->scent?->display_name ?: $cost->scent?->name ?: 'Any scent' }}</div>
                  <div>{{ $cost->size?->label ?: $cost->size?->code ?: 'Any size' }}</div>
                </div>
              </td>
              <td class="px-4 py-3 text-zinc-950">{{ '$' . number_format((float) $cost->cost_amount, 2) }}</td>
              <td class="px-4 py-3 text-zinc-700">{{ $cost->currency_code }}</td>
              <td class="px-4 py-3 text-zinc-700">{{ $cost->effective_at?->format('M j, Y g:i A') ?: 'Immediate' }}</td>
              <td class="px-4 py-3">
                <span class="inline-flex rounded-full px-2 py-1 text-xs {{ $cost->is_active ? 'bg-emerald-100 text-emerald-900' : 'bg-zinc-100 text-zinc-500' }}">
                  {{ $cost->is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-4 py-3 text-right">
                <button type="button" wire:click="openEdit({{ $cost->id }})" class="rounded-full border border-emerald-400/30 bg-emerald-100 px-3 py-1 text-[11px] text-emerald-900">Edit</button>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="8" class="px-4 py-6 text-center text-zinc-600">
                @if(!($catalogCostsAvailable ?? true))
                  Catalog costs are not available in this environment yet.
                @else
                  No cost rules found for the current filters.
                @endif
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-4">{{ $costs->links() }}</div>
</section>

@if($showEdit)
  <div class="fixed inset-0 z-[9999] flex items-center justify-center fb-overlay-soft p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
    <div class="mf-app-card w-full max-w-4xl rounded-2xl border border-zinc-200 p-6">
      <div class="text-lg font-semibold text-zinc-950">Edit Cost Rule</div>
      <div class="mt-4 grid gap-3 md:grid-cols-12">
        <div class="md:col-span-2">
          <flux:input wire:model.defer="edit.shopify_store_key" label="Store" />
        </div>
        <div class="md:col-span-2">
          <flux:input wire:model.defer="edit.shopify_variant_id" label="Variant ID" type="number" min="1" />
          @error('edit.shopify_variant_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div class="md:col-span-2">
          <flux:input wire:model.defer="edit.shopify_product_id" label="Product ID" type="number" min="1" />
        </div>
        <div class="md:col-span-2">
          <flux:input wire:model.defer="edit.sku" label="SKU" />
        </div>
        <div class="md:col-span-2">
          <label class="mb-1 block text-xs font-medium uppercase tracking-[0.2em] text-zinc-500">Scent</label>
          <select wire:model.defer="edit.scent_id" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 focus:border-emerald-300 focus:outline-none">
            <option value="">Any scent</option>
            @foreach($scentOptions as $scent)
              <option value="{{ $scent->id }}">{{ $scent->display_name ?: $scent->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="md:col-span-2">
          <label class="mb-1 block text-xs font-medium uppercase tracking-[0.2em] text-zinc-500">Size</label>
          <select wire:model.defer="edit.size_id" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 focus:border-emerald-300 focus:outline-none">
            <option value="">Any size</option>
            @foreach($sizeOptions as $size)
              <option value="{{ $size->id }}">{{ $size->label ?: $size->code }}</option>
            @endforeach
          </select>
        </div>
        <div class="md:col-span-2">
          <flux:input wire:model.defer="edit.cost_amount" label="Cost" type="number" step="0.01" min="0" />
          @error('edit.cost_amount') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div class="md:col-span-1">
          <flux:input wire:model.defer="edit.currency_code" label="Currency" />
        </div>
        <div class="md:col-span-3">
          <flux:input wire:model.defer="edit.effective_at" label="Effective at" type="datetime-local" />
        </div>
        <div class="md:col-span-6">
          <flux:input wire:model.defer="edit.notes" label="Notes" />
        </div>
        <div class="md:col-span-2 flex items-center gap-2 pt-6">
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
