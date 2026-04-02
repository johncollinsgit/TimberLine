<div class="space-y-6">
  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Event Instance</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">{{ $eventInstance->title }}</div>
    <div class="mt-2 text-sm text-zinc-600">
      {{ optional($eventInstance->starts_at)->format('M j, Y') ?? 'Date TBD' }}
      @if($eventInstance->ends_at && optional($eventInstance->ends_at)->toDateString() !== optional($eventInstance->starts_at)->toDateString())
        – {{ optional($eventInstance->ends_at)->format('M j, Y') }}
      @endif
      @if($eventInstance->state)
        · {{ $eventInstance->state }}
      @endif
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
      <div>
        <div class="text-xs uppercase tracking-[0.22em] text-emerald-800">Notes</div>
        <div class="mt-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-700">
          {{ $eventInstance->notes ?: 'No notes imported for this event instance.' }}
        </div>
      </div>

      <div class="space-y-3">
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
          <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-800">Total Boxes Sent</div>
          <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format($totalBoxesSent, 2) }}</div>
        </div>
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-xs text-zinc-600 space-y-2">
          <div><span class="text-emerald-800">Runner:</span> {{ $eventInstance->primary_runner ?: '—' }}</div>
          <div><span class="text-emerald-800">Hours:</span> {{ $eventInstance->selling_hours !== null ? number_format((float) $eventInstance->selling_hours, 2) : '—' }}</div>
          <div><span class="text-emerald-800">Boxes Sold:</span> {{ $eventInstance->boxes_sold !== null ? number_format((float) $eventInstance->boxes_sold, 2) : '—' }}</div>
          <div><span class="text-emerald-800">Source:</span> {{ $eventInstance->source_file ?: '—' }}</div>
          <div><span class="text-emerald-800">Sheet:</span> {{ $eventInstance->source_sheet ?: '—' }}</div>
        </div>
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-xs uppercase tracking-[0.22em] text-emerald-800">Box Plan Lines</div>
    <div class="mt-4 rounded-2xl border border-zinc-200 overflow-hidden">
      <div class="grid grid-cols-4 gap-0 bg-zinc-50 px-3 py-2 text-[11px] text-zinc-500">
        <div>Scent</div>
        <div>Sent</div>
        <div>Returned</div>
        <div>Notes</div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($eventInstance->boxPlans as $line)
          <div class="grid grid-cols-4 gap-0 px-3 py-3 text-xs text-zinc-700">
            <div>{{ $line->scent_raw }} @if($line->is_split_box)<span class="text-[10px] text-amber-800">split</span>@endif</div>
            <div>{{ $line->box_count_sent !== null ? number_format((float) $line->box_count_sent, 2) : '—' }}</div>
            <div>{{ $line->box_count_returned !== null ? number_format((float) $line->box_count_returned, 2) : '—' }}</div>
            <div>{{ $line->line_notes ?: '—' }}</div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-zinc-500">No box plans imported for this event instance.</div>
        @endforelse
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-xs uppercase tracking-[0.22em] text-emerald-800">Same Title Other Years</div>
    <div class="mt-4 space-y-2">
      @forelse($history as $row)
        <a href="{{ route('events.browse.show', $row) }}" class="block rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700">
          {{ $row->title }} · {{ optional($row->starts_at)->format('M j, Y') ?? 'TBD' }}
        </a>
      @empty
        <div class="text-xs text-zinc-500">No other imported instances share this title yet.</div>
      @endforelse
    </div>
  </section>
</div>
