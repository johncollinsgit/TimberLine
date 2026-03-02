<x-layouts::app.sidebar :title="'Master Data'">
  <flux:main>
    <div class="flex min-h-[calc(100vh-8rem)] flex-col gap-6">
      <section class="rounded-3xl border border-white/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Admin Master Data</div>
            <h1 class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Normalized Catalog</h1>
            <p class="mt-2 max-w-3xl text-sm text-emerald-50/70">
              This page edits the canonical backend tables. Markets drafts read from this data. Operators do not create mappings in the wizard.
            </p>
          </div>
          <a
            href="{{ route('admin.index') }}"
            class="inline-flex h-11 items-center justify-center rounded-full border border-white/10 bg-white/5 px-4 text-sm font-medium text-white/85 transition hover:bg-white/10"
          >
            Back To Admin
          </a>
        </div>
      </section>

      <section class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-3xl border border-white/10 bg-[#101513]/80 p-4 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.72)] md:p-6">
        <div
          id="master-data-grid"
          data-resources='@json($resources)'
          data-active-resource="{{ $activeResource }}"
          data-base-endpoint="{{ $baseEndpoint }}"
          class="h-full min-h-0"
        >
          <div class="flex h-full items-center justify-center rounded-2xl border border-white/10 bg-black/20 px-4 py-5 text-sm text-emerald-50/65">
            Loading master data grid…
          </div>
        </div>
      </section>
    </div>

    @vite('resources/js/admin/master-data-grid.tsx')
  </flux:main>
</x-layouts::app.sidebar>
