<div class="space-y-6 min-w-0">
  <section class="rounded-3xl border border-white/10 bg-white/5 p-5 sm:p-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div class="min-w-0">
        <div class="text-[11px] uppercase tracking-[0.3em] text-white/55">Market Event</div>
        <h1 class="mt-2 text-2xl sm:text-3xl font-semibold text-white">{{ $event->display_name ?: $event->name }}</h1>
        <p class="mt-2 text-sm text-white/65">
          {{ $event->market?->name ?? 'Unlinked Market' }}
          @if($event->starts_at)
            <span class="text-white/35">·</span>{{ $event->starts_at->format('M j, Y') }}
            @if($event->ends_at && !$event->ends_at->isSameDay($event->starts_at))
              - {{ $event->ends_at->format('M j, Y') }}
            @endif
          @endif
          @if($event->city || $event->state)
            <span class="text-white/35">·</span>{{ trim(collect([$event->city, $event->state])->filter()->implode(', ')) }}
          @endif
          @if($event->venue)
            <span class="text-white/35">·</span>{{ $event->venue }}
          @endif
        </p>
        <div class="mt-3 flex flex-wrap items-center gap-2">
          <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] text-white/75">Source: {{ $event->source ?: 'manual' }}</span>
          @if(!blank($event->parse_confidence))
            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] text-white/75">Parse: {{ ucfirst((string) $event->parse_confidence) }}</span>
          @endif
          @if($event->needs_review)
            <span class="rounded-full border border-amber-300/30 bg-amber-400/15 px-3 py-1 text-[11px] font-semibold text-amber-50">Needs Review</span>
          @endif
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        @if($event->market)
          <a href="{{ route('markets.browser.market', $event->market) }}" class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10">View market history</a>
        @endif
        @if($event->year)
          <a href="{{ route('markets.browser.year', ['year' => $event->year]) }}" class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10">View year list</a>
        @endif
        @if($draftList)
          <a href="{{ route('markets.lists.show', $draftList) }}" class="rounded-full border border-emerald-300/20 bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-emerald-100/90 hover:bg-emerald-500/15">Open Draft Pour List</a>
        @endif
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-white/10 bg-white/5 p-4 sm:p-5">
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-lg font-semibold text-white">Imported Box Lines / Scent Notes</h2>
      <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-white/70">{{ $boxLines->count() }} rows</span>
    </div>
    <div class="mt-4 overflow-x-auto rounded-2xl border border-white/10">
      <table class="min-w-full text-sm">
        <thead class="bg-white/5 text-white/70">
          <tr>
            <th class="px-3 py-2 text-left">Item Type</th>
            <th class="px-3 py-2 text-left">Product / SKU</th>
            <th class="px-3 py-2 text-left">Scent</th>
            <th class="px-3 py-2 text-left">Size</th>
            <th class="px-3 py-2 text-right">Qty</th>
            <th class="px-3 py-2 text-left">Notes</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-white/10">
          @forelse($boxLines as $line)
            <tr class="hover:bg-white/5">
              <td class="px-3 py-2 text-white/85">{{ $line->item_type ?: '—' }}</td>
              <td class="px-3 py-2 text-white/80">{{ $line->product_key ?: ($line->sku ?: '—') }}</td>
              <td class="px-3 py-2 text-white/80">{{ $line->scent ?: '—' }}</td>
              <td class="px-3 py-2 text-white/80">{{ $line->size ?: '—' }}</td>
              <td class="px-3 py-2 text-right text-white/90">{{ (int) $line->qty }}</td>
              <td class="px-3 py-2 text-white/70">{{ $line->notes ?: '—' }}</td>
            </tr>
          @empty
            <tr><td colspan="6" class="px-3 py-4 text-white/50">No imported box lines for this event yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>

  <section class="rounded-3xl border border-white/10 bg-white/5 p-4 sm:p-5">
    <h2 class="text-lg font-semibold text-white">Notes + Source</h2>
    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
      <div class="rounded-2xl border border-white/10 bg-white/5 p-3 text-sm text-white/75">
        <div class="text-xs uppercase tracking-[0.2em] text-white/50">Source</div>
        <div class="mt-1">{{ $event->source ?: 'manual' }}</div>
      </div>
      <div class="rounded-2xl border border-white/10 bg-white/5 p-3 text-sm text-white/75">
        <div class="text-xs uppercase tracking-[0.2em] text-white/50">Source Ref</div>
        <div class="mt-1 break-words">{{ $event->source_ref ?: '—' }}</div>
      </div>
      <div class="rounded-2xl border border-white/10 bg-white/5 p-3 text-sm text-white/75">
        <div class="text-xs uppercase tracking-[0.2em] text-white/50">Status</div>
        <div class="mt-1">{{ ucfirst((string) ($event->status ?: 'planned')) }}</div>
      </div>
    </div>
    @if(!blank($event->notes))
      <div class="mt-3 rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-white/80 whitespace-pre-wrap">{{ $event->notes }}</div>
    @endif
    @if(!empty($event->parse_notes_json))
      <div class="mt-3 rounded-2xl border border-white/10 bg-white/5 p-4">
        <div class="text-xs uppercase tracking-[0.2em] text-white/50">Parser Details</div>
        <div class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-2 text-xs text-white/70">
          <div>Date confidence: {{ $event->parse_notes_json['date_confidence'] ?? '—' }}</div>
          <div>Location confidence: {{ $event->parse_notes_json['location_confidence'] ?? '—' }}</div>
          @if(!blank($event->parse_notes_json['date_parse_notes'] ?? null))
            <div class="md:col-span-2">Date parse notes: {{ $event->parse_notes_json['date_parse_notes'] }}</div>
          @endif
          @if(!blank($event->parse_notes_json['location_parse_notes'] ?? null))
            <div class="md:col-span-2">Location parse notes: {{ $event->parse_notes_json['location_parse_notes'] }}</div>
          @endif
          @if(!empty($event->parse_notes_json['notes']) && is_array($event->parse_notes_json['notes']))
            <div class="md:col-span-2">
              <div class="mb-1">Notes:</div>
              <ul class="list-disc pl-5 space-y-1">
                @foreach($event->parse_notes_json['notes'] as $note)
                  <li>{{ $note }}</li>
                @endforeach
              </ul>
            </div>
          @endif
        </div>
      </div>
    @endif
  </section>
</div>
