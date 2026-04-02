<div
  wire:init="refresh"
  @if(in_array((string) $syncStatus, ['queued', 'running'], true))
    wire:poll.15s="refresh"
  @endif
>
  <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div class="min-w-0">
        <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-800">Events Cache</div>
        <button
          type="button"
          wire:click="toggleDetails"
          class="mt-2 inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-[11px] text-zinc-600 hover:bg-zinc-100"
        >
          {{ $detailsOpen ? 'Hide details' : 'Show details' }}
        </button>
      </div>
      <div class="flex flex-wrap items-center gap-2 sm:justify-end">
        <span class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] {{ (string)$syncStatus === 'failed' ? 'border-rose-300/25 bg-rose-100 text-rose-900' : ((string)$syncStatus === 'running' ? 'border-emerald-300/25 bg-emerald-100 text-emerald-900' : ((string)$syncStatus === 'queued' ? 'border-amber-300/25 bg-amber-100 text-amber-900' : 'border-zinc-200 bg-zinc-50 text-zinc-600')) }}">
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
          class="rounded-xl border border-emerald-300/25 bg-emerald-100 px-3 py-2 text-xs font-semibold text-zinc-900 disabled:cursor-not-allowed disabled:opacity-60"
        >
          Sync Now
        </button>
      </div>
    </div>

    @if($detailsOpen)
      <div class="mt-3 max-w-prose space-y-2 text-[11px] leading-relaxed text-emerald-800">
        <div>
          DB-only view. Manual sync is only for force-refreshing the stored events.
          @if(!empty($lastSyncedHuman))
            <span class="ml-1 inline-block">Last sync {{ $lastSyncedHuman }}.</span>
          @endif
        </div>
        @if(!empty($syncMessage))
          <div class="text-emerald-800">{{ $syncMessage }}</div>
        @endif
      </div>
    @endif

    @if((string) $syncStatus === 'failed' && !empty($lastError))
      <div class="mt-3 rounded-xl border border-rose-300/20 bg-rose-100 px-3 py-2 text-xs text-rose-900 break-words">
        {{ $lastError }}
      </div>
    @endif
  </div>

  @if(!empty($error))
    <div class="mt-2 rounded-xl border border-rose-300/20 bg-rose-100 px-3 py-2 text-xs text-rose-900">
      {{ $error }}
    </div>
  @endif
</div>
