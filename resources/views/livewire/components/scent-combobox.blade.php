<div class="relative">
  <div class="flex items-center gap-2">
    <input
      type="text"
      wire:model.live.debounce.200ms="query"
      placeholder="{{ $placeholder }}"
      class="w-full h-10 rounded-xl border border-white/10 bg-white/5 px-3 text-white/90 placeholder:text-white/35
            focus:outline-none focus:ring-2 focus:ring-emerald-400/20"
      autocomplete="off"
    />
    @if($selectedId)
      <button type="button" wire:click="clear"
        class="h-10 w-10 rounded-xl border border-white/10 bg-white/5 text-white/70 hover:bg-white/10">×</button>
    @endif
  </div>

  @if(count($options) > 0)
    <div class="absolute z-20 mt-2 w-full rounded-xl border border-emerald-200/10 bg-[#0f1412] shadow-xl">
      <ul class="max-h-64 overflow-y-auto">
        @foreach($options as $option)
          <li>
            <button type="button" wire:click="select({{ $option->id }})"
              class="w-full px-3 py-2 text-left text-sm text-white/85 hover:bg-emerald-500/10">
              {{ $option->name }}
            </button>
          </li>
        @endforeach
      </ul>
    </div>
  @endif
</div>
