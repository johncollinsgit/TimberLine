<div
  wire:init="refresh"
  @if(in_array((string) $syncStatus, ['queued', 'running'], true))
    wire:poll.15s="refresh"
  @endif
>
  <div class="flex items-start justify-between gap-2">
    <div>
      <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Upcoming Events</div>
      <div class="mt-1 text-[11px] text-emerald-100/60">
        DB-only view. Sync is manual.
        @if(!empty($lastSyncedHuman))
          <span class="ml-1">Last sync {{ $lastSyncedHuman }}.</span>
        @endif
      </div>
      @if(!empty($syncMessage))
        <div class="mt-1 text-[11px] text-emerald-100/55">{{ $syncMessage }}</div>
      @endif
    </div>
    <button
      type="button"
      wire:click="syncEvents"
      @disabled(in_array((string) $syncStatus, ['queued', 'running'], true))
      class="rounded-xl border border-emerald-300/25 bg-emerald-500/10 px-3 py-2 text-xs text-white/90 disabled:cursor-not-allowed disabled:opacity-60"
    >
      @if((string) $syncStatus === 'running')
        Sync Running...
      @elseif((string) $syncStatus === 'queued')
        Sync Queued...
      @else
        Sync
      @endif
    </button>
  </div>

  @if(!empty($error))
    <div class="mt-2 rounded-xl border border-rose-300/20 bg-rose-500/10 px-3 py-2 text-xs text-rose-100">
      {{ $error }}
    </div>
  @endif
</div>
