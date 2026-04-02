<section class="mf-app-card rounded-3xl border border-zinc-200 p-5">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div>
      <div class="text-lg font-semibold text-zinc-950">Scents</div>
      <div class="text-sm text-zinc-600">Catalog-style scent view. New scents should be created through the wizard.</div>
    </div>
    <div class="flex items-center gap-2">
      <a href="{{ $wizardUrl }}" wire:navigate class="rounded-full border border-emerald-400/40 bg-emerald-100 px-4 py-2 text-xs font-semibold text-zinc-950">
        New Scent Wizard
      </a>
    </div>
  </div>

  <div class="mt-4 rounded-2xl border border-emerald-300/20 bg-emerald-100 px-4 py-3 text-xs text-zinc-600">
    Governance: edit existing records here. New canonical scents route through the wizard.
  </div>

  <div class="mt-4 flex flex-wrap items-center gap-2">
    <flux:input wire:model.live="search" placeholder="Search scents..." />
    <div class="flex items-center gap-2 rounded-full border border-zinc-200 bg-zinc-50 px-3 py-2">
      <span class="text-[11px] uppercase tracking-wide text-zinc-500">Rows</span>
      <select wire:model.live="perPage" class="bg-transparent text-xs text-zinc-700 focus:outline-none">
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  </div>

  <div class="mt-4 flex items-center justify-between text-[11px] text-emerald-800">
    <div>Double-click any editable cell. Enter saves, Tab saves + moves, Escape cancels.</div>
    <div class="hidden sm:block">Inline edits auto-save on blur when valid.</div>
  </div>

  <datalist id="catalog-inline-canonical-list">
    @foreach($canonicalScents as $option)
      @php $canonicalOptionLabel = $option->display_name ?: $option->name; @endphp
      <option value="{{ $option->id }}::{{ $canonicalOptionLabel }}">{{ $canonicalOptionLabel }}</option>
    @endforeach
  </datalist>
  <datalist id="catalog-inline-wholesale-list">
    @foreach($wholesaleSources as $source)
      <option value="{{ $source->id }}::{{ $source->custom_scent_name }} · {{ $source->account_name }}">
        {{ $source->custom_scent_name }} · {{ $source->account_name }}
      </option>
    @endforeach
  </datalist>
  <datalist id="catalog-inline-blend-list">
    @foreach($blends as $blend)
      <option value="{{ $blend->id }}::{{ $blend->name }}">{{ $blend->name }}</option>
    @endforeach
  </datalist>
  <datalist id="catalog-inline-oil-ref-list">
    @foreach($baseOils as $oil)
      <option value="{{ $oil->name }}">{{ $oil->name }}</option>
    @endforeach
  </datalist>

  <div class="mt-3 overflow-hidden rounded-2xl border border-zinc-200 bg-white">
    <div class="overflow-x-auto">
      <table class="min-w-[112rem] w-full table-fixed text-sm">
        <colgroup>
          <col class="w-[14rem]">
          <col class="w-[14rem]">
          <col class="w-[8rem]">
          <col class="w-[16rem]">
          <col class="w-[14rem]">
          <col class="w-[15rem]">
          <col class="w-[8rem]">
          <col class="w-[13rem]">
          <col class="w-[8rem]">
          <col class="w-[8rem]">
          <col class="w-[10rem]">
        </colgroup>
        <thead class="bg-zinc-100 text-zinc-700">
          <tr>
            <th class="cursor-pointer whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]" wire:click="setSort('name')">Name</th>
            <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]">Display</th>
            <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]" title="Abbreviation">Abbrev</th>
            <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]">Oil Ref</th>
            <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]">Canonical</th>
            <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]">Wholesale Source</th>
            <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]">Blend</th>
            <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]">Blend Type</th>
            <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]">Oil Count</th>
            <th class="whitespace-nowrap px-3 py-2.5 text-left text-[11px] uppercase tracking-[0.2em]">Active</th>
            <th class="whitespace-nowrap px-3 py-2.5 text-right text-[11px] uppercase tracking-[0.2em]">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200">
          @foreach($scents as $scent)
            <tr class="hover:bg-zinc-50">
              <td class="px-3 py-2 align-top">
                @php
                  $field = 'name';
                  $cellKey = $scent->id . ':' . $field;
                  $isEditing = $inlineRowId === $scent->id && $inlineField === $field;
                  $isFocused = $focusedRowId === $scent->id && $focusedField === $field;
                  $cellError = $inlineErrors[$cellKey] ?? null;
                @endphp
                <div
                  wire:click="focusInlineCell({{ $scent->id }}, '{{ $field }}')"
                  wire:dblclick="startInlineEdit({{ $scent->id }}, '{{ $field }}')"
                  tabindex="0"
                  class="rounded-lg border px-2 py-1.5 transition {{ $cellError ? 'border-red-400/50 bg-red-500/10' : ($isEditing ? 'border-emerald-300/50 bg-emerald-100' : ($isFocused ? 'border-emerald-300/30 bg-zinc-100' : 'border-transparent bg-transparent hover:border-zinc-200 hover:bg-zinc-50')) }}"
                >
                  @if($isEditing)
                    <input
                      type="text"
                      wire:model.live.debounce.150ms="inlineValue"
                      wire:keydown.enter.prevent="commitInlineEdit('stay')"
                      wire:keydown.tab.prevent="commitInlineEdit('next')"
                      wire:keydown.shift.tab.prevent="commitInlineEdit('prev')"
                      wire:keydown.escape.prevent="cancelInlineEdit"
                      wire:blur="commitInlineEdit('stay')"
                      autofocus
                      class="h-8 w-full rounded-md border border-emerald-300/40 bg-zinc-50 px-2 text-sm text-zinc-950 focus:border-emerald-200 focus:outline-none"
                    />
                  @else
                    <div class="truncate text-[14px] font-medium text-zinc-950">{{ $scent->name }}</div>
                  @endif
                </div>
                @if($inlineSaving[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-zinc-500">Saving...</div>
                @elseif($cellError)
                  <div class="mt-1 text-[11px] text-red-300">{{ $cellError }}</div>
                @elseif($inlineSaved[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-emerald-200/80">Saved</div>
                @endif
              </td>

              <td class="px-3 py-2 align-top">
                @php
                  $field = 'display_name';
                  $cellKey = $scent->id . ':' . $field;
                  $isEditing = $inlineRowId === $scent->id && $inlineField === $field;
                  $isFocused = $focusedRowId === $scent->id && $focusedField === $field;
                  $cellError = $inlineErrors[$cellKey] ?? null;
                @endphp
                <div
                  wire:click="focusInlineCell({{ $scent->id }}, '{{ $field }}')"
                  wire:dblclick="startInlineEdit({{ $scent->id }}, '{{ $field }}')"
                  tabindex="0"
                  class="rounded-lg border px-2 py-1.5 transition {{ $cellError ? 'border-red-400/50 bg-red-500/10' : ($isEditing ? 'border-emerald-300/50 bg-emerald-100' : ($isFocused ? 'border-emerald-300/30 bg-zinc-100' : 'border-transparent bg-transparent hover:border-zinc-200 hover:bg-zinc-50')) }}"
                >
                  @if($isEditing)
                    <input
                      type="text"
                      wire:model.live.debounce.150ms="inlineValue"
                      wire:keydown.enter.prevent="commitInlineEdit('stay')"
                      wire:keydown.tab.prevent="commitInlineEdit('next')"
                      wire:keydown.shift.tab.prevent="commitInlineEdit('prev')"
                      wire:keydown.escape.prevent="cancelInlineEdit"
                      wire:blur="commitInlineEdit('stay')"
                      autofocus
                      class="h-8 w-full rounded-md border border-emerald-300/40 bg-zinc-50 px-2 text-sm text-zinc-950 focus:border-emerald-200 focus:outline-none"
                    />
                  @else
                    <div class="truncate text-sm text-zinc-700">{{ $scent->display_name ?: '—' }}</div>
                  @endif
                </div>
                @if($inlineSaving[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-zinc-500">Saving...</div>
                @elseif($cellError)
                  <div class="mt-1 text-[11px] text-red-300">{{ $cellError }}</div>
                @elseif($inlineSaved[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-emerald-200/80">Saved</div>
                @endif
              </td>

              <td class="px-3 py-2 align-top">
                @php
                  $field = 'abbreviation';
                  $cellKey = $scent->id . ':' . $field;
                  $isEditing = $inlineRowId === $scent->id && $inlineField === $field;
                  $isFocused = $focusedRowId === $scent->id && $focusedField === $field;
                  $cellError = $inlineErrors[$cellKey] ?? null;
                @endphp
                <div
                  wire:click="focusInlineCell({{ $scent->id }}, '{{ $field }}')"
                  wire:dblclick="startInlineEdit({{ $scent->id }}, '{{ $field }}')"
                  tabindex="0"
                  class="rounded-lg border px-2 py-1.5 transition {{ $cellError ? 'border-red-400/50 bg-red-500/10' : ($isEditing ? 'border-emerald-300/50 bg-emerald-100' : ($isFocused ? 'border-emerald-300/30 bg-zinc-100' : 'border-transparent bg-transparent hover:border-zinc-200 hover:bg-zinc-50')) }}"
                >
                  @if($isEditing)
                    <input
                      type="text"
                      wire:model.live.debounce.150ms="inlineValue"
                      wire:keydown.enter.prevent="commitInlineEdit('stay')"
                      wire:keydown.tab.prevent="commitInlineEdit('next')"
                      wire:keydown.shift.tab.prevent="commitInlineEdit('prev')"
                      wire:keydown.escape.prevent="cancelInlineEdit"
                      wire:blur="commitInlineEdit('stay')"
                      autofocus
                      class="h-8 w-full rounded-md border border-emerald-300/40 bg-zinc-50 px-2 text-sm text-zinc-950 focus:border-emerald-200 focus:outline-none"
                    />
                  @else
                    <span class="whitespace-nowrap text-sm text-zinc-800">{{ $scent->abbreviation ?: '—' }}</span>
                  @endif
                </div>
                @if($inlineSaving[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-zinc-500">Saving...</div>
                @elseif($cellError)
                  <div class="mt-1 text-[11px] text-red-300">{{ $cellError }}</div>
                @elseif($inlineSaved[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-emerald-200/80">Saved</div>
                @endif
              </td>

              <td class="px-3 py-2 align-top">
                @php
                  $field = 'oil_reference_name';
                  $cellKey = $scent->id . ':' . $field;
                  $isEditing = $inlineRowId === $scent->id && $inlineField === $field;
                  $isFocused = $focusedRowId === $scent->id && $focusedField === $field;
                  $cellError = $inlineErrors[$cellKey] ?? null;
                @endphp
                <div
                  wire:click="focusInlineCell({{ $scent->id }}, '{{ $field }}')"
                  wire:dblclick="startInlineEdit({{ $scent->id }}, '{{ $field }}')"
                  tabindex="0"
                  class="rounded-lg border px-2 py-1.5 transition {{ $cellError ? 'border-red-400/50 bg-red-500/10' : ($isEditing ? 'border-emerald-300/50 bg-emerald-100' : ($isFocused ? 'border-emerald-300/30 bg-zinc-100' : 'border-transparent bg-transparent hover:border-zinc-200 hover:bg-zinc-50')) }}"
                >
                  @if($isEditing)
                    <input
                      type="text"
                      list="catalog-inline-oil-ref-list"
                      wire:model.live.debounce.150ms="inlineValue"
                      wire:keydown.enter.prevent="commitInlineEdit('stay')"
                      wire:keydown.tab.prevent="commitInlineEdit('next')"
                      wire:keydown.shift.tab.prevent="commitInlineEdit('prev')"
                      wire:keydown.escape.prevent="cancelInlineEdit"
                      wire:blur="commitInlineEdit('stay')"
                      autofocus
                      placeholder="Search oil..."
                      class="h-8 w-full rounded-md border border-emerald-300/40 bg-zinc-50 px-2 text-sm text-zinc-950 focus:border-emerald-200 focus:outline-none"
                    />
                  @else
                    <span class="block truncate text-sm text-zinc-700">{{ $scent->oil_reference_name ?: '—' }}</span>
                  @endif
                </div>
                @if($inlineSaving[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-zinc-500">Saving...</div>
                @elseif($cellError)
                  <div class="mt-1 text-[11px] text-red-300">{{ $cellError }}</div>
                @elseif($inlineSaved[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-emerald-200/80">Saved</div>
                @endif
              </td>

              <td class="px-3 py-2 align-top">
                @php
                  $field = 'canonical_scent_id';
                  $cellKey = $scent->id . ':' . $field;
                  $isEditing = $inlineRowId === $scent->id && $inlineField === $field;
                  $isFocused = $focusedRowId === $scent->id && $focusedField === $field;
                  $cellError = $inlineErrors[$cellKey] ?? null;
                  $canonicalLabel = $scent->canonicalScent ? ($scent->canonicalScent->display_name ?: $scent->canonicalScent->name) : '—';
                @endphp
                <div
                  wire:click="focusInlineCell({{ $scent->id }}, '{{ $field }}')"
                  wire:dblclick="startInlineEdit({{ $scent->id }}, '{{ $field }}')"
                  tabindex="0"
                  class="rounded-lg border px-2 py-1.5 transition {{ $cellError ? 'border-red-400/50 bg-red-500/10' : ($isEditing ? 'border-emerald-300/50 bg-emerald-100' : ($isFocused ? 'border-emerald-300/30 bg-zinc-100' : 'border-transparent bg-transparent hover:border-zinc-200 hover:bg-zinc-50')) }}"
                >
                  @if($isEditing)
                    <input
                      type="text"
                      list="catalog-inline-canonical-list"
                      wire:model.live.debounce.150ms="inlineValue"
                      wire:keydown.enter.prevent="commitInlineEdit('stay')"
                      wire:keydown.tab.prevent="commitInlineEdit('next')"
                      wire:keydown.shift.tab.prevent="commitInlineEdit('prev')"
                      wire:keydown.escape.prevent="cancelInlineEdit"
                      wire:blur="commitInlineEdit('stay')"
                      autofocus
                      placeholder="Search canonical..."
                      class="h-8 w-full rounded-md border border-emerald-300/40 bg-zinc-50 px-2 text-sm text-zinc-950 focus:border-emerald-200 focus:outline-none"
                    />
                  @else
                    <span class="block truncate text-sm text-zinc-700" title="{{ $canonicalLabel }}">{{ $canonicalLabel }}</span>
                  @endif
                </div>
                @if($inlineSaving[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-zinc-500">Saving...</div>
                @elseif($cellError)
                  <div class="mt-1 text-[11px] text-red-300">{{ $cellError }}</div>
                @elseif($inlineSaved[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-emerald-200/80">Saved</div>
                @endif
              </td>

              <td class="px-3 py-2 align-top">
                @php
                  $field = 'source_wholesale_custom_scent_id';
                  $cellKey = $scent->id . ':' . $field;
                  $isEditing = $inlineRowId === $scent->id && $inlineField === $field;
                  $isFocused = $focusedRowId === $scent->id && $focusedField === $field;
                  $cellError = $inlineErrors[$cellKey] ?? null;
                  $wholesaleLabel = $scent->sourceWholesaleCustomScent
                    ? ($scent->sourceWholesaleCustomScent->custom_scent_name . ' · ' . $scent->sourceWholesaleCustomScent->account_name)
                    : '—';
                @endphp
                <div
                  wire:click="focusInlineCell({{ $scent->id }}, '{{ $field }}')"
                  wire:dblclick="startInlineEdit({{ $scent->id }}, '{{ $field }}')"
                  tabindex="0"
                  class="rounded-lg border px-2 py-1.5 transition {{ $cellError ? 'border-red-400/50 bg-red-500/10' : ($isEditing ? 'border-emerald-300/50 bg-emerald-100' : ($isFocused ? 'border-emerald-300/30 bg-zinc-100' : 'border-transparent bg-transparent hover:border-zinc-200 hover:bg-zinc-50')) }}"
                >
                  @if($isEditing)
                    <input
                      type="text"
                      list="catalog-inline-wholesale-list"
                      wire:model.live.debounce.150ms="inlineValue"
                      wire:keydown.enter.prevent="commitInlineEdit('stay')"
                      wire:keydown.tab.prevent="commitInlineEdit('next')"
                      wire:keydown.shift.tab.prevent="commitInlineEdit('prev')"
                      wire:keydown.escape.prevent="cancelInlineEdit"
                      wire:blur="commitInlineEdit('stay')"
                      autofocus
                      placeholder="Search wholesale..."
                      class="h-8 w-full rounded-md border border-emerald-300/40 bg-zinc-50 px-2 text-sm text-zinc-950 focus:border-emerald-200 focus:outline-none"
                    />
                  @else
                    <span class="block truncate text-sm text-zinc-700" title="{{ $wholesaleLabel }}">{{ $wholesaleLabel }}</span>
                  @endif
                </div>
                @if($inlineSaving[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-zinc-500">Saving...</div>
                @elseif($cellError)
                  <div class="mt-1 text-[11px] text-red-300">{{ $cellError }}</div>
                @elseif($inlineSaved[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-emerald-200/80">Saved</div>
                @endif
              </td>

              <td class="px-3 py-2 align-top">
                @php
                  $field = 'is_blend';
                  $cellKey = $scent->id . ':' . $field;
                  $isEditing = $inlineRowId === $scent->id && $inlineField === $field;
                  $isFocused = $focusedRowId === $scent->id && $focusedField === $field;
                  $cellError = $inlineErrors[$cellKey] ?? null;
                @endphp
                <div
                  wire:click="focusInlineCell({{ $scent->id }}, '{{ $field }}')"
                  wire:dblclick="startInlineEdit({{ $scent->id }}, '{{ $field }}')"
                  tabindex="0"
                  class="rounded-lg border px-2 py-1.5 transition {{ $cellError ? 'border-red-400/50 bg-red-500/10' : ($isEditing ? 'border-emerald-300/50 bg-emerald-100' : ($isFocused ? 'border-emerald-300/30 bg-zinc-100' : 'border-transparent bg-transparent hover:border-zinc-200 hover:bg-zinc-50')) }}"
                >
                  @if($isEditing)
                    <select
                      wire:model.live="inlineValue"
                      wire:keydown.enter.prevent="commitInlineEdit('stay')"
                      wire:keydown.tab.prevent="commitInlineEdit('next')"
                      wire:keydown.shift.tab.prevent="commitInlineEdit('prev')"
                      wire:keydown.escape.prevent="cancelInlineEdit"
                      wire:blur="commitInlineEdit('stay')"
                      autofocus
                      class="h-8 w-full rounded-md border border-emerald-300/40 bg-zinc-50 px-2 text-sm text-zinc-950 focus:border-emerald-200 focus:outline-none"
                    >
                      <option value="0">Single</option>
                      <option value="1">Blend</option>
                    </select>
                  @else
                    @if($scent->is_blend)
                      <span class="inline-flex h-6 items-center whitespace-nowrap rounded-md border border-amber-300/35 bg-amber-100 px-2 text-[11px] font-medium text-amber-900">Blend</span>
                    @else
                      <span class="inline-flex h-6 items-center whitespace-nowrap rounded-md border border-zinc-300 bg-zinc-50 px-2 text-[11px] font-medium text-zinc-700">Single</span>
                    @endif
                  @endif
                </div>
                @if($inlineSaving[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-zinc-500">Saving...</div>
                @elseif($cellError)
                  <div class="mt-1 text-[11px] text-red-300">{{ $cellError }}</div>
                @elseif($inlineSaved[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-emerald-200/80">Saved</div>
                @endif
              </td>

              <td class="px-3 py-2 align-top">
                @php
                  $field = 'oil_blend_id';
                  $cellKey = $scent->id . ':' . $field;
                  $isEditing = $inlineRowId === $scent->id && $inlineField === $field;
                  $isFocused = $focusedRowId === $scent->id && $focusedField === $field;
                  $cellError = $inlineErrors[$cellKey] ?? null;
                  $blendLabel = $scent->oilBlend?->name ?: '—';
                @endphp
                <div
                  wire:click="focusInlineCell({{ $scent->id }}, '{{ $field }}')"
                  wire:dblclick="startInlineEdit({{ $scent->id }}, '{{ $field }}')"
                  tabindex="0"
                  class="rounded-lg border px-2 py-1.5 transition {{ $cellError ? 'border-red-400/50 bg-red-500/10' : ($isEditing ? 'border-emerald-300/50 bg-emerald-100' : ($isFocused ? 'border-emerald-300/30 bg-zinc-100' : 'border-transparent bg-transparent hover:border-zinc-200 hover:bg-zinc-50')) }}"
                >
                  @if($isEditing)
                    <input
                      type="text"
                      list="catalog-inline-blend-list"
                      wire:model.live.debounce.150ms="inlineValue"
                      wire:keydown.enter.prevent="commitInlineEdit('stay')"
                      wire:keydown.tab.prevent="commitInlineEdit('next')"
                      wire:keydown.shift.tab.prevent="commitInlineEdit('prev')"
                      wire:keydown.escape.prevent="cancelInlineEdit"
                      wire:blur="commitInlineEdit('stay')"
                      autofocus
                      placeholder="Search blend..."
                      class="h-8 w-full rounded-md border border-emerald-300/40 bg-zinc-50 px-2 text-sm text-zinc-950 focus:border-emerald-200 focus:outline-none"
                    />
                  @else
                    <span class="block truncate text-sm {{ $scent->is_blend ? 'text-zinc-800' : 'text-zinc-500' }}" title="{{ $blendLabel }}">{{ $blendLabel }}</span>
                  @endif
                </div>
                @if($inlineSaving[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-zinc-500">Saving...</div>
                @elseif($cellError)
                  <div class="mt-1 text-[11px] text-red-300">{{ $cellError }}</div>
                @elseif($inlineSaved[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-emerald-200/80">Saved</div>
                @endif
              </td>

              <td class="px-3 py-2 align-top">
                @php
                  $field = 'blend_oil_count';
                  $cellKey = $scent->id . ':' . $field;
                  $isEditing = $inlineRowId === $scent->id && $inlineField === $field;
                  $isFocused = $focusedRowId === $scent->id && $focusedField === $field;
                  $cellError = $inlineErrors[$cellKey] ?? null;
                @endphp
                <div
                  wire:click="focusInlineCell({{ $scent->id }}, '{{ $field }}')"
                  wire:dblclick="startInlineEdit({{ $scent->id }}, '{{ $field }}')"
                  tabindex="0"
                  class="rounded-lg border px-2 py-1.5 transition {{ $cellError ? 'border-red-400/50 bg-red-500/10' : ($isEditing ? 'border-emerald-300/50 bg-emerald-100' : ($isFocused ? 'border-emerald-300/30 bg-zinc-100' : 'border-transparent bg-transparent hover:border-zinc-200 hover:bg-zinc-50')) }}"
                >
                  @if($isEditing)
                    <input
                      type="number"
                      min="1"
                      step="1"
                      wire:model.live.debounce.150ms="inlineValue"
                      wire:keydown.enter.prevent="commitInlineEdit('stay')"
                      wire:keydown.tab.prevent="commitInlineEdit('next')"
                      wire:keydown.shift.tab.prevent="commitInlineEdit('prev')"
                      wire:keydown.escape.prevent="cancelInlineEdit"
                      wire:blur="commitInlineEdit('stay')"
                      autofocus
                      class="h-8 w-full rounded-md border border-emerald-300/40 bg-zinc-50 px-2 text-sm text-zinc-950 focus:border-emerald-200 focus:outline-none"
                    />
                  @else
                    <span class="text-sm text-zinc-700">{{ $scent->blend_oil_count ?: '—' }}</span>
                  @endif
                </div>
                @if($inlineSaving[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-zinc-500">Saving...</div>
                @elseif($cellError)
                  <div class="mt-1 text-[11px] text-red-300">{{ $cellError }}</div>
                @elseif($inlineSaved[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-emerald-200/80">Saved</div>
                @endif
              </td>

              <td class="px-3 py-2 align-top">
                @php
                  $field = 'is_active';
                  $cellKey = $scent->id . ':' . $field;
                  $isEditing = $inlineRowId === $scent->id && $inlineField === $field;
                  $isFocused = $focusedRowId === $scent->id && $focusedField === $field;
                  $cellError = $inlineErrors[$cellKey] ?? null;
                @endphp
                <div
                  wire:click="focusInlineCell({{ $scent->id }}, '{{ $field }}')"
                  wire:dblclick="startInlineEdit({{ $scent->id }}, '{{ $field }}')"
                  tabindex="0"
                  class="rounded-lg border px-2 py-1.5 transition {{ $cellError ? 'border-red-400/50 bg-red-500/10' : ($isEditing ? 'border-emerald-300/50 bg-emerald-100' : ($isFocused ? 'border-emerald-300/30 bg-zinc-100' : 'border-transparent bg-transparent hover:border-zinc-200 hover:bg-zinc-50')) }}"
                >
                  @if($isEditing)
                    <select
                      wire:model.live="inlineValue"
                      wire:keydown.enter.prevent="commitInlineEdit('stay')"
                      wire:keydown.tab.prevent="commitInlineEdit('next')"
                      wire:keydown.shift.tab.prevent="commitInlineEdit('prev')"
                      wire:keydown.escape.prevent="cancelInlineEdit"
                      wire:blur="commitInlineEdit('stay')"
                      autofocus
                      class="h-8 w-full rounded-md border border-emerald-300/40 bg-zinc-50 px-2 text-sm text-zinc-950 focus:border-emerald-200 focus:outline-none"
                    >
                      <option value="1">Active</option>
                      <option value="0">Inactive</option>
                    </select>
                  @else
                    <span class="inline-flex h-6 items-center whitespace-nowrap rounded-md border px-2 text-[11px] font-medium {{ $scent->is_active ? 'border-zinc-300 bg-emerald-100 text-emerald-900' : 'border-zinc-300 bg-zinc-50 text-zinc-600' }}">
                      {{ $scent->is_active ? 'Active' : 'Inactive' }}
                    </span>
                  @endif
                </div>
                @if($inlineSaving[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-zinc-500">Saving...</div>
                @elseif($cellError)
                  <div class="mt-1 text-[11px] text-red-300">{{ $cellError }}</div>
                @elseif($inlineSaved[$cellKey] ?? false)
                  <div class="mt-1 text-[11px] text-emerald-200/80">Saved</div>
                @endif
              </td>

              <td class="px-3 py-2 align-top text-right">
                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                  <button type="button" wire:click="openEdit({{ $scent->id }})" class="inline-flex h-7 items-center rounded-full border border-emerald-400/30 bg-emerald-100 px-3 text-[11px] text-emerald-900">Advanced</button>
                  <button type="button" wire:click="openDelete({{ $scent->id }})" class="inline-flex h-7 items-center rounded-full border border-red-400/30 bg-red-500/10 px-3 text-[11px] text-red-100">Delete</button>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-4">{{ $scents->links() }}</div>
</section>

@if($showEdit)
  <div class="fixed inset-0 z-[9999] flex items-center justify-center fb-overlay-soft p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
    <div class="max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6">
      <div class="text-lg font-semibold text-zinc-950">Edit Scent</div>

      @if($editErrorBanner)
        <div class="mt-3 rounded-xl border border-red-300/35 bg-red-950/30 px-3 py-2 text-xs text-red-100">
          {{ $editErrorBanner }}
        </div>
      @endif

      <div class="mt-4 grid gap-3 md:grid-cols-6">
        <div class="md:col-span-2">
          <flux:input wire:model.defer="edit.name" label="Name" />
          @error('edit.name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div class="md:col-span-2">
          <flux:input wire:model.defer="edit.display_name" label="Display Name" />
          @error('edit.display_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div>
          <flux:input wire:model.defer="edit.abbreviation" label="Abbrev" />
          @error('edit.abbreviation') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div>
          <label class="text-xs text-zinc-600">Oil Ref</label>
          <input
            wire:model.defer="edit.oil_reference_name"
            list="catalog-edit-oil-ref-list"
            class="mt-1 h-10 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-zinc-900"
            placeholder="Search/select primary oil or type custom"
          />
          <datalist id="catalog-edit-oil-ref-list">
            @foreach($baseOils as $oil)
              <option value="{{ $oil->name }}">{{ $oil->name }}</option>
            @endforeach
          </datalist>
          @error('edit.oil_reference_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div class="md:col-span-3">
          <label class="text-xs text-zinc-600">Map to canonical scent (optional)</label>
          <div class="mt-1">
            <livewire:components.scent-combobox
              wire:model.live="edit.canonical_scent_id"
              :emit-key="'catalog-scent-edit-canonical'"
              :allow-wholesale-custom="true"
              :include-inactive="true"
              wire:key="catalog-scent-edit-canonical-{{ $editingId }}"
            />
          </div>
          @if($editCanonicalSuggestionId && $editCanonicalSuggestionLabel)
            <button type="button" wire:click="applyCanonicalSuggestion('edit')" class="mt-2 rounded-full border border-emerald-300/30 bg-emerald-100 px-3 py-1 text-[11px] text-emerald-900">
              Suggested: map to {{ $editCanonicalSuggestionLabel }}
            </button>
          @endif
          @error('edit.canonical_scent_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div class="md:col-span-3">
          <label class="text-xs text-zinc-600">Wholesale custom source (optional)</label>
          <div class="mt-1 flex items-center gap-2">
            <select wire:model.defer="edit.source_wholesale_custom_scent_id" class="h-10 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-zinc-900">
              <option value="">None</option>
              @foreach($wholesaleSources as $source)
                @php
                  $slotCount = collect([$source->oil_1, $source->oil_2, $source->oil_3])->filter(fn ($slot) => !blank($slot))->count();
                  $topLevelCount = is_array(data_get($source, 'top_level_recipe_json.components'))
                    ? count(data_get($source, 'top_level_recipe_json.components'))
                    : 0;
                  $isBlendLike = $slotCount > 1 || $topLevelCount > 1;
                @endphp
                <option value="{{ $source->id }}">
                  {{ $source->custom_scent_name }} · {{ $source->account_name }} · {{ $isBlendLike ? 'Wholesale custom blend' : 'Wholesale custom scent' }}
                </option>
              @endforeach
            </select>
            <button type="button" wire:click="applySelectedWholesaleSource('edit')" class="shrink-0 rounded-full border border-zinc-300 bg-emerald-100 px-3 py-2 text-[11px] font-semibold text-zinc-950">
              Apply
            </button>
          </div>
          @error('edit.source_wholesale_custom_scent_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div>
          <label class="text-xs text-zinc-600">Recipe-backed scent?</label>
          <div class="mt-2 flex h-10 items-center gap-2">
            <input type="checkbox" wire:model.defer="edit.is_blend" class="rounded border-zinc-300 bg-zinc-100" />
            <span class="text-sm text-zinc-700">Blend / multi-source recipe</span>
          </div>
          @error('edit.is_blend') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div class="flex items-center gap-2">
          <input type="checkbox" wire:model.defer="edit.is_active" class="rounded border-zinc-300 bg-zinc-100" />
          <span class="text-sm text-zinc-700">Active</span>
        </div>
      </div>

      @if($edit['is_blend'] ?? false)
        <div class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
          <div class="text-xs uppercase tracking-[0.24em] text-emerald-800">Blend Mapping</div>
          <div class="mt-3 grid gap-3 md:grid-cols-6">
            <div class="md:col-span-3">
              <label class="text-xs text-zinc-600">Existing blend</label>
              <select wire:model.defer="edit.oil_blend_id" class="mt-1 h-10 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-zinc-900">
                <option value="">None</option>
                @foreach($blends as $blend)
                  <option value="{{ $blend->id }}">{{ $blend->name }}</option>
                @endforeach
              </select>
              @error('edit.oil_blend_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            </div>
            <div>
              <flux:input wire:model.defer="edit.blend_oil_count" label="Oil count" type="number" min="1" />
              @error('edit.blend_oil_count') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            </div>
            <div class="md:col-span-2">
              <label class="text-xs text-zinc-600">Create new blend from sources</label>
              <div class="mt-2 flex h-10 items-center gap-2">
                <input type="checkbox" wire:model.defer="edit.create_inline_blend" class="rounded border-zinc-300 bg-zinc-100" />
                <span class="text-sm text-zinc-700">Create inline</span>
              </div>
              @error('edit.create_inline_blend') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            </div>
            @if($edit['create_inline_blend'] ?? false)
              <div class="md:col-span-3">
                <flux:input wire:model.defer="edit.inline_blend_name" label="New blend name" />
                @error('edit.inline_blend_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
              </div>
            @endif
          </div>

          <div class="mt-4 space-y-2">
            <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Recipe Sources</div>
            @foreach($edit['recipe_components'] ?? [] as $index => $row)
              @php $rowType = $row['type'] ?? 'base_oil'; @endphp
              <div class="grid gap-2 md:grid-cols-8" wire:key="edit-recipe-row-{{ $index }}">
                <div class="md:col-span-2">
                  <label class="mb-1 block text-[11px] text-zinc-500">Source type</label>
                  <select wire:model.defer="edit.recipe_components.{{ $index }}.type" class="h-10 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-900">
                    <option value="base_oil">Oil</option>
                    <option value="blend">Blend</option>
                  </select>
                </div>
                <div class="md:col-span-4">
                  <label class="mb-1 block text-[11px] text-zinc-500">Source</label>
                  <select wire:model.defer="edit.recipe_components.{{ $index }}.id" class="h-10 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-900">
                    <option value="">Select {{ $rowType === 'blend' ? 'blend' : 'oil' }}</option>
                    @if($rowType === 'blend')
                      @foreach($blends as $blend)
                        <option value="{{ $blend->id }}">{{ $blend->name }}</option>
                      @endforeach
                    @else
                      @foreach($baseOils as $oil)
                        <option value="{{ $oil->id }}">{{ $oil->name }}</option>
                      @endforeach
                    @endif
                  </select>
                  @error("edit.recipe_components.$index.id") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
                </div>
                <div>
                  <flux:input wire:model.defer="edit.recipe_components.{{ $index }}.ratio_weight" label="Weight" type="number" min="1" />
                  @error("edit.recipe_components.$index.ratio_weight") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
                </div>
                <div class="flex items-end">
                  <button type="button" wire:click="removeRecipeComponent('edit', {{ $index }})" class="h-10 w-full rounded-xl border border-red-400/30 bg-red-500/10 px-3 text-[11px] font-semibold text-red-100">
                    Remove
                  </button>
                </div>
              </div>
            @endforeach
            <button type="button" wire:click="addRecipeComponent('edit')" class="rounded-full border border-zinc-300 bg-emerald-100 px-3 py-1 text-[11px] font-semibold text-emerald-900">
              Add oil/blend source
            </button>
            @error('edit.recipe_components') <div class="text-xs text-red-300">{{ $message }}</div> @enderror
          </div>
        </div>
      @endif

      <div class="mt-4 flex items-center gap-2">
        <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-zinc-950 disabled:cursor-not-allowed disabled:opacity-60">
          <span wire:loading.remove wire:target="save">Save</span>
          <span wire:loading wire:target="save">Saving...</span>
        </button>
        <button type="button" wire:click="closeEdit" class="rounded-full border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-600">Cancel</button>
      </div>
    </div>
  </div>
@endif

@if($showDelete)
  <div class="fixed inset-0 z-[9999] flex items-center justify-center fb-overlay-soft p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
    <div class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6">
      <div class="text-lg font-semibold text-zinc-950">Delete Scent</div>
      <div class="mt-2 text-sm text-zinc-600">Are you sure? This cannot be undone.</div>
      <div class="mt-4 flex items-center gap-2">
        <button type="button" wire:click="destroy" class="rounded-full border border-red-400/40 bg-red-500/30 px-4 py-2 text-xs font-semibold text-zinc-950">Delete</button>
        <button type="button" wire:click="$set('showDelete', false)" class="rounded-full border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-600">Cancel</button>
      </div>
    </div>
  </div>
@endif

@once
  <script>
    window.addEventListener('catalog-scent-focus-invalid', (event) => {
      const payload = event?.detail?.[0] && typeof event.detail[0] === 'object' ? event.detail[0] : event.detail;
      const field = payload?.field;
      if (!field || typeof field !== 'string') return;

      const selectors = [
        `[wire\\:model\\.defer="${field}"]`,
        `[wire\\:model\\.live="${field}"]`,
        `[wire\\:model="${field}"]`,
      ];

      const target = document.querySelector(selectors.join(','));
      if (!target) return;

      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (typeof target.focus === 'function') {
        target.focus();
      }
    });
  </script>
@endonce
