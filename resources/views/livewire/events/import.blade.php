<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Events</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Import Event History</div>
    <div class="mt-2 text-sm text-emerald-50/70">Upload a CSV to backfill past events and shipments.</div>
    <a href="{{ route('events.import-market-box-plans') }}" class="mt-3 inline-flex rounded-full border border-emerald-400/25 bg-emerald-500/15 px-4 py-2 text-xs text-white/90">Need historical market box templates? Import Market Box Plans</a>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 space-y-4">
    <div class="text-xs text-emerald-100/60">Expected columns</div>
    <div class="text-[11px] text-white/60">
      name, venue, city, state, starts_at, ends_at, due_date, ship_date, status, scent, size, planned_qty, sent_qty, returned_qty, sold_qty
    </div>

    <input type="file" wire:model="file" class="text-xs text-white/80" />
    @error('file')
      <div class="text-xs text-red-300">{{ $message }}</div>
    @enderror
    <div wire:loading wire:target="file" class="text-[11px] text-emerald-100/70">Uploading file…</div>

    <div>
      <button type="button" wire:click="importCsv" wire:loading.attr="disabled" wire:target="importCsv,file"
        class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-4 py-2 text-xs text-white/90 disabled:opacity-50">
        Import CSV
      </button>
      <span wire:loading wire:target="importCsv" class="ml-2 text-[11px] text-emerald-100/70">Importing…</span>
    </div>

    @if(!empty($report))
      <div class="text-xs text-emerald-100/70">
        Imported:
        {{ (int)($report['events_created'] ?? 0) }} events created,
        {{ (int)($report['events_updated'] ?? 0) }} events updated,
        {{ (int)($report['shipments_created'] ?? 0) }} shipments created,
        {{ (int)($report['market_plans_created'] ?? 0) }} market plans created,
        {{ (int)($report['market_plans_updated'] ?? 0) }} market plans updated,
        skipped {{ (int)($report['skipped'] ?? 0) }} rows.
      </div>
    @endif

    @if(!empty($warnings))
      <div class="rounded-2xl border border-amber-300/20 bg-amber-500/10 p-3 text-[11px] text-amber-100">
        <div class="font-semibold">Import warnings</div>
        <ul class="mt-2 space-y-1">
          @foreach($warnings as $err)
            <li>{{ $err }}</li>
          @endforeach
        </ul>
      </div>
    @endif
  </section>
</div>
