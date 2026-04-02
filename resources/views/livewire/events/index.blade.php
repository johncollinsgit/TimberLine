<div class="space-y-6">
  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Events</div>
    <div class="mt-2 flex items-center justify-between">
      <div class="text-3xl font-['Fraunces'] font-semibold text-zinc-950">Market Events</div>
      <div class="flex items-center gap-2">
        <a href="{{ route('events.browse') }}" class="rounded-full border border-emerald-400/25 bg-emerald-100 px-4 py-2 text-xs text-zinc-900">Browse Box Plans</a>
        <a href="{{ route('events.import-market-box-plans') }}" class="rounded-full border border-zinc-300 bg-zinc-50 px-4 py-2 text-xs text-zinc-700">Import Box Plans</a>
        <a href="{{ route('events.import') }}" class="rounded-full border border-zinc-300 bg-zinc-50 px-4 py-2 text-xs text-zinc-700">Import History</a>
        <a href="{{ route('events.create') }}" class="rounded-full border border-emerald-400/25 bg-emerald-100 px-4 py-2 text-xs text-zinc-900">New Event</a>
      </div>
    </div>
    <div class="mt-2 text-sm text-zinc-600">Plan, track, and learn from market history.</div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="flex flex-wrap gap-3 items-center justify-between">
      <div class="flex gap-2">
        <button wire:click="$set('filter','upcoming')" class="px-3 py-2 rounded-full text-xs border {{ $filter==='upcoming' ? 'border-emerald-300/40 bg-emerald-100 text-emerald-900' : 'border-zinc-200 text-zinc-600' }}">Upcoming</button>
        <button wire:click="$set('filter','past')" class="px-3 py-2 rounded-full text-xs border {{ $filter==='past' ? 'border-emerald-300/40 bg-emerald-100 text-emerald-900' : 'border-zinc-200 text-zinc-600' }}">Past</button>
        <button wire:click="$set('filter','all')" class="px-3 py-2 rounded-full text-xs border {{ $filter==='all' ? 'border-emerald-300/40 bg-emerald-100 text-emerald-900' : 'border-zinc-200 text-zinc-600' }}">All</button>
      </div>
      <input type="text" wire:model.live.debounce.250ms="search" placeholder="Search events..."
        class="h-9 rounded-2xl border border-zinc-200 bg-zinc-50 px-3 text-xs text-zinc-900" />
    </div>

    <div class="mt-4 rounded-2xl border border-zinc-200 overflow-hidden">
      <div class="grid grid-cols-8 gap-0 bg-zinc-50 text-[11px] text-zinc-500 px-3 py-2">
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
          <div class="grid grid-cols-8 gap-0 px-3 py-2 text-xs text-zinc-700">
            <div class="font-semibold">{{ $event->name }}</div>
            <div>
              <div>{{ optional($event->starts_at)->format('M j, Y') }}</div>
              <div class="text-[11px] text-zinc-500">{{ optional($event->ends_at)->format('M j, Y') }}</div>
            </div>
            <div>{{ optional($event->due_date)->format('M j, Y') ?? '—' }}</div>
            <div>{{ optional($event->ship_date)->format('M j, Y') ?? '—' }}</div>
            <div>{{ (int) ($event->sent_total ?? 0) }}</div>
            <div>{{ (int) ($event->returned_total ?? 0) }}</div>
            <div class="text-zinc-500">{{ $event->status }}</div>
            <div><a href="{{ route('events.show', $event) }}" class="text-emerald-800 underline">Open</a></div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-zinc-500">No events yet.</div>
        @endforelse
      </div>
    </div>

    <div class="mt-4">{{ $events->links() }}</div>
  </section>
</div>
