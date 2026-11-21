<section class="rounded-2xl border bg-white p-5 space-y-4 dark:bg-zinc-900" aria-label="Customer marketing history">
    <h2 class="text-xl font-semibold">Texts and campaign history</h2>
    <p class="text-sm text-zinc-500">Original send, delivery and click records remain separate from modeled purchase attribution.</p>
    @forelse($marketingHistory['messages'] as $message)
        <details class="rounded-xl border p-4"><summary class="cursor-pointer font-medium">{{ $message['campaign_name'] ?: 'Message' }} · {{ ucfirst($message['status']) }} · {{ substr($message['sent_at'] ?? '', 0, 10) }}</summary>
            <p class="mt-3 whitespace-pre-line">{{ $message['message'] }}</p>
            <p class="mt-2 text-sm text-zinc-500">Delivery: {{ $message['delivered_at'] ? 'Confirmed' : 'Not confirmed' }} · Recorded clicks: {{ $message['recorded_clicks'] }}</p>
            @if($message['campaign_id'])<a class="mt-2 inline-flex min-h-11 items-center underline" href="{{ route('marketing.campaigns.show', ['campaign' => $message['campaign_id'], 'tenant' => $tenant->slug]) }}">View campaign results</a>@endif
        </details>
    @empty<p>No marketing messages recorded.</p>@endforelse
    @foreach($marketingHistory['conversions'] as $conversion)
        <p class="rounded-xl border p-3 text-sm">{{ substr($conversion['occurred_at'] ?? '', 0, 10) }} · Campaign purchase · {{ $conversion['net_sales_minor'] === null ? 'Amount needs review' : '$'.number_format($conversion['net_sales_minor']/100, 2).' net sales' }} · {{ str_replace('_', ' ', $conversion['basis']) }}</p>
    @endforeach
    <nav class="flex gap-4" aria-label="Customer marketing pages">
        @if($marketingHistory['page'] > 1)<a class="min-h-11 py-3 underline" href="{{ route('customers.profile', ['profile'=>$profile->id, 'tenant'=>$tenant->slug, 'marketing_page'=>$marketingHistory['page']-1]) }}">Newer messages</a>@endif
        @if($marketingHistory['has_more'])<a class="min-h-11 py-3 underline" href="{{ route('customers.profile', ['profile'=>$profile->id, 'tenant'=>$tenant->slug, 'marketing_page'=>$marketingHistory['page']+1]) }}">Older messages</a>@endif
    </nav>
</section>
