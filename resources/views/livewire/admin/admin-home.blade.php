@php
  $user = auth()->user();
  $isAdmin = $user?->isAdmin() ?? true;
  $isManager = $user?->isManager() ?? false;
@endphp

<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Administration</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">System Controls</div>
    <div class="mt-2 text-sm text-emerald-50/70">Manage users, catalogs, and Shopify import fixes.</div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-4 md:p-6">
    <div class="flex flex-wrap gap-2 border-b border-white/10 pb-3">
      @if($isAdmin)
        <button
          wire:click="setTab('users')"
          class="px-3 py-2 text-sm font-semibold rounded-full {{ $tab === 'users' ? 'bg-emerald-500/20 text-emerald-50 border border-emerald-400/40' : 'border border-white/10 text-white/70 hover:text-white' }}">
          Manage Users
        </button>
      @endif
      <button
        wire:click="setTab('imports')"
        class="px-3 py-2 text-sm font-semibold rounded-full {{ $tab === 'imports' ? 'bg-emerald-500/20 text-emerald-50 border border-emerald-400/40' : 'border border-white/10 text-white/70 hover:text-white' }}">
        Fix Imports
      </button>
      <button
        wire:click="setTab('catalog')"
        class="px-3 py-2 text-sm font-semibold rounded-full {{ $tab === 'catalog' ? 'bg-emerald-500/20 text-emerald-50 border border-emerald-400/40' : 'border border-white/10 text-white/70 hover:text-white' }}">
        Scent Catalog
      </button>
      <button
        wire:click="setTab('sizes-wicks')"
        class="px-3 py-2 text-sm font-semibold rounded-full {{ $tab === 'sizes-wicks' ? 'bg-emerald-500/20 text-emerald-50 border border-emerald-400/40' : 'border border-white/10 text-white/70 hover:text-white' }}">
        Sizes & Wicks
      </button>
      <button
        wire:click="setTab('wholesale-custom')"
        class="px-3 py-2 text-sm font-semibold rounded-full {{ $tab === 'wholesale-custom' ? 'bg-emerald-500/20 text-emerald-50 border border-emerald-400/40' : 'border border-white/10 text-white/70 hover:text-white' }}">
        Wholesale Custom Scents
      </button>
      <button
        wire:click="setTab('blends')"
        class="px-3 py-2 text-sm font-semibold rounded-full {{ $tab === 'blends' ? 'bg-emerald-500/20 text-emerald-50 border border-emerald-400/40' : 'border border-white/10 text-white/70 hover:text-white' }}">
        Oil Blends
      </button>
      <button
        wire:click="setTab('candle-club')"
        class="px-3 py-2 text-sm font-semibold rounded-full {{ $tab === 'candle-club' ? 'bg-emerald-500/20 text-emerald-50 border border-emerald-400/40' : 'border border-white/10 text-white/70 hover:text-white' }}">
        Candle Club
      </button>
      <button
        wire:click="setTab('oils')"
        class="px-3 py-2 text-sm font-semibold rounded-full {{ $tab === 'oils' ? 'bg-emerald-500/20 text-emerald-50 border border-emerald-400/40' : 'border border-white/10 text-white/70 hover:text-white' }}">
        Scent Oil Abbreviations
      </button>
    </div>

    <div class="mt-6 space-y-6">
      @if($tab === 'users' && $isAdmin)
        <livewire:admin.users.users-index />
      @elseif($tab === 'imports')
        <livewire:admin.imports.import-exceptions />
      @elseif($tab === 'catalog')
        <div class="grid gap-6">
          <livewire:admin.catalog.scents-crud />
        </div>
      @elseif($tab === 'sizes-wicks')
        <div class="grid gap-6">
          <livewire:admin.catalog.sizes-crud />
          <livewire:admin.catalog.wicks-crud />
        </div>
      @elseif($tab === 'wholesale-custom')
        <livewire:admin.wholesale.custom-scents-crud />
      @elseif($tab === 'blends')
        <livewire:admin.oils.oil-blends-crud />
      @elseif($tab === 'candle-club')
        <livewire:admin.candle-club.candle-club-scents-crud />
      @elseif($tab === 'oils')
        <livewire:admin.oils.oil-abbreviations-crud />
      @endif
    </div>
  </section>
</div>
