<x-shopify.customers-layout
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :customer-subnav="$pageSubnav"
    :page-actions="$pageActions"
>
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
        ], $gridFilters ?? []);

        $sort = (string) ($filters['sort'] ?? 'last_activity');
        $direction = (string) ($filters['direction'] ?? 'desc');
        $perPage = (int) ($filters['per_page'] ?? 25);

        $sortOptions = collect($gridSortOptions ?? [])
            ->pluck('label', 'value')
            ->all();

        $labelFor = static fn (bool $state): string => $state ? 'Completed' : 'Not completed';
        $indicatorClassFor = static fn (bool $state): string => $state ? 'is-yes' : 'is-no';
    @endphp

    <style>
        .customers-manage-root {
            display: grid;
            gap: 16px;
        }

        .customers-toolbar {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #ffffff;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
            padding: 16px;
            display: grid;
            gap: 12px;
        }

        .customers-toolbar-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .customers-toolbar-head h2 {
            margin: 0;
            font-size: 15px;
            font-weight: 640;
            letter-spacing: -0.01em;
            color: rgba(15, 23, 42, 0.92);
        }

        .customers-toolbar-head p {
            margin: 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.56);
        }

        .customers-toolbar-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: minmax(0, 2.1fr) repeat(8, minmax(110px, 1fr));
            align-items: end;
        }

        .customers-field {
            display: grid;
            gap: 5px;
        }

        .customers-field label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.5);
        }

        .customers-field input,
        .customers-field select {
            width: 100%;
            box-sizing: border-box;
            border-radius: 8px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: #ffffff;
            padding: 8px 10px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.78);
        }

        .customers-toolbar-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .customers-toolbar-meta {
            font-size: 12px;
            color: rgba(15, 23, 42, 0.58);
        }

        .customers-toolbar-buttons {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .customers-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: #ffffff;
            color: rgba(15, 23, 42, 0.78);
            text-decoration: none;
            padding: 8px 11px;
            font-size: 12px;
            font-weight: 620;
            cursor: pointer;
            transition: border-color 0.16s ease, background 0.16s ease, color 0.16s ease;
        }

        .customers-button:hover {
            border-color: rgba(15, 23, 42, 0.24);
            background: rgba(255, 255, 255, 1);
            color: rgba(15, 23, 42, 0.94);
        }

        .customers-button.is-primary {
            border-color: rgba(15, 143, 97, 0.32);
            background: rgba(15, 143, 97, 0.08);
            color: #0d6f4d;
        }

        .customers-table-wrap {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #ffffff;
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }

        .customers-table-wrap table {
            width: 100%;
            min-width: 1240px;
            border-collapse: collapse;
        }

        .customers-table-wrap th {
            text-align: left;
            padding: 10px 12px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.56);
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(247, 248, 246, 0.96);
            white-space: nowrap;
        }

        .customers-table-wrap td {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
            font-size: 12px;
            color: rgba(15, 23, 42, 0.78);
            white-space: nowrap;
            vertical-align: middle;
        }

        .customers-row--clickable {
            cursor: pointer;
        }

        .customers-row--clickable:hover {
            background: rgba(15, 23, 42, 0.02);
        }

        .customers-row--clickable:focus-visible {
            outline: 2px solid rgba(15, 143, 97, 0.4);
            outline-offset: -2px;
        }

        .customers-name-cell {
            min-width: 190px;
        }

        .customers-name-link {
            color: rgba(15, 23, 42, 0.92);
            text-decoration: none;
            font-weight: 630;
        }

        .customers-name-link:hover {
            color: rgba(15, 23, 42, 1);
            text-decoration: underline;
            text-decoration-thickness: 1px;
            text-underline-offset: 2px;
        }

        .customers-subtext {
            margin-top: 2px;
            font-size: 11px;
            color: rgba(15, 23, 42, 0.5);
        }

        .customers-sort-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            color: inherit;
        }

        .customers-sort-indicator {
            font-size: 10px;
            color: rgba(15, 23, 42, 0.42);
        }

        .customers-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 86px;
            border-radius: 999px;
            padding: 4px 8px;
            border: 1px solid rgba(15, 23, 42, 0.13);
            font-size: 11px;
            font-weight: 620;
            line-height: 1.2;
        }

        .customers-status.is-yes {
            border-color: rgba(15, 143, 97, 0.28);
            background: rgba(15, 143, 97, 0.1);
            color: #0d6f4d;
        }

        .customers-status.is-no {
            border-color: rgba(148, 163, 184, 0.24);
            background: rgba(148, 163, 184, 0.08);
            color: #475569;
        }

        .customers-empty {
            padding: 36px 20px;
            text-align: center;
            color: rgba(15, 23, 42, 0.62);
            font-size: 14px;
            line-height: 1.6;
        }

        .customers-pagination {
            display: flex;
            justify-content: flex-end;
            padding-top: 4px;
        }

        @media (max-width: 1440px) {
            .customers-toolbar-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .customers-toolbar-grid .customers-field:first-child {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 900px) {
            .customers-toolbar-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 680px) {
            .customers-toolbar-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <section class="customers-manage-root">
        <form method="GET" action="{{ url()->current() }}" class="customers-toolbar">
            <div class="customers-toolbar-head">
                <h2>Manage customers</h2>
                <p>{{ number_format((int) ($customers?->total() ?? 0)) }} customers · {{ number_format((int) ($activeFilterCount ?? 0)) }} active filter{{ (int) ($activeFilterCount ?? 0) === 1 ? '' : 's' }}</p>
            </div>

            <div class="customers-toolbar-grid" aria-label="Manage customers filters">
                <div class="customers-field">
                    <label for="search">Search</label>
                    <input
                        id="search"
                        name="search"
                        type="text"
                        value="{{ (string) $filters['search'] }}"
                        placeholder="Name or email"
                    />
                </div>

                <div class="customers-field">
                    <label for="candle_club">Candle Club</label>
                    <select id="candle_club" name="candle_club">
                        <option value="all" @selected($filters['candle_club'] === 'all')>All</option>
                        <option value="yes" @selected($filters['candle_club'] === 'yes')>Yes</option>
                        <option value="no" @selected($filters['candle_club'] === 'no')>No</option>
                    </select>
                </div>

                <div class="customers-field">
                    <label for="candle_cash">Candle Cash &gt; 0</label>
                    <select id="candle_cash" name="candle_cash">
                        <option value="all" @selected($filters['candle_cash'] === 'all')>All</option>
                        <option value="yes" @selected($filters['candle_cash'] === 'yes')>Yes</option>
                        <option value="no" @selected($filters['candle_cash'] === 'no')>No</option>
                    </select>
                </div>

                <div class="customers-field">
                    <label for="referral">Referral</label>
                    <select id="referral" name="referral">
                        <option value="all" @selected($filters['referral'] === 'all')>All</option>
                        <option value="yes" @selected($filters['referral'] === 'yes')>Completed</option>
                        <option value="no" @selected($filters['referral'] === 'no')>Not completed</option>
                    </select>
                </div>

                <div class="customers-field">
                    <label for="review">Review</label>
                    <select id="review" name="review">
                        <option value="all" @selected($filters['review'] === 'all')>All</option>
                        <option value="yes" @selected($filters['review'] === 'yes')>Completed</option>
                        <option value="no" @selected($filters['review'] === 'no')>Not completed</option>
                    </select>
                </div>

                <div class="customers-field">
                    <label for="birthday">Birthday</label>
                    <select id="birthday" name="birthday">
                        <option value="all" @selected($filters['birthday'] === 'all')>All</option>
                        <option value="yes" @selected($filters['birthday'] === 'yes')>Completed</option>
                        <option value="no" @selected($filters['birthday'] === 'no')>Not completed</option>
                    </select>
                </div>

                <div class="customers-field">
                    <label for="wholesale">Wholesale</label>
                    <select id="wholesale" name="wholesale">
                        <option value="all" @selected($filters['wholesale'] === 'all')>All</option>
                        <option value="yes" @selected($filters['wholesale'] === 'yes')>Eligible</option>
                        <option value="no" @selected($filters['wholesale'] === 'no')>Not eligible</option>
                    </select>
                </div>

                <div class="customers-field">
                    <label for="sort">Sort</label>
                    <select id="sort" name="sort">
                        @foreach($sortOptions as $value => $label)
                            <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="customers-field">
                    <label for="direction">Direction</label>
                    <select id="direction" name="direction">
                        <option value="desc" @selected($direction === 'desc')>Desc</option>
                        <option value="asc" @selected($direction === 'asc')>Asc</option>
                    </select>
                </div>
            </div>

            <div class="customers-toolbar-actions">
                <div class="customers-toolbar-meta">
                    Showing page {{ (int) ($customers?->currentPage() ?? 1) }} of {{ (int) ($customers?->lastPage() ?? 1) }}
                </div>
                <div class="customers-toolbar-buttons">
                    <div class="customers-field" style="min-width: 96px;">
                        <label for="per_page">Rows</label>
                        <select id="per_page" name="per_page">
                            @foreach([25, 50, 100] as $count)
                                <option value="{{ $count }}" @selected($perPage === $count)>{{ $count }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="customers-button is-primary">Apply</button>
                    <a href="{{ url()->current() }}" class="customers-button">Reset</a>
                </div>
            </div>
        </form>

        <section class="customers-table-wrap" aria-label="Manage customers table">
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>
                                <a
                                    class="customers-sort-link"
                                    href="{{ url()->current() . '?' . http_build_query(array_merge(request()->query(), ['sort' => 'name', 'direction' => ($sort === 'name' && $direction === 'asc') ? 'desc' : 'asc', 'page' => 1])) }}"
                                >
                                    Name
                                    <span class="customers-sort-indicator">{{ $sort === 'name' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                                </a>
                            </th>
                            <th>
                                <a
                                    class="customers-sort-link"
                                    href="{{ url()->current() . '?' . http_build_query(array_merge(request()->query(), ['sort' => 'email', 'direction' => ($sort === 'email' && $direction === 'asc') ? 'desc' : 'asc', 'page' => 1])) }}"
                                >
                                    Email
                                    <span class="customers-sort-indicator">{{ $sort === 'email' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                                </a>
                            </th>
                            <th>
                                <a
                                    class="customers-sort-link"
                                    href="{{ url()->current() . '?' . http_build_query(array_merge(request()->query(), ['sort' => 'candle_cash', 'direction' => ($sort === 'candle_cash' && $direction === 'asc') ? 'desc' : 'asc', 'page' => 1])) }}"
                                >
                                    Candle Cash
                                    <span class="customers-sort-indicator">{{ $sort === 'candle_cash' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                                </a>
                            </th>
                            <th>Candle Club Active</th>
                            <th>Referral</th>
                            <th>Review</th>
                            <th>Birthday</th>
                            <th>Wholesale</th>
                            <th>
                                <a
                                    class="customers-sort-link"
                                    href="{{ url()->current() . '?' . http_build_query(array_merge(request()->query(), ['sort' => 'rewards_actions', 'direction' => ($sort === 'rewards_actions' && $direction === 'asc') ? 'desc' : 'asc', 'page' => 1])) }}"
                                >
                                    Rewards Actions
                                    <span class="customers-sort-indicator">{{ $sort === 'rewards_actions' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                                </a>
                            </th>
                            <th>
                                <a
                                    class="customers-sort-link"
                                    href="{{ url()->current() . '?' . http_build_query(array_merge(request()->query(), ['sort' => 'last_activity', 'direction' => ($sort === 'last_activity' && $direction === 'asc') ? 'desc' : 'asc', 'page' => 1])) }}"
                                >
                                    Last Activity
                                    <span class="customers-sort-indicator">{{ $sort === 'last_activity' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                                </a>
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($customers as $row)
                            @php
                                $detailUrl = route('shopify.embedded.customers.detail', ['marketingProfile' => $row['id']], false);
                            @endphp
                            <tr
                                class="customers-row--clickable"
                                tabindex="0"
                                onclick="if (!event.target.closest('a,button,input,select,label')) { window.location.href='{{ $detailUrl }}'; }"
                                onkeydown="if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('a,button,input,select,label')) { event.preventDefault(); window.location.href='{{ $detailUrl }}'; }"
                            >
                                <td class="customers-name-cell">
                                    <a class="customers-name-link" href="{{ $detailUrl }}">{{ $row['name'] }}</a>
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
                                    <a href="{{ $detailUrl }}" class="customers-button">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="customers-empty">
                                    No customers matched the current search and filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if($customers instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $customers->hasPages())
            <div class="customers-pagination">
                {{ $customers->links() }}
            </div>
        @endif
    </section>
</x-shopify.customers-layout>
