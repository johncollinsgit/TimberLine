<x-layouts::app :title="'Show text results · '.$event->title">
    @php($eventRoute = ['id' => $event->id, 'tenant' => $tenant->slug])
    @php($resultsRoute = $eventRoute + ['campaign' => $run->id])
    <main class="mx-auto max-w-4xl space-y-6 px-4 py-6 sm:px-6">
        <header class="space-y-2">
            <a class="text-sm underline" href="{{ route('team.events.show-text', $eventRoute + ($backToReview ? ['review' => $backToReview] : ['edit' => 1])) }}">{{ $backToReview ? 'Back to review' : 'Back to show text' }}</a>
            <h1 class="text-3xl font-semibold">{{ data_get($run->target_snapshot, 'team_show_text.preview') ? 'Preview delivery' : 'Show text results' }}</h1>
            <p>{{ $event->title }}</p>
        </header>
        <nav aria-label="Delivery filters" class="flex flex-wrap gap-2">
            <a class="eb-secondary-button" @if($filter === '') aria-current="page" @endif href="{{ route('team.events.show-text.results', $resultsRoute) }}">All customers</a>
            @foreach($counts as $label => $count)
                <a class="eb-secondary-button" @if($filter === $label) aria-current="page" @endif href="{{ route('team.events.show-text.results', $resultsRoute + ['status' => $label]) }}">{{ $label }} {{ $count }}</a>
            @endforeach
        </nav>
        @if($purchases > 0)<p>{{ $purchases }} attributed {{ $purchases === 1 ? 'purchase' : 'purchases' }}</p>@endif
        @if($counts['Queued'] || $counts['Sent'])
            <a class="text-sm underline" href="{{ request()->fullUrl() }}">Refresh delivery status</a>
        @endif
        <ul class="divide-y divide-zinc-200 rounded-2xl border border-zinc-300">
            @forelse($page as $recipient)
                <li class="flex flex-wrap items-start justify-between gap-2 p-4">
                    <div>
                        <strong>{{ trim($recipient->profile->first_name.' '.$recipient->profile->last_name) ?: 'Customer' }}</strong>
                        <span class="text-sm">•••• {{ substr(data_get($recipient->recommendation_snapshot, 'team_show_text_phone', ''), -4) }}</span>
                        @if($statuses[$recipient->id] === 'Failed' && $recipient->last_status_note)
                            <p class="break-words text-sm">{{ $recipient->last_status_note }}</p>
                        @endif
                    </div>
                    <div class="space-x-2 text-sm">
                        <span>{{ $statuses[$recipient->id] }}</span>
                        @if(isset($stopped[$recipient->id]))<span>Opted out</span>@endif
                        @if(isset($replyByRecipient[$recipient->id]))
                            <a class="underline" href="{{ route('marketing.desk.index', ['tenant' => $tenant->slug, 'view' => 'inbox', 'conversation' => $replyByRecipient[$recipient->id]]) }}">View reply</a>
                        @endif
                    </div>
                </li>
            @empty
                <li class="p-4">No customers in this view.</li>
            @endforelse
        </ul>
        {{ $page->links() }}
    </main>
</x-layouts::app>
