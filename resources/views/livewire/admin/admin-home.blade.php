@php
  $user = auth()->user();
  $isAdmin = $user?->isAdmin() ?? true;
@endphp

<div class="space-y-4 sm:space-y-6">
  <section class="fb-card p-4 sm:p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-[var(--fb-muted)]">Administration</div>
    <div class="mt-2 text-2xl font-['Fraunces'] font-semibold text-[var(--fb-text)] sm:text-3xl">Admin Workspace</div>
    <div class="mt-2 text-sm text-[var(--fb-muted)]">Use this area to manage Scent Intake, Master Data, imports, and team-facing system controls.</div>
  </section>

  <x-ui.page-explainer
    title="Admin page guide"
    what="Run admin tasks like data cleanup, intake review, and catalog maintenance."
    why="These settings affect downstream operations, reporting quality, and operator confidence."
    when="Use this page when launching new catalog entries, resolving import issues, or updating admin controls."
  />

  <section class="fb-card p-3 sm:p-4 md:p-6">
    <div class="space-y-4 sm:space-y-6">
      @if($tab === 'users' && $isAdmin)
        <livewire:admin.users.users-index />
      @elseif($tab === 'imports')
        <livewire:admin.import-runs />
      @elseif($tab === 'scent-intake')
        <livewire:admin.imports.import-exceptions />
      @elseif($tab === 'catalog')
        <div class="grid gap-6">
          <livewire:admin.catalog.scents-crud />
          <livewire:admin.catalog.costs-crud />
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
          <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-4 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.72)] sm:p-6">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
              <div>
                <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Canonical Tables</div>
                <h2 class="mt-2 text-2xl font-['Fraunces'] font-semibold text-zinc-950 sm:text-3xl">Normalized Catalog</h2>
                <p class="mt-2 max-w-3xl text-sm text-zinc-600">
                  Power-user maintenance for existing canonical data. Use the New Scent Wizard to create new scents.
                </p>
              </div>
              <div class="flex flex-wrap gap-2">
                <a
                  href="{{ route('admin.scent-wizard', ['source_context' => 'master-data', 'return_to' => request()->fullUrl()]) }}"
                  wire:navigate
                  class="inline-flex h-11 items-center justify-center rounded-xl border border-zinc-300 bg-emerald-100 px-4 text-sm font-medium text-zinc-950 transition hover:bg-emerald-500/25"
                >
                  New Scent Wizard
                </a>
                <a
                  href="{{ route('admin.index', ['tab' => 'scent-intake']) }}"
                  wire:navigate
                  class="inline-flex h-11 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-50 px-4 text-sm font-medium text-zinc-800 transition hover:bg-zinc-100"
                >
                  Open Scent Intake
                </a>
              </div>
            </div>
          </section>

          <section class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-3xl border border-zinc-200 bg-zinc-50 p-3 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.72)] sm:p-4 md:p-6">
            <div
              id="master-data-grid"
              data-resources='@json($masterDataResources)'
              data-active-resource="{{ $masterDataActiveResource }}"
              data-base-endpoint="{{ $masterDataBaseEndpoint }}"
              data-bulk-endpoint-base="{{ url('/admin/master-data') }}"
              data-scent-wizard-url="{{ route('admin.scent-wizard', ['source_context' => 'master-data']) }}"
              class="h-full min-h-0"
            >
              <div class="flex h-full items-center justify-center rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-5 text-sm text-zinc-600">
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
