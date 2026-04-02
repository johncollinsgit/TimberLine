<div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
  <div class="text-xs uppercase tracking-[0.22em] text-emerald-800">Preview Historical Boxes</div>
  <div class="mt-1 text-sm font-medium text-zinc-950">{{ $preview['candidate_title'] ?? 'Candidate Event' }}</div>
  <div class="mt-1 text-xs text-emerald-800">
    {{ !empty($preview['candidate_date']) ? \Illuminate\Support\Carbon::parse($preview['candidate_date'])->format('M j, Y') : 'Date TBD' }}
  </div>

  @if(!($preview['has_plan_data'] ?? false))
    <div class="mt-3 rounded-xl border border-dashed border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-600">
      No plan found for this candidate.
    </div>
  @else
    <div class="mt-3 text-xs text-emerald-800">
      Full: {{ (int)($preview['summary']['full_boxes'] ?? 0) }}
      · Half: {{ (int)($preview['summary']['half_boxes'] ?? 0) }}
      · Top Shelf: {{ (int)($preview['summary']['top_shelf_boxes'] ?? 0) }}
    </div>
    <div class="mt-2 max-h-52 overflow-y-auto pr-1 space-y-2">
      @foreach(($preview['rows'] ?? []) as $row)
        <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-600">
          <span class="font-semibold text-zinc-950">{{ strtoupper(str_replace('_', ' ', (string)($row['box_type'] ?? ''))) }}</span>
          · {{ (int)($row['box_count'] ?? 0) }}
          · {{ (string)($row['scent'] ?? 'Unknown scent') }}
        </div>
      @endforeach
    </div>
  @endif
</div>
