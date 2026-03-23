@php
    $filters = array_merge([
        'search' => '',
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
    $labelFor = static fn (bool $state): string => $state ? 'Completed' : 'Not completed';
    $indicatorClassFor = static fn (bool $state): string => $state ? 'is-yes' : 'is-no';
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
                            Name
                            <span class="customers-sort-indicator">{{ $sort === 'name' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                        </a>
                    </th>
                    <th>
                        <a
                            class="customers-sort-link"
                            href="{{ $sortUrl(['sort' => 'email', 'direction' => ($sort === 'email' && $direction === 'asc') ? 'desc' : 'asc', 'page' => 1]) }}"
                        >
                            Email
                            <span class="customers-sort-indicator">{{ $sort === 'email' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                        </a>
                    </th>
                    <th>
                        <a
                            class="customers-sort-link"
                            href="{{ $sortUrl(['sort' => 'candle_cash', 'direction' => ($sort === 'candle_cash' && $direction === 'asc') ? 'desc' : 'asc', 'page' => 1]) }}"
                        >
                            Candle Cash
                            <span class="customers-sort-indicator">{{ $sort === 'candle_cash' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                        </a>
                    </th>
                    <th>Candle Club</th>
                    <th>Referral</th>
                    <th>Review</th>
                    <th>Birthday</th>
                    <th>Wholesale</th>
                    <th>
                        <a
                            class="customers-sort-link"
                            href="{{ $sortUrl(['sort' => 'rewards_actions', 'direction' => ($sort === 'rewards_actions' && $direction === 'asc') ? 'desc' : 'asc', 'page' => 1]) }}"
                        >
                            Rewards Actions
                            <span class="customers-sort-indicator">{{ $sort === 'rewards_actions' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                        </a>
                    </th>
                    <th>
                        <a
                            class="customers-sort-link"
                            href="{{ $sortUrl(['sort' => 'last_activity', 'direction' => ($sort === 'last_activity' && $direction === 'asc') ? 'desc' : 'asc', 'page' => 1]) }}"
                        >
                            Last Activity
                            <span class="customers-sort-indicator">{{ $sort === 'last_activity' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                        </a>
                    </th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $row)
                    @php($detailUrl = $detailUrlFor((int) $row['id']))
                    @php($detailSectionsUrl = $detailSectionsUrlFor((int) $row['id']))
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
                            <div class="customers-subtext">ID #{{ $row['id'] }}</div>
                        </td>
                        <td>{{ $row['email'] }}</td>
                        <td>{{ number_format((int) $row['candle_cash_balance']) }}</td>
                        <td>
                            <span class="customers-status {{ $indicatorClassFor((bool) $row['candle_club_active']) }}">
                                {{ (bool) $row['candle_club_active'] ? 'Yes' : 'No' }}
                            </span>
                        </td>
                        <td>
                            <span class="customers-status {{ $indicatorClassFor((bool) $row['referral_completed']) }}">
                                {{ $labelFor((bool) $row['referral_completed']) }}
                            </span>
                        </td>
                        <td>
                            <span class="customers-status {{ $indicatorClassFor((bool) $row['review_completed']) }}">
                                {{ $labelFor((bool) $row['review_completed']) }}
                            </span>
                        </td>
                        <td>
                            <span class="customers-status {{ $indicatorClassFor((bool) $row['birthday_completed']) }}">
                                {{ $labelFor((bool) $row['birthday_completed']) }}
                            </span>
                        </td>
                        <td>
                            <span class="customers-status {{ $indicatorClassFor((bool) $row['wholesale_eligible']) }}">
                                {{ (bool) $row['wholesale_eligible'] ? 'Eligible' : 'Not eligible' }}
                            </span>
                        </td>
                        <td>{{ number_format((int) $row['rewards_actions_count']) }}</td>
                        <td>{{ $row['last_activity_display'] }}</td>
                        <td>
                            <a href="{{ $detailUrl }}" class="customers-button customers-button--row" data-customer-prefetch-endpoint="{{ $detailSectionsUrl }}" data-customer-prefetch-profile-id="{{ (int) $row['id'] }}">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="customers-empty">
                            No customers matched the current search or filters. Try a different name, email, phone, or clear the secondary filters.
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
