@once
<style>
    .eb-square-history { display: grid; gap: 18px; padding: 24px; border: 1px solid var(--eb-border, #dbe4e3); border-radius: 16px; background: var(--eb-surface, #fff); color: var(--eb-text-primary, #0f1c1f); font: 400 14px/1.6 var(--eb-font-ui, Inter, ui-sans-serif, system-ui, sans-serif); }
    .eb-square-history, .eb-square-history * { box-sizing: border-box; min-width: 0; }
    .eb-square-history h2, .eb-square-history h3 { margin: 0; color: inherit; font-family: inherit; font-weight: 650; letter-spacing: -.02em; line-height: 1.35; }
    .eb-square-history h2 { font-size: 20px; }
    .eb-square-history h3 { margin-top: 8px; font-size: 16px; }
    .eb-square-history p { margin: 0; color: var(--eb-text-secondary, #32464b); overflow-wrap: anywhere; }
    .eb-square-history .eb-square-record { display: grid; gap: 12px; padding: 18px; border: 1px solid var(--eb-border, #dbe4e3); border-radius: 12px; background: var(--eb-surface-subtle, #f8fbfa); }
    .eb-square-history .eb-square-record-title { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px 16px; }
    .eb-square-history .eb-square-record-title strong { flex: 1 1 auto; color: var(--eb-text-primary, #0f1c1f); font-weight: 600; overflow-wrap: anywhere; }
    .eb-square-history .eb-square-status { display: inline-block; padding: 3px 10px; border: 1px solid var(--eb-border, #dbe4e3); border-radius: 999px; background: var(--eb-surface, #fff); color: var(--eb-text-primary, #0f1c1f); overflow-wrap: anywhere; }
    .eb-square-history .eb-square-record-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 24px; }
    .eb-square-history .eb-square-id { font-weight: 400; overflow-wrap: anywhere; }
    .eb-square-history .eb-square-card-reference { padding-top: 12px; border-top: 1px solid var(--eb-border, #dbe4e3); }
    .eb-square-history .eb-square-amount { color: var(--eb-text-primary, #0f1c1f); font-size: 18px; font-weight: 650; }
    .eb-square-history .eb-square-details { padding-top: 4px; border-top: 1px solid var(--eb-border, #dbe4e3); }
    .eb-square-history .eb-square-details summary { min-height: 44px; padding: 10px 0; color: var(--eb-link, #123c43); font-weight: 600; cursor: pointer; }
    .eb-square-history .eb-square-details dl { display: grid; gap: 12px; margin: 8px 0 0; }
    .eb-square-history .eb-square-details dt { color: var(--eb-text-muted, #5d6b6a); font-size: 14px; }
    .eb-square-history .eb-square-details dd { margin: 3px 0 0; color: var(--eb-text-secondary, #32464b); overflow-wrap: anywhere; }
    .eb-square-history ul { display: grid; gap: 8px; margin: 0; padding-left: 20px; color: var(--eb-text-secondary, #32464b); }
    .eb-square-history li { padding-left: 2px; font-size: 15px; overflow-wrap: anywhere; }
    .eb-square-history .eb-square-link { display: inline-flex; align-items: center; justify-content: center; justify-self: start; min-height: 44px; padding: 10px 14px; border: 1px solid var(--eb-border, #dbe4e3); border-radius: 9px; color: var(--eb-link, #123c43); background: var(--eb-surface, #fff); font-weight: 600; text-align: center; text-decoration: none; }
    .eb-square-history :is(.eb-square-link, summary):focus-visible { outline: 3px solid var(--eb-link, #1e5a63); outline-offset: 3px; }
    .eb-square-history .eb-square-notice { padding: 12px 14px; border: 1px solid #ead7ad; border-radius: 10px; background: #fffbef; color: #714f16; }
    .eb-square-history .eb-square-coverage { padding-top: 14px; border-top: 1px solid var(--eb-border, #dbe4e3); }
    @media (max-width: 639px) {
        .eb-square-history { padding: 18px 14px; }
        .eb-square-history .eb-square-record { padding: 16px 12px; }
        .eb-square-history .eb-square-record-meta { grid-template-columns: minmax(0, 1fr); }
        .eb-square-history .eb-square-link { width: 100%; }
    }
</style>
@endonce
@php
    $squareSourceDate = static function ($value): string {
        if (!$value) return 'date unknown';
        try { return \Carbon\CarbonImmutable::parse($value)->format('M j, Y'); }
        catch (\Throwable) { return 'date unknown'; }
    };
@endphp
<section class="eb-square-history eb-themed-panel" aria-label="Private Square source history">
    <h2>{{ empty($squareHistoryOnly) ? 'Original Square customer and purchase sources' : 'Original Square purchase history' }}</h2>
    <a class="eb-square-link" href="{{ route('customers.square.index', ['tenant' => $tenant->slug]) }}">Square connection, refresh and identity review</a>
    <p>Recorded source history is bounded by imported coverage. A recorded location is not an event. Historical imports do not earn or consume rewards.</p>
    <p>Newest cached records first. Original purchase dates are shown separately; new imports appear when you restart the history.</p>
    @foreach($squareSource['legacy_consent_review'] ?? [] as $channel => $consent)
        @if($consent['review_required'])<p class="eb-square-notice" role="status">{{ ucfirst($channel) }} permission needs review: older Square imports inferred permission without affirmative proof. Current permission has not been automatically changed.</p>@endif
    @endforeach
    @foreach($squareSource['issues'] as $issue)<p class="eb-square-notice" role="status">{{ str_replace('_', ' ', $issue) }}</p>@endforeach
    @if(empty($squareHistoryOnly))
    @forelse($squareSource['sources'] as $source)
        <article class="eb-square-record">
            <p class="eb-square-record-title"><strong>Square customer</strong><span class="eb-square-status">Linked source</span></p>
            <p>Cached {{ $squareSourceDate($source['synced_at'] ?? null) }} · {{ $source['source_binding']['environment'] ?? 'environment unknown' }}</p>
            <p>Email: {{ str_replace('_', ' ', $source['provider_preferences']['email']['state']) }} · SMS: {{ str_replace('_', ' ', $source['provider_preferences']['sms']['state']) }}. “Not unsubscribed” does not prove opt-in.</p>
            @foreach($source['cards'] as $card)<p class="eb-square-card-reference">Recorded card {{ $card['brand'] ?? 'brand unknown' }} · ending {{ $card['last_4'] ?? 'unknown' }}. Identity and payment authority are not granted.</p>@endforeach
            <details class="eb-square-details">
                <summary>Source details</summary>
                <dl>
                    <div><dt>Original Square customer ID</dt><dd>{{ $source['square_customer_id'] }}</dd></div>
                    <div><dt>Association basis</dt><dd>{{ str_replace('_', ' ', $source['identity_basis'] ?? 'original source link') }}</dd></div>
                    <div><dt>Original merchant</dt><dd>{{ $source['source_binding']['merchant_id'] ?? 'unknown' }}</dd></div>
                    <div><dt>Environment</dt><dd>{{ $source['source_binding']['environment'] ?? 'environment unknown' }}</dd></div>
                    <div><dt>Exact cached timestamp</dt><dd>{{ $source['synced_at'] ?? 'unknown' }}</dd></div>
                    @foreach($source['cards'] as $card)<div><dt>Recorded card reference · {{ $card['brand'] ?? 'brand unknown' }} · ending {{ $card['last_4'] ?? 'unknown' }}</dt><dd>{{ $card['provider_reference'] }}</dd></div>@endforeach
                </dl>
            </details>
        </article>
    @empty<p>No reviewed Square customer source is associated with this customer.</p>@endforelse
    @endif
    @if(isset($squareSource['history']['coverage']['square_order']))
    <h3>Recorded original orders</h3>
    @forelse($squareSource['history']['orders'] as $order)
        <article class="eb-square-record">
            <p class="eb-square-record-title"><strong>Square order</strong><span class="eb-square-status">{{ $order['state'] ?? 'state unknown' }}</span></p>
            <p>{{ $squareSourceDate($order['closed_at'] ?? null) }}</p>
            @if(!empty($order['tax_names']))<p>Original tax place: {{ implode(' · ', $order['tax_names']) }}</p>@endif
            @if(!empty($order['purchase_place']))<p>Recognized purchase-area ZIPs: {{ implode(', ', $order['purchase_place']['postal_codes']) }}. Customer home address is separate.</p>@endif
            <p class="eb-square-amount">{{ $order['total_amount'] === null ? 'amount unknown' : ($order['currency'] === 'USD' ? number_format($order['total_amount'] / 100, 2) : $order['total_amount'].' minor units') }} {{ $order['currency'] }}</p>
            <ul>@foreach($order['items'] as $item)<li>{{ $item['quantity'] ?? 'quantity unknown' }} × {{ $item['label'] ?? 'original item label unavailable' }} @if($item['variation'])· {{ $item['variation'] }}@endif</li>@endforeach</ul>
            @if(!$order['items'])<p>Original line items are not present in this cached source.</p>@endif
            <details class="eb-square-details">
                <summary>Source details</summary>
                <dl>
                    <div><dt>Original Square order ID</dt><dd>{{ $order['id'] }}</dd></div>
                    <div><dt>Exact closed timestamp</dt><dd>{{ $order['closed_at'] ?? 'date unknown' }}</dd></div>
                    <div><dt>Recorded Square location</dt><dd>{{ $order['location_id'] ?? 'unknown' }}</dd></div>
                    <div><dt>Original merchant</dt><dd>{{ $order['source_binding']['merchant_id'] ?? 'not recorded' }}</dd></div>
                    <div><dt>Environment</dt><dd>{{ $order['source_binding']['environment'] ?? 'environment unknown' }}</dd></div>
                </dl>
            </details>
        </article>
    @empty<p>No original order sources are loaded for this association.</p>@endforelse
    @endif
    @if(isset($squareSource['history']['coverage']['square_payment']))
    <h3>Recorded original payments</h3>
    @foreach($squareSource['history']['payments'] as $payment)
        <article class="eb-square-record">
            <p class="eb-square-record-title"><strong>Square payment</strong><span class="eb-square-status">{{ $payment['status'] ?? 'status unknown' }}</span></p>
            <p>{{ $squareSourceDate($payment['recorded_at'] ?? null) }}</p>
            <p class="eb-square-amount">{{ $payment['amount'] === null ? 'amount unknown' : ($payment['currency'] === 'USD' ? number_format($payment['amount'] / 100, 2) : $payment['amount'].' minor units') }} {{ $payment['currency'] }}</p>
            <details class="eb-square-details">
                <summary>Source details</summary>
                <dl>
                    <div><dt>Original Square payment ID</dt><dd>{{ $payment['id'] }}</dd></div>
                    <div><dt>Exact recorded timestamp</dt><dd>{{ $payment['recorded_at'] ?? 'date unknown' }}</dd></div>
                    <div><dt>Recorded location</dt><dd>{{ $payment['location_id'] ?? 'unknown' }}</dd></div>
                </dl>
            </details>
        </article>
    @endforeach
    @endif
    @foreach($squareSource['history']['coverage'] as $kind => $coverage)
        <p class="eb-square-coverage">{{ $kind === 'square_order' ? 'Orders' : 'Payments' }} · page {{ $coverage['page'] ?? 1 }}: {{ $coverage['displayed_count'] }} of {{ $coverage['cached_count'] }} cached records shown on this page. Provider history completeness is unknown.@if($coverage['withheld_count'] ?? 0) {{ $coverage['withheld_count'] }} scanned {{ ($coverage['withheld_count'] ?? 0) === 1 ? 'record needs' : 'records need' }} identity review.@endif</p>
        @if(($coverage['next_cursor'] ?? null) && isset($profile))
            <a class="eb-square-link" href="{{ route('customers.square.history', ['tenant' => $tenant->slug, 'profile' => $profile->id, 'kind' => $kind, 'cursor' => $coverage['next_cursor']]) }}">More cached {{ $kind === 'square_order' ? 'orders' : 'payments' }}</a>
        @endif
    @endforeach
</section>
