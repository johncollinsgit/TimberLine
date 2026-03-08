<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">New Scent Wizard</div>
        <h1 class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Create Canonical Scent</h1>
        <p class="mt-2 max-w-3xl text-sm text-emerald-50/75">
          This is the approved creation path for new scents. Use Master Data for ongoing maintenance/editing.
        </p>
      </div>
      <a
        href="{{ $returnTo }}"
        wire:navigate
        class="inline-flex h-10 items-center justify-center rounded-full border border-white/10 bg-white/5 px-4 text-xs font-semibold text-white/85 hover:bg-white/10"
      >
        Back
      </a>
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="grid gap-3 md:grid-cols-4">
      <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/60">Incoming Name</div>
        <div class="mt-1 text-sm text-white/90">{{ $context['raw_name'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/60">Variant</div>
        <div class="mt-1 text-sm text-white/90">{{ $context['raw_variant'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/60">Account</div>
        <div class="mt-1 text-sm text-white/90">{{ $context['account_name'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/60">Store</div>
        <div class="mt-1 text-sm text-white/90">{{ $context['store_key'] ?: '—' }}</div>
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="grid gap-3 md:grid-cols-6">
      <div class="md:col-span-2">
        <flux:input wire:model.defer="form.name" label="Name" />
        @error('form.name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="form.display_name" label="Display Name" />
        @error('form.display_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div>
        <flux:input wire:model.defer="form.abbreviation" label="Abbrev" />
        @error('form.abbreviation') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div>
        <flux:input wire:model.defer="form.oil_reference_name" label="Oil Ref" />
        @error('form.oil_reference_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>

      <div class="md:col-span-2">
        <label class="text-xs text-emerald-100/70">Blend-backed scent?</label>
        <div class="mt-2 flex h-10 items-center gap-2">
          <input type="checkbox" wire:model.defer="form.is_blend" class="rounded border-white/20 bg-white/10" />
          <span class="text-sm text-white/80">This scent maps to an existing blend template</span>
        </div>
        @error('form.is_blend') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>

      @if($form['is_blend'] ?? false)
        <div class="md:col-span-2">
          <label class="text-xs text-white/70">Blend template</label>
          <select wire:model.defer="form.oil_blend_id" class="mt-1 h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-white/90">
            <option value="">None selected</option>
            @foreach($blends as $blend)
              <option value="{{ $blend->id }}">{{ $blend->name }}</option>
            @endforeach
          </select>
          @error('form.oil_blend_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div>
          <flux:input wire:model.defer="form.blend_oil_count" type="number" min="1" label="Blend oils" />
          @error('form.blend_oil_count') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
      @endif

      <div class="flex items-center gap-2">
        <input type="checkbox" wire:model.defer="form.is_wholesale_custom" class="rounded border-white/20 bg-white/10" />
        <span class="text-sm text-white/80">Wholesale custom</span>
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" wire:model.defer="form.is_candle_club" class="rounded border-white/20 bg-white/10" />
        <span class="text-sm text-white/80">Candle Club scent</span>
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" wire:model.defer="form.create_alias" class="rounded border-white/20 bg-white/10" />
        <span class="text-sm text-white/80">Create alias from incoming name</span>
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" wire:model.defer="form.is_active" class="rounded border-white/20 bg-white/10" />
        <span class="text-sm text-white/80">Active</span>
      </div>
    </div>

    <div class="mt-5 flex items-center gap-2">
      <button
        type="button"
        wire:click="save"
        wire:loading.attr="disabled"
        wire:target="save"
        class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60"
      >
        <span wire:loading.remove wire:target="save">Create Scent</span>
        <span wire:loading wire:target="save">Creating…</span>
      </button>
      <a
        href="{{ $returnTo }}"
        wire:navigate
        class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/75 hover:bg-white/10"
      >
        Cancel
      </a>
    </div>
  </section>
</div>

