<x-layouts::app :title="$label">
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <header>
            <p class="text-sm text-zinc-500">Everbranch weekly recap · {{ $tenant->name }}</p>
            <h1 class="mt-2 text-2xl font-semibold">{{ $label }}</h1>
            <p class="mt-2 text-sm text-zinc-500">{{ $windowLabel }}</p>
        </header>
        <nav aria-label="Recap activity" class="flex flex-wrap gap-2">
            @foreach($links as $key => $link)
                <a href="{{ $link }}" @if($key === $metric) aria-current="page" @endif class="rounded-lg border px-3 py-2 text-sm {{ $key === $metric ? 'bg-emerald-800 text-white' : 'border-zinc-300' }}">{{ \App\Services\Marketing\WeeklyRecapActivityService::METRICS[$key] }}</a>
            @endforeach
        </nav>
        <p class="text-sm">{{ number_format($rows->total()) }} {{ $metric === 'unsubscribed' || $metric === 'customers_added' ? 'customers' : 'records' }} in this reporting window.</p>
        @if($amount !== null)<p class="text-sm font-semibold">{{ str_starts_with($metric, 'birthday_') ? 'Discount face value' : 'Candle Cash amount' }}: ${{ number_format($amount, 2) }}</p>@endif
        @if(in_array($metric, ['emails_sent', 'emails_failed']))
            <p class="text-sm text-zinc-500">Sent means accepted by the email provider. Delivery, opens, clicks and failures appear below as they are reported. Permanent address failures, spam complaints and unsubscribes stop future marketing sends. Temporary or sender problems preserve eligibility.</p>
        @elseif(str_contains($metric, 'redeemed'))
            <p class="text-sm text-zinc-500">These are recorded redemptions. Applying a code alone is not counted as a redemption.</p>
        @endif
        <div class="space-y-3">
            @forelse($rows as $row)
                <article class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex flex-wrap justify-between gap-3">
                        <div>
                            @if($row['profile_url']) <a class="font-semibold text-emerald-700 underline" href="{{ $row['profile_url'] }}">{{ $row['name'] }}</a> @else <strong>{{ $row['name'] }}</strong> @endif
                            <p class="mt-1 text-sm">{{ $row['email'] ?: 'Guest / no email recorded' }}</p>
                        </div>
                        <div class="text-sm"><strong>{{ ucfirst($row['status'] ?: 'Recorded') }}</strong><p class="mt-1 text-zinc-500">{{ $row['at'] }}</p></div>
                    </div>
                    <p class="mt-3 text-sm">{{ $row['detail'] }}</p>
                    @if(isset($row['future_sends']))
                        <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                            <div><dt class="text-zinc-500">Future marketing emails</dt><dd>{{ $row['future_sends'] }}</dd></div>
                            <div><dt class="text-zinc-500">Provider</dt><dd class="break-all">{{ $row['provider'] }} · {{ $row['provider_message_id'] ?: 'No receipt ID' }}</dd></div>
                            @if($row['failure_reason'])<div class="sm:col-span-2"><dt class="text-zinc-500">Failure reason</dt><dd class="break-words">{{ $row['failure_reason'] }}</dd></div>@endif
                            @if($row['action'])<div class="sm:col-span-2"><dt class="text-zinc-500">Failure handling</dt><dd>{{ $row['action'] }}</dd></div>@endif
                        </dl>
                        @if($row['events'])
                            <details class="mt-4 text-sm"><summary class="cursor-pointer text-emerald-700">Delivery history</summary>
                                <ul class="mt-2 space-y-2">@foreach($row['events'] as $event)<li>{{ $event['at'] }} · {{ $event['event'] }}@if($event['status']) · {{ $event['status'] }}@endif @if($event['reason']) · {{ $event['reason'] }}@endif</li>@endforeach</ul>
                            </details>
                        @endif
                    @endif
                </article>
            @empty
                <p class="rounded-xl border border-zinc-200 p-6 text-sm text-zinc-500">No {{ strtolower($label) }} were recorded in this window.</p>
            @endforelse
        </div>
        {{ $rows->links() }}
    </div>
</x-layouts::app>
