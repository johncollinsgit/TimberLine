<x-layouts::app.sidebar :title="'Master Data'">
  <flux:main>
    <div class="space-y-5">
      <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <div class="text-xs font-semibold uppercase tracking-[0.24em] text-zinc-500">Admin Master Data</div>
            <h1 class="mt-2 text-2xl font-semibold text-zinc-950">Normalized Catalog</h1>
            <p class="mt-2 max-w-3xl text-sm text-zinc-600">
              This page edits the canonical backend tables. Markets drafts read from this data. Operators do not create mappings in the wizard.
            </p>
          </div>
          <a
            href="{{ route('admin.index') }}"
            class="inline-flex h-11 items-center justify-center rounded-2xl border border-zinc-200 px-4 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50"
          >
            Back To Admin
          </a>
        </div>
      </div>

      <div
        id="master-data-grid"
        data-resources='@json($resources)'
        data-active-resource="{{ $activeResource }}"
        data-base-endpoint="{{ $baseEndpoint }}"
        class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm"
      >
        <div class="text-sm text-zinc-500">Loading master data grid…</div>
      </div>
    </div>

    @vite('resources/js/admin/master-data-grid.tsx')
  </flux:main>
</x-layouts::app.sidebar>
