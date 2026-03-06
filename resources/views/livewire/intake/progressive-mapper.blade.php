@php
  $context = $mappingContext ?? [];
  $rawLabel = trim((string) ($context['raw_label'] ?? ''));
  $rawVariant = trim((string) ($context['raw_variant'] ?? ''));
  $accountName = trim((string) ($context['account_name'] ?? ''));
  $isWholesale = (bool) ($context['is_wholesale'] ?? false);
  $sameCount = count($sameNameExceptionIds ?? []);
@endphp

<div class="space-y-4">
  <div class="rounded-2xl border border-emerald-300/20 bg-emerald-950/35 p-4">
    <div class="text-xs uppercase tracking-[0.28em] text-emerald-100/70">Resolve Mapping</div>
    <div class="mt-2 text-lg font-semibold text-white">{{ $rawLabel !== '' ? $rawLabel : 'Unnamed incoming scent' }}</div>
    <div class="mt-1 text-sm text-emerald-50/75">{{ $rawVariant !== '' ? $rawVariant : 'No variant details provided' }}</div>
    @if($isWholesale)
      <div class="mt-2 inline-flex items-center rounded-full border border-emerald-200/25 bg-emerald-500/15 px-2 py-0.5 text-[11px] text-emerald-50">
        Wholesale context{{ $accountName !== '' ? ' · '.$accountName : '' }}
      </div>
    @endif
  </div>

  <div class="rounded-2xl border border-emerald-300/20 bg-emerald-950/35 p-4 space-y-3">
    <div>
      <label class="text-xs text-emerald-100/70">Search scent</label>
      <input
        type="text"
        wire:model.live.debounce.250ms="existingScentSearch"
        placeholder="Type a scent name, wholesale custom name, alias, or abbreviation"
        class="mt-1 w-full rounded-xl border border-emerald-200/20 bg-black/25 px-3 py-2 text-sm text-white/90"
      >
    </div>

    @if($matchingScents->isNotEmpty())
      <div class="space-y-2">
        @foreach($matchingScents as $candidate)
          <label class="flex cursor-pointer items-start gap-3 rounded-xl border px-3 py-2 transition {{ (int) $selectedScentId === (int) $candidate['id'] ? 'border-emerald-300/45 bg-emerald-500/16' : 'border-white/15 bg-white/10 hover:border-emerald-300/25 hover:bg-white/15' }}">
            <input
              type="radio"
              name="selected-scent"
              value="{{ (int) $candidate['id'] }}"
              wire:model.live="selectedScentId"
              class="mt-1 border-emerald-200/40 bg-black/30 text-emerald-400"
            >
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="truncate font-semibold text-white">{{ $candidate['name'] }}</div>
                <div class="flex items-center gap-2">
                  <span class="inline-flex items-center rounded-full border border-emerald-200/25 bg-emerald-400/12 px-2 py-0.5 text-[10px] text-emerald-50">{{ $candidate['mapping_type'] }}</span>
                  <span class="text-[11px] text-emerald-100/70">{{ $candidate['score'] }}%</span>
                </div>
              </div>
              <div class="mt-1 text-[11px] text-emerald-100/70">{{ $candidate['why'] }}</div>
            </div>
          </label>
        @endforeach
      </div>
    @else
      <div class="rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-sm text-white/75">
        No scent matches found for this search.
      </div>
    @endif
  </div>

  @if($sameCount > 0)
    <div class="rounded-2xl border border-amber-300/30 bg-amber-950/25 p-4">
      <label class="flex items-start gap-2 text-sm text-amber-50/90">
        <input type="checkbox" wire:model.live="applySameName" class="mt-1 rounded border-amber-300/35 bg-black/25">
        <span>
          Also map {{ $sameCount }} other unresolved {{ $sameCount === 1 ? 'item' : 'items' }} with this same incoming name
          @if($accountName !== '')
            for this account
          @endif
        </span>
      </label>
    </div>
  @endif

  <div class="flex justify-end">
    <button
      type="button"
      wire:click="save"
      class="rounded-full border border-emerald-300/50 bg-emerald-500/30 px-5 py-2 text-sm font-semibold text-white shadow-[0_10px_28px_-12px_rgba(16,185,129,.55)] hover:bg-emerald-500/40"
    >
      Map Selected Scent
    </button>
  </div>
</div>
