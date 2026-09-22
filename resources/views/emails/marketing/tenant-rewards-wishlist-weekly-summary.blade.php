@php
    $healthLabel = (string) data_get($report, 'health.label', 'Weekly summary');
    $issues = (array) data_get($report, 'health.issues', []);
    $topProducts = (array) data_get($report, 'wishlist.top_saved_products', []);
@endphp

<h1>{{ data_get($report, 'tenant.name', 'Storefront') }} rewards + wishlist</h1>

<p>
    <strong>Status: {{ $healthLabel }}</strong><br>
    {{ data_get($report, 'health.summary') }}
</p>

<p>
    Window: {{ data_get($report, 'window.started_at') }} through {{ data_get($report, 'window.ended_at') }}
</p>

<h2>Candle Cash</h2>
<ul>
    <li>Successful status checks: {{ (int) data_get($report, 'candle_cash.successful_status_lookups', 0) }}</li>
    <li>Reward views: {{ (int) data_get($report, 'candle_cash.reward_views', 0) }}</li>
    <li>Apply attempts: {{ (int) data_get($report, 'candle_cash.apply_attempts', 0) }}</li>
    <li>Successful applies: {{ (int) data_get($report, 'candle_cash.apply_successes', 0) }} ({{ number_format((float) data_get($report, 'candle_cash.apply_success_rate', 0), 1) }}%)</li>
    <li>Birthday reward applies: {{ (int) data_get($report, 'candle_cash.birthday_apply_successes', 0) }}</li>
    <li>Regular Candle Cash applies: {{ (int) data_get($report, 'candle_cash.candle_cash_apply_successes', 0) }}</li>
    <li>Apply failures: {{ (int) data_get($report, 'candle_cash.apply_failures', 0) }}</li>
    <li>Unavailable/fallback cards shown: {{ (int) data_get($report, 'candle_cash.fallback_renders', 0) }}</li>
    <li>Candle Cash earned: ${{ number_format((float) data_get($report, 'candle_cash.earned.amount', 0), 2) }} across {{ (int) data_get($report, 'candle_cash.earned.count', 0) }} entries</li>
    <li>Reward codes issued: ${{ number_format((float) data_get($report, 'candle_cash.issued.amount', 0), 2) }} across {{ (int) data_get($report, 'candle_cash.issued.count', 0) }} codes</li>
    <li>Completed redemptions: ${{ number_format((float) data_get($report, 'candle_cash.redeemed.amount', 0), 2) }} across {{ (int) data_get($report, 'candle_cash.redeemed.count', 0) }} codes</li>
    <li>Currently active codes: ${{ number_format((float) data_get($report, 'candle_cash.currently_active_codes.amount', 0), 2) }} across {{ (int) data_get($report, 'candle_cash.currently_active_codes.count', 0) }} codes</li>
    <li>Last rewards activity: {{ data_get($report, 'candle_cash.last_storefront_activity_at') ?: 'None recorded' }}</li>
</ul>

<h2>Wishlist</h2>
<ul>
    <li>Items added: {{ (int) data_get($report, 'wishlist.adds', 0) }}</li>
    <li>Items removed: {{ (int) data_get($report, 'wishlist.removals', 0) }}</li>
    <li>Failed interactions: {{ (int) data_get($report, 'wishlist.errors', 0) }}</li>
    <li>Currently saved items: {{ (int) data_get($report, 'wishlist.current_active_items', 0) }}</li>
    <li>Customers/guests with saved items: {{ (int) data_get($report, 'wishlist.current_savers', 0) }}</li>
    <li>Last wishlist activity: {{ data_get($report, 'wishlist.last_storefront_activity_at') ?: 'None recorded' }}</li>
</ul>

@if($issues !== [])
    <h2>Recorded issues</h2>
    <ul>
        @foreach($issues as $issue)
            <li>{{ data_get($issue, 'event_type', 'storefront_event') }} / {{ data_get($issue, 'issue_type', 'unspecified') }}: {{ (int) data_get($issue, 'count', 0) }}</li>
        @endforeach
    </ul>
@endif

@if($topProducts !== [])
    <h3>Most-saved products right now</h3>
    <ol>
        @foreach($topProducts as $product)
            <li>{{ data_get($product, 'title', 'Product') }} — {{ (int) data_get($product, 'saves', 0) }} saves</li>
        @endforeach
    </ol>
@endif

<p><small>No customer names, email addresses, phone numbers, or reward codes are included in this report.</small></p>
