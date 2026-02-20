<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Events</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">New Event</div>
    <div class="mt-2 text-sm text-emerald-50/70">Create a new market event and set planning dates.</div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
      <input type="text" wire:model="name" class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90" placeholder="Event name" />
      <input type="text" wire:model="venue" class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90" placeholder="Venue" />
      <input type="text" wire:model="city" class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90" placeholder="City" />
      <input type="text" wire:model="state" class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90" placeholder="State" />
      <input type="date" wire:model="starts_at" class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90" />
      <input type="date" wire:model="ends_at" class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90" />
      <input type="date" wire:model="due_date" class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90" />
      <input type="date" wire:model="ship_date" class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90" />
      <select wire:model="status" class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90">
        <option value="planned">Planned</option>
        <option value="active">Active</option>
        <option value="completed">Completed</option>
        <option value="archived">Archived</option>
      </select>
    </div>
    <textarea wire:model="notes" class="mt-3 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90" rows="3" placeholder="Notes"></textarea>
    <div class="mt-4">
      <button wire:click="save" class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-4 py-2 text-xs text-white/90">Create Event</button>
    </div>
  </section>
</div>
