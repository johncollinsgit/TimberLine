<div class="space-y-6">
  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Market Pour Lists</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">Market Pour Lists</div>
    <div class="mt-2 text-sm text-zinc-600">Drafts and published market pour requests.</div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="flex justify-end">
      <a href="{{ route('markets.lists.create') }}" class="rounded-full border border-emerald-400/25 bg-emerald-100 px-4 py-2 text-xs text-zinc-900">New Market Pour List</a>
    </div>
    <div class="mt-4 rounded-2xl border border-zinc-200 overflow-hidden">
      <div class="grid grid-cols-5 gap-0 bg-zinc-50 text-[11px] text-zinc-500 px-3 py-2">
        <div>Title</div>
        <div>Status</div>
        <div>Events</div>
        <div>Generated</div>
        <div></div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($lists as $list)
          <div class="grid grid-cols-5 gap-0 px-3 py-2 text-xs text-zinc-700">
            <div class="font-semibold">{{ $list->title }}</div>
            <div>{{ $list->status }}</div>
            <div>{{ $list->events_count }}</div>
            <div>{{ $list->generated_at }}</div>
            <div><a href="{{ route('markets.lists.show', $list) }}" class="text-emerald-800 underline">Open</a></div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-zinc-500">No lists yet.</div>
        @endforelse
      </div>
    </div>
    <div class="mt-4">{{ $lists->links() }}</div>
  </section>
</div>
