@php
  $context = $mappingContext ?? [];
  $isWholesale = (bool) ($context['is_wholesale'] ?? false);
  $isSubscriptionLike = (bool) ($context['is_subscription_like'] ?? false);
  $rawLabel = (string) ($context['raw_label'] ?? '—');
  $rawVariant = (string) ($context['raw_variant'] ?? '—');
  $accountName = (string) ($context['account_name'] ?? '');
  $batchCount = count($batchExceptionIds ?? []);
@endphp

<div class="space-y-4">
  <div class="rounded-2xl border border-emerald-300/20 bg-emerald-950/45 p-3">
    <div class="flex flex-wrap items-center justify-between gap-2 text-[11px] text-emerald-100/75">
      <div class="inline-flex items-center gap-2">
        <span class="font-semibold text-emerald-50/90">Resolve Mapping Wizard</span>
        @if($isWholesale)
          <span class="inline-flex items-center rounded-full border border-emerald-200/25 bg-emerald-500/15 px-2 py-0.5 text-[10px] text-emerald-50">Wholesale context</span>
        @endif
        @if($isSubscriptionLike)
          <span class="inline-flex items-center rounded-full border border-sky-200/25 bg-sky-500/15 px-2 py-0.5 text-[10px] text-sky-50">Recurring monthly pattern</span>
        @endif
      </div>
      <button type="button" wire:click="toggleAdvanced" class="rounded-full border border-emerald-200/20 bg-white/10 px-3 py-1 text-[11px] text-white/90 hover:bg-white/15">
        {{ $manualMode ? 'Guided Mode' : 'Advanced Mode' }}
      </button>
    </div>
  </div>

  <div class="rounded-2xl border border-emerald-300/20 bg-emerald-950/35 p-4 text-sm text-white/90">
    <div class="text-[11px] uppercase tracking-[0.25em] text-emerald-100/65">Exception Context</div>
    <div class="mt-2 font-semibold text-lg text-white">{{ $rawLabel !== '' ? $rawLabel : '—' }}</div>
    <div class="mt-1 text-sm text-emerald-50/70">{{ $rawVariant !== '' ? $rawVariant : 'No variant provided' }}</div>
    @if($accountName !== '')
      <div class="mt-2 text-xs text-emerald-100/75">Account: <span class="text-emerald-50">{{ $accountName }}</span></div>
    @endif
  </div>

  @if($step === 1 && !$manualMode)
    <div class="rounded-2xl border border-emerald-300/20 bg-emerald-950/35 p-4">
      <div class="text-sm text-emerald-50/85">What should this be mapped as?</div>
      <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
        @if($isWholesale)
          <button type="button" wire:click="classify('wholesale-custom-existing')" class="rounded-xl border border-emerald-300/30 bg-emerald-500/18 px-4 py-3 text-left text-white">
            Map to existing wholesale custom scent
          </button>
          <button type="button" wire:click="classify('wholesale-custom-blend-existing')" class="rounded-xl border border-emerald-300/30 bg-emerald-500/18 px-4 py-3 text-left text-white">
            Map to existing wholesale custom blend
          </button>
          <button type="button" wire:click="classify('wholesale-custom-new-scent')" class="rounded-xl border border-emerald-300/25 bg-emerald-500/10 px-4 py-3 text-left text-white/90">
            Create new wholesale custom scent
          </button>
          <button type="button" wire:click="classify('wholesale-custom-new-blend')" class="rounded-xl border border-emerald-300/25 bg-emerald-500/10 px-4 py-3 text-left text-white/90">
            Create new wholesale custom blend
          </button>
        @endif

        <button type="button" wire:click="classify('standard')" class="rounded-xl border border-emerald-300/25 bg-emerald-500/10 px-4 py-3 text-left text-white/90">Standard Candle</button>
        <button type="button" wire:click="classify('subscription')" class="rounded-xl border border-sky-300/25 bg-sky-500/10 px-4 py-3 text-left text-sky-50">Subscription Drop (Candle Club)</button>
        <button type="button" wire:click="classify('custom')" class="rounded-xl border border-emerald-300/25 bg-emerald-500/10 px-4 py-3 text-left text-white/90">Custom Blend / Custom Scent</button>
        <button type="button" wire:click="classify('multi-pack')" class="rounded-xl border border-emerald-300/25 bg-emerald-500/10 px-4 py-3 text-left text-white/90">Multi-Pack (Flight / Case / Bundle)</button>
        <button type="button" wire:click="classify('non-candle')" class="rounded-xl border border-white/15 bg-white/10 px-4 py-3 text-left text-white/80">Non‑Candle Product (Exclude)</button>
      </div>
    </div>
  @elseif($step === 2 && !$manualMode)
    <div class="rounded-2xl border border-emerald-300/20 bg-emerald-950/35 p-4">
      <div class="flex items-center justify-between gap-2 text-sm text-emerald-50/85">
        <span>Likely matches</span>
        <button type="button" wire:click="manualSearch" class="text-xs text-emerald-100/80 underline">Use advanced search instead</button>
      </div>

      <div class="mt-3 space-y-2">
        @forelse($guesses as $guess)
          <button type="button" wire:click="acceptGuess({{ (int) $guess['id'] }})"
            class="w-full rounded-xl border border-emerald-300/25 bg-emerald-500/12 px-4 py-3 text-left text-white hover:bg-emerald-500/18">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="font-semibold">{{ $guess['name'] }}</div>
              <div class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-full border border-emerald-200/25 bg-emerald-400/15 px-2 py-0.5 text-[10px] text-emerald-50">{{ $guess['mapping_type'] }}</span>
                <span class="text-xs text-emerald-100/70">{{ $guess['score'] }}%</span>
              </div>
            </div>
            @if(!empty($guess['reasons']))
              <div class="mt-1 text-xs text-emerald-100/70">Why: {{ implode(' · ', array_slice($guess['reasons'], 0, 3)) }}</div>
            @endif
          </button>
        @empty
          <div class="rounded-xl border border-white/15 bg-white/10 px-4 py-3 text-sm text-white/70">
            No high-confidence match found. Use advanced search.
          </div>
        @endforelse
      </div>
    </div>
  @else
    @if($manualMode)
      <div class="flex items-center justify-between rounded-2xl border border-emerald-300/20 bg-emerald-950/35 px-4 py-3 text-sm text-emerald-50/85">
        <span>Advanced mode</span>
        <button type="button" wire:click="toggleAdvanced" class="text-xs text-emerald-100/80 underline">Back to guided mode</button>
      </div>
    @endif

    @if($classification === 'subscription')
      <div class="rounded-2xl border border-sky-300/25 bg-sky-950/35 p-4">
        <div class="text-xs uppercase tracking-[0.25em] text-sky-100/70">Subscription Drop Details</div>
        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
          <select wire:model.defer="candleClubMonth" class="rounded-xl border border-sky-200/20 bg-black/25 px-3 py-2 text-sm text-white/90">
            @for($m=1;$m<=12;$m++)
              <option value="{{ $m }}">{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
            @endfor
          </select>
          <input type="text" wire:model.defer="candleClubYear" placeholder="Year (YYYY)" class="rounded-xl border border-sky-200/20 bg-black/25 px-3 py-2 text-sm text-white/90">
          <input type="text" wire:model.defer="candleClubScent" placeholder="Scent name" class="rounded-xl border border-sky-200/20 bg-black/25 px-3 py-2 text-sm text-white/90">
          <input type="text" wire:model.defer="candleClubOil" placeholder="Oil reference name" class="rounded-xl border border-sky-200/20 bg-black/25 px-3 py-2 text-sm text-white/90">
        </div>

        @if(!empty($relatedExceptionIds))
          <div class="mt-3 rounded-xl border border-sky-200/20 bg-black/25 p-3 text-xs text-white/80">
            <div class="font-semibold text-sky-100/80">Apply to matching Candle Club exceptions</div>
            <label class="mt-2 flex items-center gap-2">
              <input type="checkbox" wire:model="applyAllRelated" class="rounded border-sky-200/30 bg-black/30">
              Apply to all {{ count($relatedExceptionIds) }} related Candle Club exceptions
            </label>
            @if(!$applyAllRelated)
              <div class="mt-2 grid gap-2">
                @foreach($relatedExceptionIds as $rid)
                  <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="applyExceptionIds" value="{{ $rid }}" class="rounded border-sky-200/30 bg-black/30">
                    Exception #{{ $rid }}
                  </label>
                @endforeach
              </div>
            @endif
          </div>
        @endif
      </div>
    @else
      <div class="rounded-2xl border border-emerald-300/20 bg-emerald-950/35 p-4 space-y-3">
        <div>
          <label class="text-xs text-emerald-100/70">Search existing scents (canonical + wholesale custom + aliases)</label>
          <input
            type="text"
            wire:model.live.debounce.250ms="existingScentSearch"
            placeholder="Search name, custom scent, alias, abbreviation, or oil reference"
            class="mt-1 w-full rounded-xl border border-emerald-200/20 bg-black/25 px-3 py-2 text-sm text-white/90"
          >
        </div>

        @if($matchingScents->isNotEmpty())
          <div class="grid gap-2">
            @foreach($matchingScents as $candidate)
              <button
                type="button"
                wire:click="$set('selectedScentId', {{ (int) $candidate['id'] }})"
                class="rounded-xl border px-3 py-2 text-left text-sm transition {{ (int) $selectedScentId === (int) $candidate['id'] ? 'border-emerald-300/45 bg-emerald-500/18 text-emerald-50' : 'border-white/15 bg-white/10 text-white/85 hover:border-emerald-300/25 hover:bg-white/15' }}"
              >
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <span class="font-semibold">{{ $candidate['name'] }}</span>
                  <div class="flex items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-emerald-200/25 bg-emerald-400/15 px-2 py-0.5 text-[10px] text-emerald-50">{{ $candidate['mapping_type'] }}</span>
                    <span class="text-[11px] text-emerald-100/75">{{ $candidate['score'] }}%</span>
                  </div>
                </div>
                @if(!empty($candidate['reasons']))
                  <div class="mt-1 text-[11px] text-emerald-100/70">Why: {{ implode(' · ', array_slice($candidate['reasons'], 0, 3)) }}</div>
                @endif
              </button>
            @endforeach
          </div>
        @endif
      </div>

      <div class="rounded-2xl border border-emerald-300/20 bg-emerald-950/35 p-4">
        <div class="text-xs uppercase tracking-[0.28em] text-emerald-100/65">
          @if($isWholesale)
            Or create a wholesale custom scent record
          @else
            Or create a canonical scent
          @endif
        </div>
        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
          <input type="text" wire:model.defer="newScentName" placeholder="Canonical name" class="rounded-xl border border-emerald-200/20 bg-black/25 px-3 py-2 text-sm text-white/90">
          <input type="text" wire:model.defer="newScentDisplay" placeholder="Display name" class="rounded-xl border border-emerald-200/20 bg-black/25 px-3 py-2 text-sm text-white/90">
          <input type="text" wire:model.defer="newScentAbbr" placeholder="Abbreviation" class="rounded-xl border border-emerald-200/20 bg-black/25 px-3 py-2 text-sm text-white/90">
          <input type="text" wire:model.defer="newScentOil" placeholder="Oil reference name" class="rounded-xl border border-emerald-200/20 bg-black/25 px-3 py-2 text-sm text-white/90">
          <label class="inline-flex items-center gap-2 text-xs text-emerald-50/80">
            <input type="checkbox" wire:model="newScentIsBlend" class="rounded border-emerald-200/30 bg-black/30">
            Treat as blend
          </label>
          @if($newScentIsBlend)
            <input type="number" min="1" wire:model.defer="newScentBlendCount" placeholder="Blend oil count" class="rounded-xl border border-emerald-200/20 bg-black/25 px-3 py-2 text-sm text-white/90">
          @endif
        </div>
      </div>
    @endif

    <div class="rounded-2xl border border-emerald-300/20 bg-emerald-950/35 p-4">
      <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <div>
          <label class="text-xs text-emerald-100/70">Size</label>
          <select wire:model="sizeId" class="mt-1 w-full rounded-xl border border-emerald-200/20 bg-black/25 px-3 py-2 text-sm text-white/90">
            <option value="">Select size</option>
            @foreach($sizes as $size)
              <option value="{{ $size->id }}">{{ $size->label ?? $size->code }}</option>
            @endforeach
          </select>
        </div>
        @if($classification !== 'subscription' && empty($wickType))
          <div>
            <label class="text-xs text-emerald-100/70">Wick</label>
            <select wire:model="wickType" class="mt-1 w-full rounded-xl border border-emerald-200/20 bg-black/25 px-3 py-2 text-sm text-white/90">
              <option value="">Leave unchanged</option>
              <option value="cotton">Cotton</option>
              <option value="cedar">Cedar</option>
            </select>
          </div>
        @endif
      </div>
    </div>

    @if($batchCount > 0 || $isSubscriptionLike)
      <div class="rounded-2xl border border-rose-300/30 bg-rose-950/25 p-4">
        <div class="text-xs uppercase tracking-[0.25em] text-rose-100/80">Batch Mapping</div>
        <div class="mt-2 text-sm text-rose-50/90">
          {{ $batchCount }} unresolved item{{ $batchCount === 1 ? '' : 's' }} share this incoming label.
        </div>

        <div class="mt-3 space-y-3">
          @if($batchCount > 0)
            <label class="flex items-center gap-2 text-sm text-rose-50/90">
              <input type="checkbox" wire:model="batchApplyRemaining" class="rounded border-rose-200/35 bg-black/30">
              Apply to remaining items in this import queue now
            </label>
          @endif

          <div>
            <label class="text-xs text-rose-100/80">Create reusable rule</label>
            <select wire:model="batchScope" class="mt-1 w-full rounded-xl border border-rose-200/35 bg-black/25 px-3 py-2 text-sm text-white/90">
              @foreach($batchScopeOptions as $option)
                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
              @endforeach
            </select>
          </div>
        </div>
      </div>
    @endif

    <div class="flex justify-end">
      <button type="button" wire:click="save"
        class="rounded-full border border-emerald-300/50 bg-emerald-500/30 px-5 py-2 text-sm font-semibold text-white shadow-[0_10px_30px_-12px_rgba(16,185,129,.55)] hover:bg-emerald-500/40">
        Approve & Save Mapping
      </button>
    </div>
  @endif
</div>
