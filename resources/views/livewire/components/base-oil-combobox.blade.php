<div class="relative">
  <div class="flex items-center gap-2">
    <input
      type="text"
      wire:model.live.debounce.200ms="query"
      wire:focus="openDropdownIfSearching"
      wire:keydown.escape.prevent="closeDropdown"
      wire:keydown.enter.prevent="selectOnlyMatch"
      placeholder="{{ $placeholder }}"
      class="h-10 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-zinc-900 placeholder:text-zinc-500 focus:outline-none focus:ring-2 focus:ring-emerald-400/20"
      autocomplete="off"
    />
    @if($selectedId)
      <button
        type="button"
        wire:click="clear"
        class="h-10 w-10 rounded-xl border border-zinc-200 bg-zinc-50 text-zinc-600 hover:bg-zinc-100"
      >×</button>
    @endif
  </div>

  @if($showDropdown && count($options) > 0)
    <div class="absolute z-20 mt-2 w-full rounded-xl border border-zinc-200 bg-zinc-50 shadow-xl">
      <ul class="max-h-64 overflow-y-auto">
        @foreach($options as $option)
          <li>
            <button
              type="button"
              wire:click="select({{ $option->id }})"
              class="w-full px-3 py-2 text-left text-sm text-zinc-800 hover:bg-emerald-100"
            >
              {{ $option->name }}
              @if(isset($option->active) && !$option->active)
                <span class="ml-2 text-[10px] uppercase tracking-wide text-amber-200/80">Inactive</span>
              @endif
            </button>
          </li>
        @endforeach
      </ul>
    </div>
  @elseif($showDropdown && trim($query) !== '')
    <div class="absolute z-20 mt-2 w-full rounded-xl border border-amber-300/25 bg-zinc-50 px-3 py-2 text-xs text-amber-800 shadow-xl">
      No matching governed oil found. Select an existing oil or create one in Master Data.
    </div>
  @endif
</div>
