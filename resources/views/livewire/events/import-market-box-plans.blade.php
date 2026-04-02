<div class="space-y-6">
  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Events</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">Import Market Box Plans</div>
    <div class="mt-2 text-sm text-zinc-600">Upload the normalized master CSV to backfill historical event instances and their box plan lines.</div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6 space-y-4">
    <div class="text-xs text-emerald-800">Expected columns</div>
    <div class="text-[11px] text-zinc-500">
      event_title, event_state, event_starts_at, event_ends_at, event_status, scent_raw, box_count_sent
      <span class="block mt-1">Optional: box_count_returned, line_notes, event_notes_raw, source_file, sheet_title, is_split_box</span>
    </div>

    <input type="file" wire:model="file" class="text-xs text-zinc-700" />
    @error('file')
      <div class="text-xs text-red-300">{{ $message }}</div>
    @enderror
    <div wire:loading wire:target="file" class="text-[11px] text-emerald-800">Uploading file…</div>

    <div>
      <button type="button" wire:click="importCsv" wire:loading.attr="disabled" wire:target="importCsv,file"
        class="rounded-full border border-emerald-400/25 bg-emerald-100 px-4 py-2 text-xs text-zinc-900 disabled:opacity-50">
        Import Market Box Plans
      </button>
      <span wire:loading wire:target="importCsv" class="ml-2 text-[11px] text-emerald-800">Importing…</span>
    </div>

    @if(!empty($report['import_batch_id']))
      <div class="rounded-2xl border border-emerald-300/15 bg-emerald-100 p-4 text-xs text-zinc-600">
        Imported batch <span class="font-semibold">{{ $report['import_batch_id'] }}</span>.
        {{ (int)($report['event_instances_created'] ?? 0) }} instances created,
        {{ (int)($report['event_instances_updated'] ?? 0) }} updated,
        {{ (int)($report['box_plans_created'] ?? 0) }} box plans created,
        skipped {{ (int)($report['skipped'] ?? 0) }} rows.
      </div>
    @endif

    @if(!empty($report['deleted_batch_id']))
      <div class="rounded-2xl border border-amber-300/15 bg-amber-50 p-4 text-xs text-amber-800">
        Deleted batch <span class="font-semibold">{{ $report['deleted_batch_id'] }}</span>.
        Removed {{ (int)($report['deleted_event_instances'] ?? 0) }} event instances and {{ (int)($report['deleted_box_plans'] ?? 0) }} box plans.
      </div>
    @endif

    @if(!empty($warnings))
      <div class="rounded-2xl border border-amber-300/20 bg-amber-100 p-3 text-[11px] text-amber-900">
        <div class="font-semibold">Import warnings</div>
        <ul class="mt-2 space-y-1">
          @foreach($warnings as $warning)
            <li>{{ $warning }}</li>
          @endforeach
        </ul>
      </div>
    @endif
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6 space-y-4">
    <div class="text-xs text-emerald-800">Delete Import Batch</div>
    <div class="flex flex-col gap-3 md:flex-row md:items-center">
      <select wire:model.live="selectedBatchId"
        class="h-10 rounded-2xl border border-zinc-200 bg-zinc-50 px-3 text-xs text-zinc-900">
        <option value="">Select a batch…</option>
        @foreach($recentBatches as $batch)
          <option value="{{ $batch['batch_id'] }}">
            {{ $batch['batch_id'] }} · {{ (int)$batch['event_instances'] }} events · {{ (int)$batch['box_plans'] }} lines
          </option>
        @endforeach
      </select>

      <button
        type="button"
        wire:click="deleteSelectedBatch"
        @disabled(empty($selectedBatchId))
        onclick="return confirm('Delete this import batch and all linked historical box-plan rows?');"
        class="rounded-full border border-rose-300/20 bg-rose-100 px-4 py-2 text-xs text-rose-900 disabled:cursor-not-allowed disabled:opacity-50"
      >
        Delete This Import Batch
      </button>
    </div>
  </section>
</div>
