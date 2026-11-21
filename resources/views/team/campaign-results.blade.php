<x-layouts::app :title="$results['name']">
    <main class="mx-auto max-w-6xl space-y-5 px-4 py-6">
        <header><p class="text-sm text-zinc-500">{{ $tenant->name }} · {{ $event->title }}</p><h1 class="mt-2 text-2xl font-semibold">{{ $results['name'] }}</h1><p class="mt-2">{{ $results['activity_status'] }}</p></header>
        @include('marketing.campaigns.purchase-results')
        <a class="inline-flex min-h-11 items-center underline" href="{{ route('team.app', ['tenant' => $tenant->slug, 'area' => $event->area_id, 'area_view' => 'events', 'event_id' => $event->id]) }}">Back to the show in Teams</a>
    </main>
</x-layouts::app>
