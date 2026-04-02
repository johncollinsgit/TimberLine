<div class="space-y-6">
  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Events</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">New Event</div>
    <div class="mt-2 text-sm text-zinc-600">Create a new market event and set planning dates.</div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
      <input type="text" wire:model="name" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" placeholder="Event name" />
      <input type="text" wire:model="venue" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" placeholder="Venue" />
      <input type="text" wire:model="city" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" placeholder="City" />
      <input type="text" wire:model="state" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" placeholder="State" />
      <input type="date" wire:model="starts_at" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" />
      <input type="date" wire:model="ends_at" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" />
      <input type="date" wire:model="due_date" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" />
      <input type="date" wire:model="ship_date" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" />
      <select wire:model="status" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900">
        <option value="planned">Planned</option>
        <option value="active">Active</option>
        <option value="completed">Completed</option>
        <option value="archived">Archived</option>
      </select>
    </div>
    <textarea wire:model="notes" class="mt-3 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" rows="3" placeholder="Notes"></textarea>
    <div class="mt-4">
      <button wire:click="save" class="rounded-full border border-emerald-400/25 bg-emerald-100 px-4 py-2 text-xs text-zinc-900">Create Event</button>
    </div>
  </section>
</div>
