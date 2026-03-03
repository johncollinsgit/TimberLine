<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
    <div class="flex items-center justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Import Ops</div>
        <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Shopify Import Runs</div>
        <div class="mt-2 text-sm text-emerald-50/70">Each run records counts and mapping exceptions for review.</div>
      </div>
      <a href="{{ route('admin.mapping-exceptions') }}"
         class="h-9 px-4 inline-flex items-center rounded-full border border-emerald-400/30 bg-emerald-500/15 text-xs font-semibold text-white">
        Open Scent Intake
      </a>
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#0f1412]/80 p-4">
    <div class="overflow-x-auto rounded-2xl border border-emerald-200/10">
      <table class="min-w-full text-sm">
        <thead class="bg-white/5 text-white/70">
          <tr>
            <th class="px-3 py-2 text-left font-medium">Run</th>
            <th class="px-3 py-2 text-left font-medium">Store</th>
            <th class="px-3 py-2 text-left font-medium">Started</th>
            <th class="px-3 py-2 text-left font-medium">Finished</th>
            <th class="px-3 py-2 text-right font-medium">Imported</th>
            <th class="px-3 py-2 text-right font-medium">Updated</th>
            <th class="px-3 py-2 text-right font-medium">Lines</th>
            <th class="px-3 py-2 text-right font-medium">Merged</th>
            <th class="px-3 py-2 text-right font-medium">Exceptions</th>
            <th class="px-3 py-2 text-left font-medium">Mode</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-white/10">
          @forelse($runs as $run)
            <tr class="hover:bg-white/5">
              <td class="px-3 py-2 text-white/80">#{{ $run->id }}</td>
              <td class="px-3 py-2 text-white/80">{{ $run->store_key ?? '—' }}</td>
              <td class="px-3 py-2 text-white/70">{{ optional($run->started_at)->toDateTimeString() ?? '—' }}</td>
              <td class="px-3 py-2 text-white/70">{{ optional($run->finished_at)->toDateTimeString() ?? '—' }}</td>
              <td class="px-3 py-2 text-right text-white/80">{{ $run->imported_count }}</td>
              <td class="px-3 py-2 text-right text-white/80">{{ $run->updated_count }}</td>
              <td class="px-3 py-2 text-right text-white/80">{{ $run->lines_count }}</td>
              <td class="px-3 py-2 text-right text-white/80">{{ $run->merged_lines_count }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border border-amber-300/30 bg-amber-400/20 text-amber-50">
                  {{ $run->mapping_exceptions_count }}
                </span>
              </td>
              <td class="px-3 py-2 text-white/70">
                {{ $run->is_dry_run ? 'dry-run' : 'live' }}
              </td>
            </tr>
          @empty
            <tr>
              <td class="px-3 py-6 text-white/50" colspan="10">No import runs yet.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="mt-4">
      {{ $runs->links() }}
    </div>
  </section>
</div>
