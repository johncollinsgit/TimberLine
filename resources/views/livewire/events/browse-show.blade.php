<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Event Instance</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">{{ $eventInstance->title }}</div>
    <div class="mt-2 text-sm text-emerald-50/70">
      {{ optional($eventInstance->starts_at)->format('M j, Y') ?? 'Date TBD' }}
      @if($eventInstance->ends_at && optional($eventInstance->ends_at)->toDateString() !== optional($eventInstance->starts_at)->toDateString())
        – {{ optional($eventInstance->ends_at)->format('M j, Y') }}
      @endif
      @if($eventInstance->state)
        · {{ $eventInstance->state }}
      @endif
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
      <div>
        <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Notes</div>
        <div class="mt-3 rounded-2xl border border-emerald-200/10 bg-black/15 p-4 text-sm text-white/75">
          {{ $eventInstance->notes ?: 'No notes imported for this event instance.' }}
        </div>
      </div>

      <div class="space-y-3">
        <div class="rounded-2xl border border-emerald-200/10 bg-black/15 p-4">
          <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/45">Total Boxes Sent</div>
          <div class="mt-2 text-2xl font-semibold text-white">{{ number_format($totalBoxesSent, 2) }}</div>
        </div>
        <div class="rounded-2xl border border-emerald-200/10 bg-black/15 p-4 text-xs text-emerald-50/75 space-y-2">
          <div><span class="text-emerald-100/45">Runner:</span> {{ $eventInstance->primary_runner ?: '—' }}</div>
          <div><span class="text-emerald-100/45">Hours:</span> {{ $eventInstance->selling_hours !== null ? number_format((float) $eventInstance->selling_hours, 2) : '—' }}</div>
          <div><span class="text-emerald-100/45">Boxes Sold:</span> {{ $eventInstance->boxes_sold !== null ? number_format((float) $eventInstance->boxes_sold, 2) : '—' }}</div>
          <div><span class="text-emerald-100/45">Source:</span> {{ $eventInstance->source_file ?: '—' }}</div>
          <div><span class="text-emerald-100/45">Sheet:</span> {{ $eventInstance->source_sheet ?: '—' }}</div>
        </div>
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Box Plan Lines</div>
    <div class="mt-4 rounded-2xl border border-emerald-200/10 overflow-hidden">
      <div class="grid grid-cols-4 gap-0 bg-black/30 px-3 py-2 text-[11px] text-white/50">
        <div>Scent</div>
        <div>Sent</div>
        <div>Returned</div>
        <div>Notes</div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($eventInstance->boxPlans as $line)
          <div class="grid grid-cols-4 gap-0 px-3 py-3 text-xs text-white/80">
            <div>{{ $line->scent_raw }} @if($line->is_split_box)<span class="text-[10px] text-amber-100/70">split</span>@endif</div>
            <div>{{ $line->box_count_sent !== null ? number_format((float) $line->box_count_sent, 2) : '—' }}</div>
            <div>{{ $line->box_count_returned !== null ? number_format((float) $line->box_count_returned, 2) : '—' }}</div>
            <div>{{ $line->line_notes ?: '—' }}</div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-white/60">No box plans imported for this event instance.</div>
        @endforelse
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Same Title Other Years</div>
    <div class="mt-4 space-y-2">
      @forelse($history as $row)
        <a href="{{ route('events.browse.show', $row) }}" class="block rounded-2xl border border-emerald-200/10 bg-black/15 px-4 py-3 text-sm text-white/80">
          {{ $row->title }} · {{ optional($row->starts_at)->format('M j, Y') ?? 'TBD' }}
        </a>
      @empty
        <div class="text-xs text-white/60">No other imported instances share this title yet.</div>
      @endforelse
    </div>
  </section>
</div>
