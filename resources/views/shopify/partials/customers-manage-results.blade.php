@php
    $filters = array_merge([
        'search' => '',
        'segment' => 'all',
        'candle_club' => 'all',
        'candle_cash' => 'all',
        'referral' => 'all',
        'review' => 'all',
        'birthday' => 'all',
        'wholesale' => 'all',
        'sort' => 'last_activity',
        'direction' => 'desc',
        'per_page' => 25,
    ], $filters ?? []);

    $sort = $sort ?? (string) ($filters['sort'] ?? 'last_activity');
    $direction = $direction ?? (string) ($filters['direction'] ?? 'desc');
    $embeddedContext = \App\Support\Shopify\ShopifyEmbeddedContextQuery::fromRequest(request());
    $withEmbeddedContext = static function (string $url) use ($embeddedContext): string {
        return \App\Support\Shopify\ShopifyEmbeddedContextQuery::appendToUrl($url, $embeddedContext);
    };
    $sortUrl = static function (array $overrides) use ($withEmbeddedContext): string {
        return $withEmbeddedContext(
            url()->current() . '?' . http_build_query(array_merge(request()->query(), $overrides), '', '&', PHP_QUERY_RFC3986)
        );
    };
    $detailRouteName = 'shopify.app.customers.detail';
    $detailUrlFor = static function (int $id) use ($withEmbeddedContext, $detailRouteName): string {
        return $withEmbeddedContext(route($detailRouteName, ['marketingProfile' => $id], false));
    };
    $detailSectionsRouteName = 'shopify.app.api.customers.detail-sections';
    $detailSectionsUrlFor = static function (int $id) use ($detailSectionsRouteName): string {
        return route($detailSectionsRouteName, ['marketingProfile' => $id], false);
    };
    $resolvedRewardsLabel = trim((string) data_get($displayLabels ?? [], 'rewards_label', 'Rewards'));
    if ($resolvedRewardsLabel === '') {
        $resolvedRewardsLabel = 'Rewards';
    }
    $resolvedRewardsBalanceLabel = trim((string) data_get($displayLabels ?? [], 'rewards_balance_label', $resolvedRewardsLabel . ' balance'));
    if ($resolvedRewardsBalanceLabel === '') {
        $resolvedRewardsBalanceLabel = $resolvedRewardsLabel . ' balance';
    }
@endphp

<section class="customers-table-wrap" aria-label="Manage customers table">
    <div class="customers-table-scroll">
        <table>
            <thead>
                <tr>
                    <th>
                        <a
                            class="customers-sort-link"
                            href="{{ $sortUrl(['sort' => 'name', 'direction' => ($sort === 'name' && $direction === 'asc') ? 'desc' : 'asc', 'page' => 1]) }}"
                        >
                            Customer
                            <span class="customers-sort-indicator">{{ $sort === 'name' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                        </a>
                    </th>
                    <th>
                        <a
                            class="customers-sort-link"
                            href="{{ $sortUrl(['sort' => 'orders', 'direction' => ($sort === 'orders' && $direction === 'asc') ? 'desc' : 'asc', 'page' => 1]) }}"
                        >
                            Orders
                            <span class="customers-sort-indicator">{{ $sort === 'orders' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                        </a>
                    </th>
                    <th>
                        <a
                            class="customers-sort-link"
                            href="{{ $sortUrl(['sort' => 'candle_cash', 'direction' => ($sort === 'candle_cash' && $direction === 'asc') ? 'desc' : 'asc', 'page' => 1]) }}"
                        >
                            {{ \Illuminate\Support\Str::title($resolvedRewardsBalanceLabel) }}
                            <span class="customers-sort-indicator">{{ $sort === 'candle_cash' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                        </a>
                    </th>
                    <th>Tier</th>
                    <th>
                        <a
                            class="customers-sort-link"
                            href="{{ $sortUrl(['sort' => 'last_activity', 'direction' => ($sort === 'last_activity' && $direction === 'asc') ? 'desc' : 'asc', 'page' => 1]) }}"
                        >
                            Last activity
                            <span class="customers-sort-indicator">{{ $sort === 'last_activity' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                        </a>
                    </th>
                    <th>Status</th>
                    <th aria-label="Actions"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $row)
                    @php($detailUrl = $detailUrlFor((int) $row['id']))
                    @php($detailSectionsUrl = $detailSectionsUrlFor((int) $row['id']))
                    @php($status = is_array($row['status'] ?? null) ? $row['status'] : ['key' => 'standard', 'label' => 'Standard'])
                    <tr
                        class="customers-row--clickable"
                        tabindex="0"
                        data-customer-prefetch-endpoint="{{ $detailSectionsUrl }}"
                        data-customer-prefetch-profile-id="{{ (int) $row['id'] }}"
                        onclick="if (!event.target.closest('a,button,input,select,label')) { window.location.href='{{ $detailUrl }}'; }"
                        onkeydown="if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('a,button,input,select,label')) { event.preventDefault(); window.location.href='{{ $detailUrl }}'; }"
                    >
                        <td class="customers-name-cell">
                            <a class="customers-name-link" href="{{ $detailUrl }}" data-customer-prefetch-endpoint="{{ $detailSectionsUrl }}" data-customer-prefetch-profile-id="{{ (int) $row['id'] }}">{{ $row['name'] }}</a>
                            <div class="customers-subtext">{{ $row['email'] }}</div>
                            <div class="customers-subtext">{{ $row['phone'] }}</div>
                        </td>
                        <td>{{ number_format((int) ($row['orders_count'] ?? 0)) }}</td>
                        <td>{{ number_format((int) $row['candle_cash_balance']) }}</td>
                        <td>{{ $row['vip_tier'] ?? 'Standard' }}</td>
                        <td>{{ $row['last_activity_display'] }}</td>
                        <td>
                            <span class="customers-status customers-status--{{ $status['key'] }}">{{ $status['label'] }}</span>
                        </td>
                        <td>
                            <a href="{{ $detailUrl }}" class="customers-button customers-button--row" data-customer-prefetch-endpoint="{{ $detailSectionsUrl }}" data-customer-prefetch-profile-id="{{ (int) $row['id'] }}">View record</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="customers-empty">
                            No customers matched your search or filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

@if($customers instanceof \Illuminate\Contracts\Pagination\Paginator && $customers->hasPages())
    <div class="customers-pagination">
        {{ $customers->links() }}
    </div>
@endif
