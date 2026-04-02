<div class="space-y-6 min-w-0">
  <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div class="min-w-0">
        <div class="text-[11px] uppercase tracking-[0.3em] text-zinc-500">Market Event</div>
        <h1 class="mt-2 text-2xl sm:text-3xl font-semibold text-zinc-950">{{ $event->display_name ?: $event->name }}</h1>
        <p class="mt-2 text-sm text-zinc-600">
          {{ $event->market?->name ?? 'Unlinked Market' }}
          @if($event->starts_at)
            <span class="text-zinc-500">·</span>{{ $event->starts_at->format('M j, Y') }}
            @if($event->ends_at && !$event->ends_at->isSameDay($event->starts_at))
              - {{ $event->ends_at->format('M j, Y') }}
            @endif
          @endif
          @if($event->city || $event->state)
            <span class="text-zinc-500">·</span>{{ trim(collect([$event->city, $event->state])->filter()->implode(', ')) }}
          @endif
          @if($event->venue)
            <span class="text-zinc-500">·</span>{{ $event->venue }}
          @endif
        </p>
        <div class="mt-3 flex flex-wrap items-center gap-2">
          <span class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-[11px] text-zinc-700">Source: {{ $event->source ?: 'manual' }}</span>
          @if(!blank($event->parse_confidence))
            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-[11px] text-zinc-700">Parse: {{ ucfirst((string) $event->parse_confidence) }}</span>
          @endif
          @if($event->needs_review)
            <span class="rounded-full border border-amber-300/30 bg-amber-100 px-3 py-1 text-[11px] font-semibold text-amber-900">Needs Review</span>
          @endif
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        @if((auth()->user()?->role ?? null) === 'admin')
          <button type="button" wire:click="{{ $editing ? 'cancelEdit' : 'startEdit' }}" class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
            {{ $editing ? 'Cancel edit' : 'Edit event' }}
          </button>
        @endif
        @if($event->market)
          <a href="{{ route('markets.browser.market', $event->market) }}" class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">View market history</a>
        @endif
        @if($event->year)
          <a href="{{ route('markets.browser.year', ['year' => $event->year]) }}" class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">View year list</a>
        @endif
        @if($draftList)
          <a href="{{ route('markets.lists.show', $draftList) }}" class="rounded-full border border-emerald-300/20 bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">Open Draft Pour List</a>
        @endif
      </div>
    </div>
  </section>

  @if(session('status'))
    <div class="rounded-2xl border border-emerald-300/20 bg-emerald-100 px-4 py-3 text-sm text-emerald-800">
      {{ session('status') }}
    </div>
  @endif

  @if($editing && (auth()->user()?->role ?? null) === 'admin')
    <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-4 sm:p-5">
      <div class="flex items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-zinc-950">Edit Market Event</h2>
        <button type="button" wire:click="cancelEdit" class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Cancel</button>
      </div>

      <form wire:submit="saveEvent" class="mt-4 space-y-4">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <label class="block">
            <span class="text-xs uppercase tracking-[0.18em] text-zinc-500">Market Name</span>
            <input type="text" wire:model.defer="market_name" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 placeholder:text-zinc-500 focus:outline-none focus:ring-2 focus:ring-white/15">
            @error('market_name') <span class="mt-1 block text-xs text-rose-200">{{ $message }}</span> @enderror
          </label>
          <label class="block">
            <span class="text-xs uppercase tracking-[0.18em] text-zinc-500">Event Name</span>
            <input type="text" wire:model.defer="event_name" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 placeholder:text-zinc-500 focus:outline-none focus:ring-2 focus:ring-white/15">
            @error('event_name') <span class="mt-1 block text-xs text-rose-200">{{ $message }}</span> @enderror
          </label>
          <label class="block md:col-span-2">
            <span class="text-xs uppercase tracking-[0.18em] text-zinc-500">Imported / Display Title</span>
            <input type="text" wire:model.defer="display_name" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 placeholder:text-zinc-500 focus:outline-none focus:ring-2 focus:ring-white/15">
            @error('display_name') <span class="mt-1 block text-xs text-rose-200">{{ $message }}</span> @enderror
          </label>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
          <label class="block">
            <span class="text-xs uppercase tracking-[0.18em] text-zinc-500">Year</span>
            <input type="number" wire:model.defer="year" min="2020" max="2100" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 focus:outline-none focus:ring-2 focus:ring-white/15">
            @error('year') <span class="mt-1 block text-xs text-rose-200">{{ $message }}</span> @enderror
          </label>
          <label class="block">
            <span class="text-xs uppercase tracking-[0.18em] text-zinc-500">Start Date</span>
            <input type="date" wire:model.defer="starts_at" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 focus:outline-none focus:ring-2 focus:ring-white/15">
            @error('starts_at') <span class="mt-1 block text-xs text-rose-200">{{ $message }}</span> @enderror
          </label>
          <label class="block">
            <span class="text-xs uppercase tracking-[0.18em] text-zinc-500">End Date</span>
            <input type="date" wire:model.defer="ends_at" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 focus:outline-none focus:ring-2 focus:ring-white/15">
            @error('ends_at') <span class="mt-1 block text-xs text-rose-200">{{ $message }}</span> @enderror
          </label>
          <label class="block">
            <span class="text-xs uppercase tracking-[0.18em] text-zinc-500">Status</span>
            <select wire:model.defer="status" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 focus:outline-none focus:ring-2 focus:ring-white/15">
              <option value="planned">planned</option>
              <option value="confirmed">confirmed</option>
              <option value="completed">completed</option>
              <option value="cancelled">cancelled</option>
            </select>
            @error('status') <span class="mt-1 block text-xs text-rose-200">{{ $message }}</span> @enderror
          </label>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
          <label class="block">
            <span class="text-xs uppercase tracking-[0.18em] text-zinc-500">City</span>
            <input type="text" wire:model.defer="city" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 focus:outline-none focus:ring-2 focus:ring-white/15">
            @error('city') <span class="mt-1 block text-xs text-rose-200">{{ $message }}</span> @enderror
          </label>
          <label class="block">
            <span class="text-xs uppercase tracking-[0.18em] text-zinc-500">State</span>
            <input type="text" wire:model.defer="state" maxlength="2" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm uppercase text-zinc-950 focus:outline-none focus:ring-2 focus:ring-white/15">
            @error('state') <span class="mt-1 block text-xs text-rose-200">{{ $message }}</span> @enderror
          </label>
          <label class="block">
            <span class="text-xs uppercase tracking-[0.18em] text-zinc-500">Venue</span>
            <input type="text" wire:model.defer="venue" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 focus:outline-none focus:ring-2 focus:ring-white/15">
            @error('venue') <span class="mt-1 block text-xs text-rose-200">{{ $message }}</span> @enderror
          </label>
        </div>

        <label class="block">
          <span class="text-xs uppercase tracking-[0.18em] text-zinc-500">Notes</span>
          <textarea wire:model.defer="notes" rows="4" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 focus:outline-none focus:ring-2 focus:ring-white/15"></textarea>
          @error('notes') <span class="mt-1 block text-xs text-rose-200">{{ $message }}</span> @enderror
        </label>

        <label class="inline-flex items-center gap-2 text-sm text-zinc-700">
          <input type="checkbox" wire:model.defer="needs_review" class="rounded border-zinc-300 bg-zinc-50">
          Needs review
        </label>

        <div class="flex flex-wrap items-center gap-2">
          <button type="submit" class="rounded-full border border-emerald-300/20 bg-emerald-100 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">
            Save event
          </button>
          <button type="button" wire:click="cancelEdit" class="rounded-full border border-zinc-200 bg-zinc-50 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100">
            Cancel
          </button>
        </div>
      </form>
    </section>
  @endif

  <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-4 sm:p-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h2 class="text-lg font-semibold text-zinc-950">Imported Box Lines / Scent Notes</h2>
        <div class="mt-1 text-sm text-zinc-600">{{ $boxLines->count() }} rows imported</div>
      </div>
      <div class="flex items-center gap-2">
        <div class="rounded-2xl border border-emerald-300/20 bg-emerald-100 px-4 py-2 text-right">
          <div class="text-[10px] uppercase tracking-[0.18em] text-emerald-800">Total Market Boxes</div>
          <div class="mt-1 text-xl sm:text-2xl font-bold text-emerald-900">{{ number_format((int) $boxQtyTotal) }}</div>
        </div>
      </div>
    </div>
    <div class="mt-4 overflow-x-auto rounded-2xl border border-zinc-200">
      <table class="min-w-full text-sm">
        <thead class="bg-zinc-50 text-zinc-600">
          <tr>
            <th class="px-3 py-2 text-left">Item Type</th>
            <th class="px-3 py-2 text-left">Product / SKU</th>
            <th class="px-3 py-2 text-left">Scent</th>
            <th class="px-3 py-2 text-left">Size</th>
            <th class="px-3 py-2 text-right">Qty</th>
            <th class="px-3 py-2 text-left">Notes</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200">
          @forelse($boxLines as $line)
            <tr class="hover:bg-zinc-50">
              <td class="px-3 py-2 text-zinc-800">{{ $line->item_type ?: '—' }}</td>
              <td class="px-3 py-2 text-zinc-700">{{ $line->product_key ?: ($line->sku ?: '—') }}</td>
              <td class="px-3 py-2 text-zinc-700">{{ $line->scent ?: '—' }}</td>
              <td class="px-3 py-2 text-zinc-700">{{ $line->size ?: '—' }}</td>
              <td class="px-3 py-2 text-right text-zinc-900">{{ (int) $line->qty }}</td>
              <td class="px-3 py-2 text-zinc-600">{{ $line->notes ?: '—' }}</td>
            </tr>
          @empty
            <tr><td colspan="6" class="px-3 py-4 text-zinc-500">No imported box lines for this event yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-4 sm:p-5">
    <h2 class="text-lg font-semibold text-zinc-950">Notes + Source</h2>
    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
      <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-700">
        <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Source</div>
        <div class="mt-1">{{ $event->source ?: 'manual' }}</div>
      </div>
      <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-700">
        <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Source Ref</div>
        <div class="mt-1 break-words">{{ $event->source_ref ?: '—' }}</div>
      </div>
      <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-700">
        <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Status</div>
        <div class="mt-1">{{ ucfirst((string) ($event->status ?: 'planned')) }}</div>
      </div>
    </div>
    @if(!blank($event->notes))
      <div class="mt-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-700 whitespace-pre-wrap">{{ $event->notes }}</div>
    @endif
    @if(!empty($event->parse_notes_json))
      <div class="mt-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
        <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Parser Details</div>
        <div class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-2 text-xs text-zinc-600">
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
