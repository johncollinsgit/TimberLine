<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Market Pour List</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">New Market Pour List</div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 space-y-4">
    <input type="text" wire:model="title" placeholder="Title (e.g. Spring Markets 2026 – Wave 1)"
      class="w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90" />

    <div class="text-xs text-emerald-100/60">Select events</div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
      @foreach($events as $event)
        <label class="flex items-center gap-2 rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-xs text-white/80">
          <input type="checkbox" wire:model="selectedEvents" value="{{ $event->id }}">
          {{ $event->name }} ({{ $event->starts_at }} – {{ $event->ends_at }})
        </label>
      @endforeach
    </div>

    <button type="button" wire:click="create"
      class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-4 py-2 text-xs text-white/90">
      Create Market Pour List
    </button>
  </section>
</div>
