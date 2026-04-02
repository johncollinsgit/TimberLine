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
    :merchant-journey="$merchantJourney ?? []"
>
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
        ], $gridFilters ?? []);

        $sort = (string) ($filters['sort'] ?? 'last_activity');
        $direction = (string) ($filters['direction'] ?? 'desc');
        $sortOptions = collect($gridSortOptions ?? [])->pluck('label', 'value')->all();
        $journey = is_array($merchantJourney ?? null) ? $merchantJourney : [];
        $customerSummary = is_array($journey['customer_summary'] ?? null)
            ? $journey['customer_summary']
            : ['total_profiles' => 0, 'reachable_profiles' => 0, 'customers_with_points' => 0];
        $importSummary = is_array($journey['import_summary'] ?? null) ? $journey['import_summary'] : [];
        $importState = (string) ($importSummary['state'] ?? 'not_started');
        $syncIsStale = (bool) ($importSummary['is_stale'] ?? false);
        $importCta = is_array($importSummary['cta'] ?? null)
            ? $importSummary['cta']
            : ['label' => 'Sync customers', 'href' => route('shopify.app.integrations', [], false)];
        $filtersOpenByDefault = (int) ($activeFilterCount ?? 0) > 0;
        $summaryTotal = (int) ($customerSummary['total_profiles'] ?? 0);
        $summaryReachable = (int) ($customerSummary['reachable_profiles'] ?? 0);
        $summaryWithPoints = (int) ($customerSummary['customers_with_points'] ?? 0);

        $lastSync = 'Not synced';
        if (filled(data_get($importSummary, 'latest_run.finished_at_display'))) {
            $lastSync = (string) data_get($importSummary, 'latest_run.finished_at_display');
        } elseif (filled(data_get($importSummary, 'latest_run.started_at_display'))) {
            $lastSync = (string) data_get($importSummary, 'latest_run.started_at_display');
        } elseif (filled($importSummary['label'] ?? null)) {
            $lastSync = (string) $importSummary['label'];
        }

        $primarySyncLabel = match ($importState) {
            'in_progress' => 'View sync status',
            'attention' => 'Retry sync',
            'imported' => $syncIsStale ? 'Retry sync' : 'View sync status',
            default => 'Sync customers',
        };
        $primarySyncHref = route('shopify.app.integrations', [], false);
        $embeddedContext = \App\Support\Shopify\ShopifyEmbeddedContextQuery::fromRequest(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => \App\Support\Shopify\ShopifyEmbeddedContextQuery::appendToUrl($url, $embeddedContext);

        $contextFields = collect($embeddedContext)
            ->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')
            ->all();

        $filterReset = array_merge($contextFields, [
            'sort' => 'last_activity',
            'direction' => 'desc',
            'per_page' => 25,
        ]);
    @endphp

    <style>
        .customers-page {
            display: grid;
            gap: 16px;
        }

        .customers-summary-strip {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .customers-summary-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 12px;
            background: #fff;
            padding: 14px;
        }

        .customers-summary-label {
            margin: 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.62);
        }

        .customers-summary-value {
            margin: 8px 0 0;
            font-size: 1.15rem;
            font-weight: 700;
            color: #0f172a;
        }

        .customers-summary-meta {
            margin: 6px 0 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.52);
        }

        .customers-toolbar {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 12px;
            background: #fff;
            padding: 14px;
            display: grid;
            gap: 12px;
        }

        .customers-toolbar-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .customers-toolbar-title {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 650;
            color: #0f172a;
        }

        .customers-toolbar-copy {
            margin: 4px 0 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.6);
        }

        .customers-toolbar-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .customers-action-button,
        .customers-action-link,
        .customers-filter-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            border-radius: 8px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: #fff;
            color: #0f172a;
            padding: 0 12px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .customers-action-button--primary {
            border-color: #0f766e;
            background: rgba(15, 118, 110, 0.12);
            color: #115e59;
        }

        .customers-query-row {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(170px, 220px) minmax(150px, 190px) auto;
            gap: 10px;
            align-items: end;
        }

        .customers-field {
            display: grid;
            gap: 5px;
        }

        .customers-field label {
            font-size: 11px;
            color: rgba(15, 23, 42, 0.58);
        }

        .customers-field input,
        .customers-field select {
            width: 100%;
            min-height: 38px;
            border-radius: 8px;
            border: 1px solid rgba(15, 23, 42, 0.16);
            background: #fff;
            padding: 8px 10px;
            font-size: 13px;
            color: #0f172a;
            box-sizing: border-box;
        }

        .customers-controls {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .customers-sync-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.58);
        }

        .customers-filter-panel {
            border-top: 1px solid rgba(15, 23, 42, 0.08);
            padding-top: 12px;
            display: grid;
            gap: 10px;
        }

        .customers-filter-panel[hidden] {
            display: none !important;
        }

        .customers-filter-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .customers-index-table {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 12px;
            background: #fff;
            overflow: hidden;
        }

        .customers-index-scroll {
            overflow-x: auto;
        }

        .customers-index-table table {
            width: 100%;
            border-collapse: collapse;
            min-width: 940px;
        }

        .customers-index-table th,
        .customers-index-table td {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            text-align: left;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.86);
            vertical-align: top;
        }

        .customers-index-table th {
            font-size: 11px;
            font-weight: 700;
            color: rgba(15, 23, 42, 0.64);
            background: rgba(248, 250, 252, 0.75);
            white-space: nowrap;
        }

        .customers-sort-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: inherit;
            text-decoration: none;
        }

        .customers-sort-indicator {
            font-size: 11px;
            color: rgba(15, 23, 42, 0.4);
        }

        .customers-row-clickable {
            cursor: pointer;
        }

        .customers-row-clickable:hover {
            background: rgba(248, 250, 252, 0.75);
        }

        .customers-identity-name {
            color: #0f172a;
            text-decoration: none;
            font-weight: 600;
        }

        .customers-identity-meta {
            margin: 2px 0 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.55);
        }

        .customers-status-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: rgba(248, 250, 252, 0.8);
        }

        .customers-status-badge--active {
            border-color: rgba(15, 118, 110, 0.35);
            color: #115e59;
            background: rgba(15, 118, 110, 0.12);
        }

        .customers-status-badge--needs_contact {
            border-color: rgba(180, 83, 9, 0.35);
            color: #92400e;
            background: rgba(245, 158, 11, 0.16);
        }

        .customers-row-action {
            color: #0f766e;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
        }

        .customers-index-empty {
            text-align: center !important;
            padding: 20px 12px !important;
            color: rgba(15, 23, 42, 0.58) !important;
        }

        .customers-empty-state {
            border: 1px dashed rgba(15, 23, 42, 0.2);
            border-radius: 12px;
            background: rgba(248, 250, 252, 0.7);
            padding: 22px;
            text-align: center;
            display: grid;
            gap: 10px;
        }

        .customers-empty-state h2 {
            margin: 0;
            font-size: 1rem;
            color: #0f172a;
        }

        .customers-empty-state p {
            margin: 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.62);
        }

        .customers-pagination {
            margin-top: 12px;
        }

        @media (max-width: 980px) {
            .customers-summary-strip {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .customers-query-row {
                grid-template-columns: 1fr;
            }

            .customers-controls {
                justify-content: flex-start;
            }

            .customers-filter-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <section class="customers-page" data-customers-page>
        <div class="customers-summary-strip" aria-label="Customer summary">
            <article class="customers-summary-card">
                <p class="customers-summary-label">Total customers</p>
                <p class="customers-summary-value">{{ number_format($summaryTotal) }}</p>
            </article>
            <article class="customers-summary-card">
                <p class="customers-summary-label">Reachable customers</p>
                <p class="customers-summary-value">{{ number_format($summaryReachable) }}</p>
            </article>
            <article class="customers-summary-card">
                <p class="customers-summary-label">Customers with points</p>
                <p class="customers-summary-value">{{ number_format($summaryWithPoints) }}</p>
            </article>
            <article class="customers-summary-card">
                <p class="customers-summary-label">Last synced</p>
                <p class="customers-summary-value">{{ $lastSync }}</p>
                <p class="customers-summary-meta">{{ (string) ($importSummary['label'] ?? 'Not synced') }}</p>
            </article>
        </div>

        <form method="GET" action="{{ $customersManageEndpoint ?? request()->url() }}" class="customers-toolbar" data-customers-toolbar>
            @foreach($contextFields as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ (string) $value }}" />
            @endforeach

            <div class="customers-toolbar-head">
                <div>
                    <h2 class="customers-toolbar-title">All customers</h2>
                    <p class="customers-toolbar-copy">Search customers, apply filters, and open a profile.</p>
                </div>
                <div class="customers-toolbar-actions">
                    <a class="customers-action-button customers-action-button--primary" href="{{ $embeddedUrl($primarySyncHref) }}">{{ $primarySyncLabel }}</a>
                    <a class="customers-action-link" href="{{ $embeddedUrl(route('shopify.app.customers.segments', [], false)) }}">Create segment</a>
                </div>
            </div>

            <div class="customers-query-row">
                <div class="customers-field">
                    <label for="customers-search">Search</label>
                    <input
                        id="customers-search"
                        name="search"
                        type="search"
                        value="{{ (string) $filters['search'] }}"
                        placeholder="Search name, email, phone, or customer ID"
                    />
                </div>

                <div class="customers-field">
                    <label for="customers-segment">Segment</label>
                    <select id="customers-segment" name="segment">
                        <option value="all" @selected($filters['segment'] === 'all')>All customers</option>
                        <option value="with_points" @selected($filters['segment'] === 'with_points')>With points</option>
                        <option value="reachable" @selected($filters['segment'] === 'reachable')>Reachable</option>
                        <option value="needs_contact" @selected($filters['segment'] === 'needs_contact')>Needs contact</option>
                    </select>
                </div>

                <div class="customers-field">
                    <label for="customers-sort">Sort</label>
                    <select id="customers-sort" name="sort">
                        @foreach($sortOptions as $value => $label)
                            <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="customers-controls">
                    <button
                        type="button"
                        class="customers-filter-toggle"
                        data-customers-toggle-filters
                        aria-expanded="{{ $filtersOpenByDefault ? 'true' : 'false' }}"
                        aria-controls="customers-filter-panel"
                    >
                        Filters
                    </button>
                    <select name="direction" aria-label="Sort direction">
                        <option value="desc" @selected($direction === 'desc')>Newest first</option>
                        <option value="asc" @selected($direction === 'asc')>Oldest first</option>
                    </select>
                    <button type="submit" class="customers-action-button customers-action-button--primary">Apply filters</button>
                </div>
            </div>

            <div class="customers-sync-meta">
                <span>Sync status: {{ (string) ($importSummary['label'] ?? 'Not synced') }}</span>
                <div class="customers-controls">
                    <a class="customers-action-link" href="{{ $embeddedUrl(route('shopify.app.customers.manage', array_merge($contextFields, ['refresh' => 1]), false)) }}">Refresh table</a>
                    <a class="customers-action-link" href="{{ $embeddedUrl(route('shopify.app.customers.manage', $filterReset, false)) }}">Reset view</a>
                </div>
            </div>

            <div class="customers-filter-panel" id="customers-filter-panel" data-customers-filter-panel @if(! $filtersOpenByDefault) hidden @endif>
                <div class="customers-filter-grid">
                    <div class="customers-field">
                        <label for="customers-candle-club">Candle Club</label>
                        <select id="customers-candle-club" name="candle_club">
                            <option value="all" @selected($filters['candle_club'] === 'all')>All</option>
                            <option value="yes" @selected($filters['candle_club'] === 'yes')>Active</option>
                            <option value="no" @selected($filters['candle_club'] === 'no')>Not active</option>
                        </select>
                    </div>
                    <div class="customers-field">
                        <label for="customers-balance">Points balance</label>
                        <select id="customers-balance" name="candle_cash">
                            <option value="all" @selected($filters['candle_cash'] === 'all')>All</option>
                            <option value="yes" @selected($filters['candle_cash'] === 'yes')>Has points</option>
                            <option value="no" @selected($filters['candle_cash'] === 'no')>No points</option>
                        </select>
                    </div>
                    <div class="customers-field">
                        <label for="customers-referral">Referral</label>
                        <select id="customers-referral" name="referral">
                            <option value="all" @selected($filters['referral'] === 'all')>All</option>
                            <option value="yes" @selected($filters['referral'] === 'yes')>Completed</option>
                            <option value="no" @selected($filters['referral'] === 'no')>Not completed</option>
                        </select>
                    </div>
                    <div class="customers-field">
                        <label for="customers-review">Review</label>
                        <select id="customers-review" name="review">
                            <option value="all" @selected($filters['review'] === 'all')>All</option>
                            <option value="yes" @selected($filters['review'] === 'yes')>Completed</option>
                            <option value="no" @selected($filters['review'] === 'no')>Not completed</option>
                        </select>
                    </div>
                    <div class="customers-field">
                        <label for="customers-birthday">Birthday</label>
                        <select id="customers-birthday" name="birthday">
                            <option value="all" @selected($filters['birthday'] === 'all')>All</option>
                            <option value="yes" @selected($filters['birthday'] === 'yes')>Completed</option>
                            <option value="no" @selected($filters['birthday'] === 'no')>Not completed</option>
                        </select>
                    </div>
                    <div class="customers-field">
                        <label for="customers-wholesale">Wholesale</label>
                        <select id="customers-wholesale" name="wholesale">
                            <option value="all" @selected($filters['wholesale'] === 'all')>All</option>
                            <option value="yes" @selected($filters['wholesale'] === 'yes')>Eligible</option>
                            <option value="no" @selected($filters['wholesale'] === 'no')>Not eligible</option>
                        </select>
                    </div>
                </div>
                <div class="customers-controls">
                    <button type="submit" class="customers-action-button customers-action-button--primary">Apply filters</button>
                </div>
            </div>
        </form>

        @if($summaryTotal === 0)
            <section class="customers-empty-state" aria-label="No customers synced yet">
                <h2>No customers synced yet</h2>
                <p>Start Shopify sync to load customer profiles and loyalty status.</p>
                <div class="customers-controls" style="justify-content: center;">
                    <a class="customers-action-button customers-action-button--primary" href="{{ $embeddedUrl((string) ($importCta['href'] ?? route('shopify.app.integrations', [], false))) }}">Sync customers</a>
                    <a class="customers-action-link" href="{{ $embeddedUrl(route('shopify.app.integrations', [], false)) }}">Review sync settings</a>
                </div>
            </section>
        @else
            @include('shopify.partials.customers-manage-results', [
                'customers' => $customers,
                'filters' => $filters,
                'sort' => $sort,
                'direction' => $direction,
                'displayLabels' => $displayLabels ?? [],
            ])
        @endif
    </section>

    <script>
        (() => {
            const toolbar = document.querySelector('[data-customers-toolbar]');
            if (!toolbar) {
                return;
            }

            const toggle = toolbar.querySelector('[data-customers-toggle-filters]');
            const panel = toolbar.querySelector('[data-customers-filter-panel]');

            if (!toggle || !panel) {
                return;
            }

            const setExpanded = (expanded) => {
                panel.hidden = !expanded;
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            };

            toggle.addEventListener('click', () => {
                setExpanded(panel.hidden);
            });
        })();
    </script>
</x-shopify.customers-layout>
