<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Events</div>
    <div class="mt-2 flex items-center justify-between">
      <div class="text-3xl font-['Fraunces'] font-semibold text-white">Market Events</div>
      <div class="flex items-center gap-2">
        <a href="{{ route('events.import') }}" class="rounded-full border border-white/15 bg-white/5 px-4 py-2 text-xs text-white/80">Import History</a>
        <a href="{{ route('events.create') }}" class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-4 py-2 text-xs text-white/90">New Event</a>
      </div>
    </div>
    <div class="mt-2 text-sm text-emerald-50/70">Plan, track, and learn from market history.</div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="flex flex-wrap gap-3 items-center justify-between">
      <div class="flex gap-2">
        <button wire:click="$set('filter','upcoming')" class="px-3 py-2 rounded-full text-xs border {{ $filter==='upcoming' ? 'border-emerald-300/40 bg-emerald-400/20 text-emerald-50' : 'border-white/10 text-white/70' }}">Upcoming</button>
        <button wire:click="$set('filter','past')" class="px-3 py-2 rounded-full text-xs border {{ $filter==='past' ? 'border-emerald-300/40 bg-emerald-400/20 text-emerald-50' : 'border-white/10 text-white/70' }}">Past</button>
        <button wire:click="$set('filter','all')" class="px-3 py-2 rounded-full text-xs border {{ $filter==='all' ? 'border-emerald-300/40 bg-emerald-400/20 text-emerald-50' : 'border-white/10 text-white/70' }}">All</button>
      </div>
      <input type="text" wire:model.live.debounce.250ms="search" placeholder="Search events..."
        class="h-9 rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-xs text-white/90" />
    </div>

    <div class="mt-4 rounded-2xl border border-emerald-200/10 overflow-hidden">
      <div class="grid grid-cols-8 gap-0 bg-black/30 text-[11px] text-white/50 px-3 py-2">
        <div>Name</div>
        <div>Dates</div>
        <div>Due</div>
        <div>Ship</div>
        <div>Sent</div>
        <div>Returned</div>
        <div>Status</div>
        <div></div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($events as $event)
          <div class="grid grid-cols-8 gap-0 px-3 py-2 text-xs text-white/80">
            <div class="font-semibold">{{ $event->name }}</div>
            <div>
              <div>{{ optional($event->starts_at)->format('M j, Y') }}</div>
              <div class="text-[11px] text-white/40">{{ optional($event->ends_at)->format('M j, Y') }}</div>
            </div>
            <div>{{ optional($event->due_date)->format('M j, Y') ?? '—' }}</div>
            <div>{{ optional($event->ship_date)->format('M j, Y') ?? '—' }}</div>
            <div>{{ (int) ($event->sent_total ?? 0) }}</div>
            <div>{{ (int) ($event->returned_total ?? 0) }}</div>
            <div class="text-white/60">{{ $event->status }}</div>
            <div><a href="{{ route('events.show', $event) }}" class="text-emerald-100/80 underline">Open</a></div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-white/60">No events yet.</div>
        @endforelse
      </div>
    </div>

    <div class="mt-4">{{ $events->links() }}</div>
  </section>
</div>
