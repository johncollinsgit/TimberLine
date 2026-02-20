<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Market Pour Lists</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Market Pour Lists</div>
    <div class="mt-2 text-sm text-emerald-50/70">Drafts and published market pour requests.</div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="flex justify-end">
      <a href="{{ route('markets.lists.create') }}" class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-4 py-2 text-xs text-white/90">New Market Pour List</a>
    </div>
    <div class="mt-4 rounded-2xl border border-emerald-200/10 overflow-hidden">
      <div class="grid grid-cols-5 gap-0 bg-black/30 text-[11px] text-white/50 px-3 py-2">
        <div>Title</div>
        <div>Status</div>
        <div>Events</div>
        <div>Generated</div>
        <div></div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($lists as $list)
          <div class="grid grid-cols-5 gap-0 px-3 py-2 text-xs text-white/80">
            <div class="font-semibold">{{ $list->title }}</div>
            <div>{{ $list->status }}</div>
            <div>{{ $list->events_count }}</div>
            <div>{{ $list->generated_at }}</div>
            <div><a href="{{ route('markets.lists.show', $list) }}" class="text-emerald-100/80 underline">Open</a></div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-white/60">No lists yet.</div>
        @endforelse
      </div>
    </div>
    <div class="mt-4">{{ $lists->links() }}</div>
  </section>
</div>
