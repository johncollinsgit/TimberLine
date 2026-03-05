<div
  wire:init="refresh"
  @if(in_array((string) $syncStatus, ['queued', 'running'], true))
    wire:poll.15s="refresh"
  @endif
>
  <div class="rounded-2xl border border-emerald-200/10 bg-black/15 p-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div class="min-w-0">
        <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Events Cache</div>
        <button
          type="button"
          wire:click="toggleDetails"
          class="mt-2 inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-2.5 py-1 text-[11px] text-emerald-50/80 hover:bg-white/10"
        >
          {{ $detailsOpen ? 'Hide details' : 'Show details' }}
        </button>
      </div>
      <div class="flex flex-wrap items-center gap-2 sm:justify-end">
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

    @if($detailsOpen)
      <div class="mt-3 max-w-prose space-y-2 text-[11px] leading-relaxed text-emerald-100/60">
        <div>
          DB-only view. Manual sync is only for force-refreshing the stored events.
          @if(!empty($lastSyncedHuman))
            <span class="ml-1 inline-block">Last sync {{ $lastSyncedHuman }}.</span>
          @endif
        </div>
        @if(!empty($syncMessage))
          <div class="text-emerald-100/55">{{ $syncMessage }}</div>
        @endif
      </div>
    @endif

    @if((string) $syncStatus === 'failed' && !empty($lastError))
      <div class="mt-3 rounded-xl border border-rose-300/20 bg-rose-500/10 px-3 py-2 text-xs text-rose-100 break-words">
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
