<section class="space-y-3 rounded border p-4" aria-label="Private Square source history">
    <h2 class="text-xl font-semibold">Original Square customer and purchase sources</h2>
    <a href="{{ route('customers.square.index', ['tenant' => $tenant->slug]) }}">Square connection, refresh and identity review</a>
    <p>Recorded source history is bounded by imported coverage. A recorded location is not an event. Historical imports do not earn or consume rewards.</p>
    @foreach($squareSource['legacy_consent_review'] as $channel => $consent)
        @if($consent['review_required'])<p role="status">{{ ucfirst($channel) }} permission needs review: older Square imports inferred permission without affirmative proof. Current permission has not been automatically changed.</p>@endif
    @endforeach
    @foreach($squareSource['issues'] as $issue)<p role="status">{{ str_replace('_', ' ', $issue) }}</p>@endforeach
    @forelse($squareSource['sources'] as $source)
        <article class="rounded border p-3">
            <p>Square customer {{ $source['square_customer_id'] }} · {{ str_replace('_', ' ', $source['identity_basis'] ?? 'original source link') }}</p>
            <p>Original merchant {{ $source['source_binding']['merchant_id'] ?? 'unknown' }} · {{ $source['source_binding']['environment'] ?? 'environment unknown' }} · cached {{ $source['synced_at'] ?? 'unknown' }}</p>
            <p>Email: {{ str_replace('_', ' ', $source['provider_preferences']['email']['state']) }} · SMS: {{ str_replace('_', ' ', $source['provider_preferences']['sms']['state']) }}. “Not unsubscribed” does not prove opt-in.</p>
            @foreach($source['cards'] as $card)<p>Recorded card {{ $card['brand'] ?? 'brand unknown' }} · ending {{ $card['last_4'] ?? 'unknown' }} · reference {{ $card['provider_reference'] }}. Identity and payment authority are not granted.</p>@endforeach
        </article>
    @empty<p>No reviewed Square customer source is associated with this customer.</p>@endforelse
    <h3 class="font-semibold">Recorded original orders</h3>
    @forelse($squareSource['history']['orders'] as $order)
        <article class="rounded border p-3">
            <p>Square order {{ $order['id'] }} · {{ $order['state'] ?? 'state unknown' }} · {{ $order['closed_at'] ?? 'date unknown' }} · {{ $order['total_amount'] === null ? 'amount unknown' : ($order['currency'] === 'USD' ? number_format($order['total_amount'] / 100, 2) : $order['total_amount'].' minor units') }} {{ $order['currency'] }}</p>
            <p>Recorded Square location {{ $order['location_id'] ?? 'unknown' }} · merchant {{ $order['source_binding']['merchant_id'] ?? 'not recorded' }} · {{ $order['source_binding']['environment'] ?? 'environment unknown' }}</p>
            <ul>@foreach($order['items'] as $item)<li>{{ $item['quantity'] ?? 'quantity unknown' }} × {{ $item['label'] ?? 'original item label unavailable' }} @if($item['variation'])· {{ $item['variation'] }}@endif</li>@endforeach</ul>
            @if(!$order['items'])<p>Original line items are not present in this cached source.</p>@endif
        </article>
    @empty<p>No original order sources are loaded for this association.</p>@endforelse
    <h3 class="font-semibold">Recorded original payments</h3>
    @foreach($squareSource['history']['payments'] as $payment)<p>Square payment {{ $payment['id'] }} · {{ $payment['status'] ?? 'status unknown' }} · {{ $payment['amount'] === null ? 'amount unknown' : ($payment['currency'] === 'USD' ? number_format($payment['amount'] / 100, 2) : $payment['amount'].' minor units') }} {{ $payment['currency'] }} · {{ $payment['recorded_at'] ?? 'date unknown' }} · recorded location {{ $payment['location_id'] ?? 'unknown' }}.</p>@endforeach
    @foreach($squareSource['history']['coverage'] as $kind => $coverage)<p>{{ str_replace('_', ' ', $kind) }}: {{ $coverage['displayed_count'] }} of {{ $coverage['cached_count'] }} cached records shown. Provider history completeness is unknown.</p>@endforeach
</section>
