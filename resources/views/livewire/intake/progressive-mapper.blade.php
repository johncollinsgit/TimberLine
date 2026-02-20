<div class="space-y-4">
  <div class="flex items-center justify-between text-[11px] text-emerald-100/60">
    <div>Exception context</div>
    <button type="button" wire:click="toggleAdvanced" class="px-2 py-1 rounded-full border border-white/10 bg-white/5 text-white/70">
      {{ $manualMode ? 'Progressive Mode' : 'Advanced Mode' }}
    </button>
  </div>

  <div class="rounded-2xl border border-emerald-200/10 bg-black/30 p-3 text-xs text-white/80">
    <div class="font-semibold">Raw Title / Variant</div>
    <div class="text-white/60">{{ $this->exceptionIds ? (\App\Models\MappingException::find($this->exceptionIds[0])->raw_title ?? '—') : '—' }}</div>
    <div class="text-white/40">{{ $this->exceptionIds ? (\App\Models\MappingException::find($this->exceptionIds[0])->raw_variant ?? '—') : '—' }}</div>
  </div>

  @if($step === 1 && !$manualMode)
    <div class="flex items-center justify-between text-sm text-emerald-50/70">
      <span>This order doesn’t look like a standard mapped product. What is this?</span>
      <button type="button" wire:click="toggleAdvanced" class="text-xs text-emerald-100/70 underline">Advanced mode</button>
    </div>
    <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
      <button type="button" wire:click="classify('standard')" class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-left text-white/90">Standard Candle</button>
      <button type="button" wire:click="classify('subscription')" class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-left text-white/90">Subscription Drop (Candle Club)</button>
      <button type="button" wire:click="classify('custom')" class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-left text-white/90">Custom Blend / Custom Scent</button>
      <button type="button" wire:click="classify('multi-pack')" class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-left text-white/90">Multi-Pack (Flight / Case / Bundle)</button>
      <button type="button" wire:click="classify('non-candle')" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-left text-white/70">Non‑Candle Product (Exclude)</button>
    </div>
  @elseif($step === 2 && !$manualMode)
    <div class="flex items-center justify-between text-sm text-emerald-50/70">
      <span>We think this might be:</span>
      <button type="button" wire:click="toggleAdvanced" class="text-xs text-emerald-100/70 underline">Advanced mode</button>
    </div>
    <div class="space-y-2">
      @foreach($guesses as $guess)
        <button type="button" wire:click="acceptGuess({{ $guess['id'] }})"
          class="w-full rounded-2xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-left text-white/90">
          {{ $guess['name'] }} <span class="text-xs text-emerald-100/60">({{ $guess['score'] }}% match)</span>
        </button>
      @endforeach
      <button type="button" wire:click="manualSearch"
        class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-left text-white/70">
        No, search manually
      </button>
    </div>
  @else
    @if($manualMode)
      <div class="flex items-center justify-between text-sm text-emerald-50/70">
        <span>Advanced mode</span>
        <button type="button" wire:click="toggleAdvanced" class="text-xs text-emerald-100/70 underline">Guided mode</button>
      </div>
    @endif

    @if($classification === 'subscription')
      <div class="text-xs text-emerald-100/60">Candle Club details</div>
      <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <select wire:model.defer="candleClubMonth" class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90">
          @for($m=1;$m<=12;$m++)
            <option value="{{ $m }}">{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
          @endfor
        </select>
        <input type="text" wire:model.defer="candleClubYear" placeholder="Year (YYYY)" class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90">
        <input type="text" wire:model.defer="candleClubScent" placeholder="Scent name" class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90">
        <input type="text" wire:model.defer="candleClubOil" placeholder="Oil reference name" class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90">
      </div>
      @if(!empty($relatedExceptionIds))
        <div class="mt-3 rounded-2xl border border-emerald-200/10 bg-black/20 p-3 text-xs text-white/80">
          <div class="font-semibold text-emerald-100/70">Apply to other Candle Club exceptions?</div>
          <label class="mt-2 flex items-center gap-2">
            <input type="checkbox" wire:model="applyAllRelated" class="rounded border-emerald-200/30 bg-black/30">
            Apply to all {{ count($relatedExceptionIds) }} matching Candle Club exceptions
          </label>
          @if(!$applyAllRelated)
            <div class="mt-2 grid gap-2">
              @foreach($relatedExceptionIds as $rid)
                <label class="flex items-center gap-2">
                  <input type="checkbox" wire:model="applyExceptionIds" value="{{ $rid }}" class="rounded border-emerald-200/30 bg-black/30">
                  Exception #{{ $rid }}
                </label>
              @endforeach
            </div>
          @endif
        </div>
      @endif
    @endif

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
      <div>
        <label class="text-xs text-emerald-100/60">Size</label>
        <select wire:model="sizeId" class="mt-1 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90">
          <option value="">Select size</option>
          @foreach($sizes as $size)
            <option value="{{ $size->id }}">{{ $size->label ?? $size->code }}</option>
          @endforeach
        </select>
      </div>
      @if($classification !== 'subscription' && empty($wickType))
        <div>
          <label class="text-xs text-emerald-100/60">Wick</label>
          <select wire:model="wickType" class="mt-1 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90">
            <option value="">Leave unchanged</option>
            <option value="cotton">Cotton</option>
            <option value="cedar">Cedar</option>
          </select>
        </div>
      @endif
    </div>

    <div class="flex justify-end gap-3">
      <button type="button" wire:click="save"
        class="rounded-full border border-emerald-400/40 bg-emerald-500/25 px-4 py-2 text-xs font-semibold text-white">
        Approve & Save Mapping
      </button>
    </div>
  @endif
</div>
