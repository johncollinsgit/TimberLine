<section class="rounded-3xl border border-zinc-200 bg-white p-6">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
    <div>
      <div class="text-xs uppercase tracking-[0.3em] text-emerald-800">Candle Club</div>
      <div class="mt-1 text-2xl font-['Fraunces'] font-semibold text-zinc-950">Monthly Scents</div>
      <div class="mt-2 text-sm text-zinc-600">Assign existing scents to Candle Club month/year slots.</div>
      <div class="mt-1 text-xs text-emerald-800">Create new scents through the New Scent Wizard.</div>
    </div>
    <a
      href="{{ route('admin.scent-wizard', ['source_context' => 'candle-club', 'channel_hint' => 'candle_club', 'return_to' => route('admin.index', ['tab' => 'candle-club'])]) }}"
      wire:navigate
      class="inline-flex h-9 items-center rounded-full border border-zinc-300 bg-emerald-100 px-4 text-xs font-semibold text-zinc-950 hover:bg-emerald-500/25"
    >
      New Scent Wizard
    </a>
  </div>

  <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
    <div>
      <label class="text-xs text-emerald-800">Month</label>
      <select wire:model.defer="month" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900">
        <option value="">Select month</option>
        @for($m=1;$m<=12;$m++)
          <option value="{{ $m }}">{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
        @endfor
      </select>
      @error('month') <div class="text-xs text-red-400 mt-1">{{ $message }}</div> @enderror
    </div>
    <div>
      <label class="text-xs text-emerald-800">Year</label>
      <input type="number" wire:model.defer="year" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" />
      @error('year') <div class="text-xs text-red-400 mt-1">{{ $message }}</div> @enderror
    </div>
    <div class="md:col-span-2">
      <label class="text-xs text-emerald-800">Select existing scent</label>
      <div class="mt-1">
        <livewire:components.scent-combobox
          wire:model.live="scentId"
          :emit-key="'candle-club-scent'"
          :allow-wholesale-custom="true"
          :include-inactive="true"
          wire:key="candle-club-scent-picker"
        />
      </div>
      @error('scentId') <div class="text-xs text-red-400 mt-1">{{ $message }}</div> @enderror
    </div>
  </div>

  <div class="mt-4">
    <button type="button" wire:click="save"
      class="rounded-full border border-emerald-400/40 bg-emerald-500/25 px-4 py-2 text-xs font-semibold text-zinc-950">
      Save Candle Club Assignment
    </button>
  </div>

  <div class="mt-8">
    <div class="flex items-center justify-between">
      <div class="text-xs uppercase tracking-[0.3em] text-emerald-800">Records</div>
      <input type="text" wire:model.live.debounce.250ms="search"
        placeholder="Search…"
        class="h-9 rounded-2xl border border-zinc-200 bg-zinc-50 px-3 text-xs text-zinc-900" />
    </div>

    <div class="mt-4 rounded-2xl border border-zinc-200 overflow-hidden">
      <div class="grid grid-cols-4 gap-0 bg-zinc-50 text-[11px] text-zinc-500 px-3 py-2">
        <div>Month / Year</div>
        <div>Scent</div>
        <div>Oil Reference</div>
        <div>Updated</div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($records as $row)
          <div class="grid grid-cols-4 gap-0 px-3 py-2 text-xs text-zinc-700">
            <div>{{ \Carbon\Carbon::create()->month($row->month)->format('F') }} {{ $row->year }}</div>
            <div class="font-semibold">{{ $row->scent?->display_name ?? $row->scent?->name ?? '—' }}</div>
            <div class="text-zinc-600">{{ $row->scent?->oil_reference_name ?? '—' }}</div>
            <div class="text-zinc-500">{{ optional($row->updated_at)->toDateString() ?? '—' }}</div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-zinc-500">No candle club scents yet.</div>
        @endforelse
      </div>
    </div>

    <div class="mt-4">
      {{ $records->links() }}
    </div>
  </div>
</section>
