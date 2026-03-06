@php
  $user = auth()->user();
  $isAdmin = $user?->isAdmin() ?? true;
  $tabLinkClass = 'inline-flex min-h-[2.25rem] max-w-[9.5rem] items-center justify-center rounded-xl border px-2 py-1 text-center text-[11px] font-semibold leading-tight whitespace-normal transition sm:min-h-[2.5rem] sm:max-w-[11rem] sm:px-3 sm:text-xs';
@endphp

<div class="space-y-4 sm:space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-4 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)] sm:p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Administration</div>
    <div class="mt-2 text-2xl font-['Fraunces'] font-semibold text-white sm:text-3xl">Admin Workspace</div>
    <div class="mt-2 text-sm text-emerald-50/70">System controls, scent intake, and canonical master data now live in one place.</div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-3 sm:p-4 md:p-6">
    <div class="flex flex-wrap items-stretch gap-2 border-b border-white/10 pb-2 sm:pb-3">
      @if($isAdmin)
        <a
          href="{{ route('admin.index', ['tab' => 'users']) }}"
          wire:navigate
          class="{{ $tabLinkClass }} {{ $tab === 'users' ? 'border-emerald-400/35 bg-emerald-500/15 text-emerald-50' : 'border-white/10 bg-black/20 text-white/70 hover:border-emerald-300/20 hover:text-white' }}">
          Manage Users
        </a>
      @endif
      <a
        href="{{ route('admin.index', ['tab' => 'imports']) }}"
        wire:navigate
        class="{{ $tabLinkClass }} {{ $tab === 'imports' ? 'border-emerald-400/35 bg-emerald-500/15 text-emerald-50' : 'border-white/10 bg-black/20 text-white/70 hover:border-emerald-300/20 hover:text-white' }}">
        Fix Imports
      </a>
      <a
        href="{{ route('admin.index', ['tab' => 'scent-intake']) }}"
        wire:navigate
        class="{{ $tabLinkClass }} {{ $tab === 'scent-intake' ? 'border-emerald-400/35 bg-emerald-500/15 text-emerald-50' : 'border-white/10 bg-black/20 text-white/70 hover:border-emerald-300/20 hover:text-white' }}">
        Scent Intake
      </a>
      <a
        href="{{ route('admin.index', ['tab' => 'catalog']) }}"
        wire:navigate
        class="{{ $tabLinkClass }} {{ $tab === 'catalog' ? 'border-emerald-400/35 bg-emerald-500/15 text-emerald-50' : 'border-white/10 bg-black/20 text-white/70 hover:border-emerald-300/20 hover:text-white' }}">
        Scent Catalog
      </a>
      <a
        href="{{ route('admin.index', ['tab' => 'sizes-wicks']) }}"
        wire:navigate
        class="{{ $tabLinkClass }} {{ $tab === 'sizes-wicks' ? 'border-emerald-400/35 bg-emerald-500/15 text-emerald-50' : 'border-white/10 bg-black/20 text-white/70 hover:border-emerald-300/20 hover:text-white' }}">
        Sizes & Wicks
      </a>
      <a
        href="{{ route('admin.index', ['tab' => 'wholesale-custom']) }}"
        wire:navigate
        class="{{ $tabLinkClass }} {{ $tab === 'wholesale-custom' ? 'border-emerald-400/35 bg-emerald-500/15 text-emerald-50' : 'border-white/10 bg-black/20 text-white/70 hover:border-emerald-300/20 hover:text-white' }}">
        Wholesale Custom Scents
      </a>
      <a
        href="{{ route('admin.index', ['tab' => 'blends']) }}"
        wire:navigate
        class="{{ $tabLinkClass }} {{ $tab === 'blends' ? 'border-emerald-400/35 bg-emerald-500/15 text-emerald-50' : 'border-white/10 bg-black/20 text-white/70 hover:border-emerald-300/20 hover:text-white' }}">
        Oil Blends
      </a>
      <a
        href="{{ route('admin.index', ['tab' => 'candle-club']) }}"
        wire:navigate
        class="{{ $tabLinkClass }} {{ $tab === 'candle-club' ? 'border-emerald-400/35 bg-emerald-500/15 text-emerald-50' : 'border-white/10 bg-black/20 text-white/70 hover:border-emerald-300/20 hover:text-white' }}">
        Candle Club
      </a>
      <a
        href="{{ route('admin.index', ['tab' => 'oils']) }}"
        wire:navigate
        class="{{ $tabLinkClass }} {{ $tab === 'oils' ? 'border-emerald-400/35 bg-emerald-500/15 text-emerald-50' : 'border-white/10 bg-black/20 text-white/70 hover:border-emerald-300/20 hover:text-white' }}">
        Scent Oil Abbreviations
      </a>
      <a
        href="{{ route('admin.index', ['tab' => 'master-data', 'resource' => $masterDataActiveResource]) }}"
        wire:navigate
        class="{{ $tabLinkClass }} {{ $tab === 'master-data' ? 'border-emerald-400/35 bg-emerald-500/15 text-emerald-50' : 'border-white/10 bg-black/20 text-white/70 hover:border-emerald-300/20 hover:text-white' }}">
        Master Data
      </a>
    </div>

    <div class="mt-4 space-y-4 sm:mt-6 sm:space-y-6">
      @if($tab === 'users' && $isAdmin)
        <livewire:admin.users.users-index />
      @elseif($tab === 'imports')
        <livewire:admin.import-runs />
      @elseif($tab === 'scent-intake')
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
      @elseif($tab === 'master-data')
        <div class="flex min-h-0 flex-col gap-4 sm:min-h-[calc(100vh-16rem)] sm:gap-6">
          <section class="rounded-3xl border border-white/10 bg-black/15 p-4 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.72)] sm:p-6">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
              <div>
                <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Canonical Tables</div>
                <h2 class="mt-2 text-2xl font-['Fraunces'] font-semibold text-white sm:text-3xl">Normalized Catalog</h2>
                <p class="mt-2 max-w-3xl text-sm text-emerald-50/70">
                  This grid edits the same canonical tables that Scent Intake and the retail planners read from.
                </p>
              </div>
              <a
                href="{{ route('admin.index', ['tab' => 'scent-intake']) }}"
                wire:navigate
                class="inline-flex h-11 items-center justify-center rounded-xl border border-white/10 bg-white/5 px-4 text-sm font-medium text-white/85 transition hover:bg-white/10"
              >
                Open Scent Intake
              </a>
            </div>
          </section>

          <section class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-3xl border border-white/10 bg-black/15 p-3 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.72)] sm:p-4 md:p-6">
            <div
              id="master-data-grid"
              data-resources='@json($masterDataResources)'
              data-active-resource="{{ $masterDataActiveResource }}"
              data-base-endpoint="{{ $masterDataBaseEndpoint }}"
              data-bulk-endpoint-base="{{ url('/admin/master-data') }}"
              class="h-full min-h-0"
            >
              <div class="flex h-full items-center justify-center rounded-2xl border border-white/10 bg-black/20 px-4 py-5 text-sm text-emerald-50/65">
                Loading master data grid…
              </div>
            </div>
          </section>
        </div>
      @endif
    </div>
  </section>

  @once
    @vite('resources/js/admin/master-data-grid.tsx')
  @endonce
</div>
