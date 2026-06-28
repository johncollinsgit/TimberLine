@php
    $recentTakers = (int) data_get($report, 'quiz.recent_takers', 0);
    $totalTakers = (int) data_get($report, 'quiz.total_takers', 0);
    $recentWishlistAdds = (int) data_get($report, 'wishlist.recent_additions', 0);
    $totalWishlistAdds = (int) data_get($report, 'wishlist.total_additions', 0);
    $recentPurchases = (int) data_get($report, 'orders.recent_purchases', 0);
    $totalPurchases = (int) data_get($report, 'orders.total_purchases', 0);
    $recentRevenue = number_format((float) data_get($report, 'orders.recent_revenue', 0), 2);
    $totalRevenue = number_format((float) data_get($report, 'orders.total_revenue', 0), 2);
    $topPersonalities = is_array(data_get($report, 'quiz.top_personalities')) ? data_get($report, 'quiz.top_personalities') : [];
@endphp

<h1>Modern Forestry scent quiz weekly report</h1>

<p>
    Recent window: last {{ $recentDays }} days
    <br>
    As of: {{ (string) data_get($report, 'as_of') }}
</p>

<h2>Quiz usage</h2>
<ul>
    <li>Recent takers: {{ $recentTakers }}</li>
    <li>Total takers: {{ $totalTakers }}</li>
    <li>Recent completions: {{ (int) data_get($report, 'quiz.recent_completions', 0) }}</li>
    <li>Total completions: {{ (int) data_get($report, 'quiz.total_completions', 0) }}</li>
</ul>

<h2>Attributed outcomes</h2>
<ul>
    <li>Recent wishlist additions: {{ $recentWishlistAdds }}</li>
    <li>Total wishlist additions: {{ $totalWishlistAdds }}</li>
    <li>Recent purchases: {{ $recentPurchases }}</li>
    <li>Total purchases: {{ $totalPurchases }}</li>
    <li>Recent attributed revenue: ${{ $recentRevenue }}</li>
    <li>Total attributed revenue: ${{ $totalRevenue }}</li>
</ul>

@if($topPersonalities !== [])
    <h2>Top scent personalities</h2>
    <ul>
        @foreach($topPersonalities as $row)
            <li>{{ data_get($row, 'title', 'Profile') }}: {{ (int) data_get($row, 'count', 0) }}</li>
        @endforeach
    </ul>
@endif
