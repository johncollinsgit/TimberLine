<div
  wire:init="refresh"
  @if(in_array((string) $syncStatus, ['queued', 'running'], true))
    wire:poll.15s="refresh"
  @endif
>
  <div class="rounded-2xl border border-emerald-200/10 bg-black/15 p-4">
    <div class="flex items-start justify-between gap-3">
      <div>
        <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Events Cache</div>
        <div class="mt-1 text-[11px] text-emerald-100/60">
          DB-only view. Manual sync is only for force-refreshing the stored events.
          @if(!empty($lastSyncedHuman))
            <span class="ml-1">Last sync {{ $lastSyncedHuman }}.</span>
          @endif
        </div>
        @if(!empty($syncMessage))
          <div class="mt-2 text-[11px] text-emerald-100/55">{{ $syncMessage }}</div>
        @endif
      </div>
      <div class="flex shrink-0 items-center gap-2">
        <span class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] {{ (string)$syncStatus === 'failed' ? 'border-rose-300/25 bg-rose-500/10 text-rose-100' : ((string)$syncStatus === 'running' ? 'border-emerald-300/25 bg-emerald-500/12 text-emerald-50' : ((string)$syncStatus === 'queued' ? 'border-amber-300/25 bg-amber-500/10 text-amber-50' : 'border-white/10 bg-white/5 text-emerald-50/85')) }}">
          @if((string) $syncStatus === 'running')
            Syncing...
          @elseif((string) $syncStatus === 'queued')
            Queued
          @elseif((string) $syncStatus === 'failed')
            Failed
          @else
            Ready
          @endif
        </span>
        <button
          type="button"
          wire:click="syncEvents"
          @disabled(in_array((string) $syncStatus, ['queued', 'running'], true))
          class="rounded-xl border border-emerald-300/25 bg-emerald-500/10 px-3 py-2 text-xs font-semibold text-white/90 disabled:cursor-not-allowed disabled:opacity-60"
        >
          Sync Now
        </button>
      </div>
    </div>

    @if((string) $syncStatus === 'failed' && !empty($lastError))
      <div class="mt-3 rounded-xl border border-rose-300/20 bg-rose-500/10 px-3 py-2 text-xs text-rose-100">
        {{ $lastError }}
      </div>
    @endif
  </div>

  @if(!empty($error))
    <div class="mt-2 rounded-xl border border-rose-300/20 bg-rose-500/10 px-3 py-2 text-xs text-rose-100">
      {{ $error }}
    </div>
  @endif
</div>
