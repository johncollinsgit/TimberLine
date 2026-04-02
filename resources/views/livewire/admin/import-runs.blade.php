<div class="space-y-6">
  <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
    <div class="flex items-center justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Import Ops</div>
        <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">Shopify Import Runs</div>
        <div class="mt-2 text-sm text-zinc-600">Each run records counts and mapping exceptions for review.</div>
      </div>
      <a href="{{ route('admin.mapping-exceptions') }}"
         class="h-9 px-4 inline-flex items-center rounded-full border border-emerald-400/30 bg-emerald-100 text-xs font-semibold text-zinc-950">
        Open Scent Intake
      </a>
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-4">
    <div class="overflow-x-auto rounded-2xl border border-zinc-200">
      <table class="min-w-full text-sm">
        <thead class="bg-zinc-50 text-zinc-600">
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
        <tbody class="divide-y divide-zinc-200">
          @forelse($runs as $run)
            <tr class="hover:bg-zinc-50">
              <td class="px-3 py-2 text-zinc-700">#{{ $run->id }}</td>
              <td class="px-3 py-2 text-zinc-700">{{ $run->store_key ?? '—' }}</td>
              <td class="px-3 py-2 text-zinc-600">{{ optional($run->started_at)->toDateTimeString() ?? '—' }}</td>
              <td class="px-3 py-2 text-zinc-600">{{ optional($run->finished_at)->toDateTimeString() ?? '—' }}</td>
              <td class="px-3 py-2 text-right text-zinc-700">{{ $run->imported_count }}</td>
              <td class="px-3 py-2 text-right text-zinc-700">{{ $run->updated_count }}</td>
              <td class="px-3 py-2 text-right text-zinc-700">{{ $run->lines_count }}</td>
              <td class="px-3 py-2 text-right text-zinc-700">{{ $run->merged_lines_count }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border border-amber-300/30 bg-amber-100 text-amber-900">
                  {{ $run->mapping_exceptions_count }}
                </span>
              </td>
              <td class="px-3 py-2 text-zinc-600">
                {{ $run->is_dry_run ? 'dry-run' : 'live' }}
              </td>
            </tr>
          @empty
            <tr>
              <td class="px-3 py-6 text-zinc-500" colspan="10">No import runs yet.</td>
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
