<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Events</div>
    <div class="mt-2 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
      <div>
        <div class="text-3xl font-['Fraunces'] font-semibold text-white">Browse Event Instances</div>
        <div class="mt-2 text-sm text-emerald-50/70">Historical market event instances and their imported box-plan lines.</div>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="{{ route('events.import-market-box-plans') }}" class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-4 py-2 text-xs text-white/90">Import Market Box Plans</a>
        <a href="{{ route('events.import') }}" class="rounded-full border border-white/15 bg-white/5 px-4 py-2 text-xs text-white/80">Legacy Shipment Import</a>
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="grid gap-3 md:grid-cols-4">
      <input type="text" wire:model.live.debounce.250ms="search" placeholder="Search title..."
        class="h-10 rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-xs text-white/90" />

      <select wire:model.live="year" class="h-10 rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-xs text-white/90">
        <option value="all">All years</option>
        @foreach($years as $yearOption)
          <option value="{{ $yearOption }}">{{ $yearOption }}</option>
        @endforeach
      </select>

      <select wire:model.live="state" class="h-10 rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-xs text-white/90">
        <option value="all">All states</option>
        @foreach($states as $stateOption)
          <option value="{{ $stateOption }}">{{ $stateOption }}</option>
        @endforeach
      </select>

      <select wire:model.live="status" class="h-10 rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-xs text-white/90">
        <option value="all">All statuses</option>
        <option value="planned">Planned</option>
        <option value="active">Active</option>
        <option value="completed">Completed</option>
        <option value="unknown">Unknown</option>
      </select>
    </div>

    <div class="mt-4 rounded-2xl border border-emerald-200/10 overflow-hidden">
      <div class="grid grid-cols-5 gap-0 bg-black/30 text-[11px] text-white/50 px-3 py-2">
        <div>Title</div>
        <div>Date Range</div>
        <div>Status</div>
        <div>Boxes Sent</div>
        <div></div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($events as $event)
          <div class="grid grid-cols-5 gap-0 px-3 py-3 text-xs text-white/80">
            <div class="font-semibold">{{ $event->title }}</div>
            <div>
              {{ optional($event->starts_at)->format('M j, Y') ?? 'TBD' }}
              @if($event->ends_at && optional($event->ends_at)->toDateString() !== optional($event->starts_at)->toDateString())
                <span class="block text-[11px] text-white/45">{{ optional($event->ends_at)->format('M j, Y') }}</span>
              @endif
            </div>
            <div class="text-white/60">{{ $event->status }}</div>
            <div>{{ number_format((float) ($event->total_boxes_sent ?? 0), 2) }}</div>
            <div><a href="{{ route('events.browse.show', $event) }}" class="text-emerald-100/80 underline">Open</a></div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-white/60">No imported event instances yet.</div>
        @endforelse
      </div>
    </div>

    <div class="mt-4">{{ $events->links() }}</div>
  </section>
</div>
