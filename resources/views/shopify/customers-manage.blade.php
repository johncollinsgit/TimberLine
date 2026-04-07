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
        $perPage = (int) ($filters['per_page'] ?? 25);
        $sortOptions = collect($gridSortOptions ?? [])->pluck('label', 'value')->all();
        $resultsDeferred = (bool) ($customersResultsDeferred ?? false);
        $currentCount = method_exists($customers, 'count') ? (int) $customers->count() : 0;
        $currentPage = method_exists($customers, 'currentPage') ? (int) $customers->currentPage() : 1;
        $summaryLabel = $resultsDeferred
            ? 'Search to load customers'
            : sprintf(
                '%s customer%s loaded · Page %s',
                number_format($currentCount),
                $currentCount === 1 ? '' : 's',
                number_format($currentPage)
            );
        $pageLabel = $resultsDeferred
            ? 'Search to view matching records'
            : (method_exists($customers, 'hasMorePages') && $customers->hasMorePages()
                ? 'Page ' . number_format($currentPage) . ' · More results available'
                : 'Page ' . number_format($currentPage));
        $defaultGridFilters = [
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
        ];
        $filtersOpenByDefault = (int) ($activeFilterCount ?? 0) > 0;
        $resolvedRewardsLabel = trim((string) ($rewardsLabel ?? data_get($displayLabels ?? [], 'rewards_label', 'Rewards')));
        if ($resolvedRewardsLabel === '') {
            $resolvedRewardsLabel = 'Rewards';
        }
        $resolvedRewardsBalanceLabel = trim((string) ($rewardsBalanceLabel ?? data_get($displayLabels ?? [], 'rewards_balance_label', $resolvedRewardsLabel . ' balance')));
        if ($resolvedRewardsBalanceLabel === '') {
            $resolvedRewardsBalanceLabel = $resolvedRewardsLabel . ' balance';
        }
        $sortOptions['candle_cash'] = \Illuminate\Support\Str::title($resolvedRewardsBalanceLabel);
        $sortOptions['rewards_actions'] = \Illuminate\Support\Str::title($resolvedRewardsLabel . ' actions');
        $journey = is_array($merchantJourney ?? null) ? $merchantJourney : [];
        $importSummary = is_array($journey['import_summary'] ?? null) ? $journey['import_summary'] : [];
        $importState = (string) ($importSummary['state'] ?? 'not_started');
        $syncIsStale = (bool) ($importSummary['is_stale'] ?? false);
        $importCta = is_array($importSummary['cta'] ?? null) ? $importSummary['cta'] : ['label' => 'Sync customers', 'href' => route('shopify.app.integrations', [], false)];
        $customerSummary = is_array($journey['customer_summary'] ?? null)
            ? $journey['customer_summary']
            : ['total_profiles' => 0, 'reachable_profiles' => 0, 'customers_with_points' => 0];
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
        /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrls */
        $embeddedUrls = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
        $embeddedContext = $embeddedUrls->contextQuery(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => $embeddedUrls->append($url, $embeddedContext);
    @endphp

    <style>
        .customers-manage-root {
            display: grid;
            gap: 14px;
        }

        .customers-summary-strip {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .customers-summary-card {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.04);
            padding: 14px 16px;
        }

        .customers-summary-card h2 {
            margin: 0;
            font-size: 12px;
            font-weight: 600;
            color: rgba(15, 23, 42, 0.58);
        }

        .customers-summary-card p {
            margin: 8px 0 0;
            font-size: 1.1rem;
            font-weight: 700;
            color: #0f172a;
        }

        .customers-summary-card small {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.52);
        }

        .customers-toolbar {
            display: grid;
            gap: 14px;
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.97);
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.06);
            padding: 16px;
        }

        .customers-toolbar-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .customers-toolbar-copy {
            display: grid;
            gap: 4px;
        }

        .customers-toolbar-copy h2 {
            margin: 0;
            font-size: 0.98rem;
            font-weight: 650;
            letter-spacing: -0.01em;
            color: #0f172a;
        }

        .customers-toolbar-copy p {
            margin: 0;
            font-size: 13px;
            line-height: 1.5;
            color: rgba(15, 23, 42, 0.58);
        }

        .customers-toolbar-summary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.96);
            padding: 0 12px;
            font-size: 12px;
            font-weight: 600;
            color: rgba(15, 23, 42, 0.64);
            white-space: nowrap;
        }

        .customers-toolbar-row {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) minmax(170px, 210px) auto minmax(150px, 180px) minmax(108px, 132px);
            gap: 10px;
            align-items: stretch;
        }

        .customers-search {
            position: relative;
            display: grid;
            gap: 6px;
        }

        .customers-search label,
        .customers-control label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.48);
        }

        .customers-search input,
        .customers-control select,
        .customers-filter-field select {
            width: 100%;
            box-sizing: border-box;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #fff;
            min-height: 44px;
            padding: 10px 14px;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.84);
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .customers-search input:focus,
        .customers-control select:focus,
        .customers-filter-field select:focus {
            outline: none;
            border-color: rgba(15, 143, 97, 0.38);
            box-shadow: 0 0 0 4px rgba(15, 143, 97, 0.1);
        }

        .customers-search input {
            padding-left: 42px;
        }

        .customers-search-icon {
            position: absolute;
            left: 14px;
            bottom: 13px;
            width: 16px;
            height: 16px;
            color: rgba(15, 23, 42, 0.38);
            pointer-events: none;
        }

        .customers-search-icon svg {
            display: block;
            width: 100%;
            height: 100%;
        }

        .customers-button,
        .customers-filter-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(255, 255, 255, 0.96);
            color: rgba(15, 23, 42, 0.8);
            text-decoration: none;
            min-height: 44px;
            padding: 0 14px;
            font-size: 12px;
            font-weight: 630;
            cursor: pointer;
            transition: border-color 0.18s ease, background 0.18s ease, color 0.18s ease, transform 0.18s ease;
        }

        .customers-button:hover,
        .customers-filter-toggle:hover {
            border-color: rgba(15, 23, 42, 0.22);
            background: rgba(255, 255, 255, 1);
            color: rgba(15, 23, 42, 0.96);
            transform: translateY(-1px);
        }

        .customers-button:focus-visible,
        .customers-filter-toggle:focus-visible {
            outline: none;
            box-shadow: 0 0 0 4px rgba(15, 143, 97, 0.1);
        }

        .customers-filter-toggle.is-active {
            border-color: rgba(15, 143, 97, 0.24);
            background: rgba(15, 143, 97, 0.1);
            color: #0d6f4d;
        }

        .customers-filter-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            min-height: 20px;
            border-radius: 999px;
            background: rgba(15, 143, 97, 0.14);
            color: #0d6f4d;
            font-size: 11px;
            font-weight: 700;
            padding: 0 6px;
        }

        .customers-filter-badge[hidden] {
            display: none !important;
        }

        .customers-control {
            display: grid;
            gap: 6px;
        }

        .customers-toolbar-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .customers-page-note,
        .customers-live-note {
            font-size: 12px;
            color: rgba(15, 23, 42, 0.58);
        }

        .customers-live-note {
            min-height: 18px;
        }

        .customers-active-filters {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .customers-active-filters[hidden] {
            display: none !important;
        }

        .customers-filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.95);
            padding: 6px 10px;
            font-size: 11px;
            font-weight: 620;
            color: rgba(15, 23, 42, 0.68);
        }

        .customers-filter-chip__label {
            color: rgba(15, 23, 42, 0.46);
        }

        .customers-filter-panel {
            display: grid;
            gap: 12px;
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.78);
            padding: 14px;
        }

        .customers-filter-panel[hidden] {
            display: none !important;
        }

        .customers-filter-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .customers-filter-field {
            display: grid;
            gap: 6px;
        }

        .customers-filter-field label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.48);
        }

        .customers-filter-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .customers-filter-actions-copy {
            font-size: 12px;
            color: rgba(15, 23, 42, 0.56);
        }

        .customers-filter-actions-buttons {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .customers-button.is-primary {
            border-color: rgba(15, 143, 97, 0.28);
            background: rgba(15, 143, 97, 0.12);
            color: #0d6f4d;
        }

        .customers-button.is-link {
            background: transparent;
        }

        .customers-results-shell {
            position: relative;
            display: grid;
            gap: 10px;
            --customers-results-height: auto;
        }

        .customers-results-shell.is-loading [data-customers-results] {
            min-height: var(--customers-results-height);
        }

        .customers-results-shell.is-loading .customers-table-wrap,
        .customers-results-shell.is-loading .customers-pagination {
            opacity: 0.68;
        }

        .customers-results-shell[aria-busy="true"]::before {
            content: "";
            position: absolute;
            inset: 0 auto auto 0;
            width: 100%;
            height: 2px;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(15, 143, 97, 0), rgba(15, 143, 97, 0.72), rgba(15, 143, 97, 0));
            animation: customers-progress 1.2s linear infinite;
        }

        .customers-results-shell[aria-busy="true"]::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            border-radius: 16px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0));
        }

        .customers-results-status {
            min-height: 18px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.58);
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .customers-results-status[data-tone="error"] {
            color: #b42318;
        }

        .customers-results-status:empty {
            min-height: 0;
        }

        .customers-results-status-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 0 10px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(255, 255, 255, 0.98);
            color: rgba(15, 23, 42, 0.78);
            font-size: 11px;
            font-weight: 620;
            cursor: pointer;
            transition: border-color 0.18s ease, background 0.18s ease;
        }

        .customers-results-status-action:hover {
            border-color: rgba(15, 23, 42, 0.22);
            background: #fff;
        }

        .customers-results-status-action:focus-visible {
            outline: none;
            box-shadow: 0 0 0 4px rgba(15, 143, 97, 0.1);
        }

        .customers-table-wrap {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.05);
            overflow: hidden;
            position: relative;
            transition: opacity 0.18s ease;
        }

        .customers-results-shell.is-loading .customers-table-wrap::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(90deg, rgba(255, 255, 255, 0) 0%, rgba(248, 250, 252, 0.64) 48%, rgba(255, 255, 255, 0) 100%);
            transform: translateX(-100%);
            animation: customers-sheen 1.2s ease-in-out infinite;
        }

        .customers-table-scroll {
            overflow-x: auto;
        }

        .customers-table-wrap table {
            width: 100%;
            min-width: 1120px;
            border-collapse: collapse;
        }

        .customers-table-wrap th {
            text-align: left;
            padding: 11px 13px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.11em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.56);
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.96);
            white-space: nowrap;
        }

        .customers-table-wrap td {
            padding: 11px 13px;
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
            background: rgba(15, 23, 42, 0.018);
        }

        .customers-row--clickable:focus-visible {
            outline: 2px solid rgba(15, 143, 97, 0.4);
            outline-offset: -2px;
        }

        .customers-name-cell {
            min-width: 200px;
        }

        .customers-name-link {
            color: rgba(15, 23, 42, 0.94);
            text-decoration: none;
            font-weight: 630;
        }

        .customers-name-link:hover {
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
            color: inherit;
            text-decoration: none;
        }

        .customers-sort-indicator {
            font-size: 10px;
            color: rgba(15, 23, 42, 0.42);
        }

        .customers-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 82px;
            border-radius: 999px;
            padding: 4px 8px;
            border: 1px solid rgba(15, 23, 42, 0.13);
            font-size: 11px;
            font-weight: 620;
            line-height: 1.2;
        }

        .customers-status.is-yes,
        .customers-status--active {
            border-color: rgba(15, 143, 97, 0.28);
            background: rgba(15, 143, 97, 0.1);
            color: #0d6f4d;
        }

        .customers-status.is-no,
        .customers-status--standard {
            border-color: rgba(148, 163, 184, 0.24);
            background: rgba(148, 163, 184, 0.08);
            color: #475569;
        }

        .customers-status--needs_contact {
            border-color: rgba(202, 138, 4, 0.26);
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }

        .customers-button--row {
            min-height: 34px;
            padding: 0 12px;
        }

        .customers-empty {
            padding: 28px 20px;
            text-align: center;
            color: rgba(15, 23, 42, 0.58);
            background: rgba(248, 250, 252, 0.84);
            font-size: 13px;
            line-height: 1.6;
        }

        .customers-pagination {
            display: flex;
            justify-content: flex-end;
            padding-top: 2px;
        }

        .customers-pagination nav {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .customers-pagination .page-link,
        .customers-pagination a,
        .customers-pagination span {
            border-radius: 999px;
        }

        @keyframes customers-progress {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        @keyframes customers-sheen {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        @media (max-width: 1080px) {
            .customers-toolbar-row {
                grid-template-columns: minmax(0, 1fr) repeat(4, minmax(0, 1fr));
            }

            .customers-filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .customers-summary-strip {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 860px) {
            .customers-toolbar-row {
                grid-template-columns: 1fr 1fr;
            }

            .customers-search {
                grid-column: 1 / -1;
            }

            .customers-filter-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .customers-toolbar {
                padding: 14px;
            }

            .customers-toolbar-row {
                grid-template-columns: 1fr;
            }

            .customers-toolbar-summary {
                white-space: normal;
            }

            .customers-filter-actions {
                align-items: stretch;
            }

            .customers-filter-actions-buttons {
                width: 100%;
            }

            .customers-filter-actions-buttons > * {
                flex: 1 1 auto;
            }

        .customers-pagination {
            justify-content: stretch;
        }

        .customers-control select:disabled,
        .customers-filter-toggle:disabled {
            cursor: not-allowed;
            background: rgba(248, 250, 252, 0.96);
            color: rgba(15, 23, 42, 0.42);
        }

        .customers-empty-state {
            display: grid;
            gap: 8px;
            border-radius: 18px;
            border: 1px dashed rgba(15, 23, 42, 0.14);
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.96), rgba(255, 255, 255, 0.98));
            padding: 24px;
        }

        .customers-empty-state__eyebrow {
            margin: 0;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.46);
        }

        .customers-empty-state h3 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: #0f172a;
        }

        .customers-empty-state p {
            margin: 0;
            max-width: 44rem;
            font-size: 13px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.58);
        }

        .customers-summary-strip {
            grid-template-columns: 1fr;
        }
        }
    </style>

    <section
        class="customers-manage-root"
        data-customers-manage
        data-endpoint="{{ $customersManageEndpoint ?? request()->url() }}"
        data-default-filters='@json($defaultGridFilters)'
        data-filter-count="{{ (int) ($activeFilterCount ?? 0) }}"
        data-results-deferred="{{ $resultsDeferred ? 'true' : 'false' }}"
    >
        <div class="customers-summary-strip" aria-label="Customer summary">
            <article class="customers-summary-card">
                <h2>Total customers</h2>
                <p>{{ number_format($summaryTotal) }}</p>
                <small>Profiles available now.</small>
            </article>
            <article class="customers-summary-card">
                <h2>Reachable customers</h2>
                <p>{{ number_format($summaryReachable) }}</p>
                <small>Email or phone on file.</small>
            </article>
            <article class="customers-summary-card">
                <h2>Customers with points</h2>
                <p>{{ number_format($summaryWithPoints) }}</p>
                <small>Points balance above zero.</small>
            </article>
            <article class="customers-summary-card">
                <h2>Last sync</h2>
                <p>{{ $lastSync }}</p>
                <small>{{ $syncIsStale ? 'Sync needs refresh.' : 'Current sync status.' }}</small>
            </article>
        </div>

        @if($importState !== 'imported')
            <article class="customers-surface">
                <h2>No customers synced yet</h2>
                <p>Start Shopify sync to load customer profiles and loyalty status.</p>
                <p class="customers-muted-note">{{ $importSummary['progress_note'] ?? 'No import has run yet for this store context.' }}</p>
                <div class="plans-meta">
                    <a class="start-here-action-link" href="{{ $embeddedUrl((string) ($importCta['href'] ?? route('shopify.app.integrations', [], false))) }}">{{ $importCta['label'] ?? 'Sync customers' }}</a>
                    <a class="start-here-action-link" href="{{ $embeddedUrl(route('shopify.app.integrations', [], false)) }}">Review sync settings</a>
                </div>
            </article>
        @endif

        <form method="GET" action="{{ url()->current() }}" class="customers-toolbar" data-customers-form novalidate>
            @foreach($embeddedContext as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}" />
            @endforeach

            <div class="customers-toolbar-head">
                <div class="customers-toolbar-copy">
                    <h2>All customers</h2>
                    <p>Search customers first, then use filters to narrow the matching list.</p>
                </div>
                <div class="customers-toolbar-summary" data-customers-summary>{{ $summaryLabel }}</div>
            </div>

            <div class="customers-toolbar-row">
                <div class="customers-search">
                    <label for="customers-search">Search customers</label>
                    <span class="customers-search-icon" aria-hidden="true">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="8.5" cy="8.5" r="5.5"></circle>
                            <path d="M13 13l4 4"></path>
                        </svg>
                    </span>
                    <input
                        id="customers-search"
                        name="search"
                        type="text"
                        value="{{ (string) $filters['search'] }}"
                        placeholder="Search name, email, phone, or customer ID"
                        autocomplete="off"
                        data-customers-search
                    />
                </div>

                <div class="customers-control">
                    <label for="customers-segment">Segment</label>
                    <select id="customers-segment" name="segment" data-customers-live="change">
                        <option value="all" @selected(($filters['segment'] ?? 'all') === 'all')>All customers</option>
                        <option value="with_points" @selected(($filters['segment'] ?? 'all') === 'with_points')>With points</option>
                        <option value="reachable" @selected(($filters['segment'] ?? 'all') === 'reachable')>Reachable</option>
                        <option value="needs_contact" @selected(($filters['segment'] ?? 'all') === 'needs_contact')>Needs contact</option>
                    </select>
                </div>

                <button
                    type="button"
                    class="customers-filter-toggle {{ $filtersOpenByDefault ? 'is-active' : '' }}"
                    data-customers-toggle-filters
                    aria-expanded="{{ $filtersOpenByDefault ? 'true' : 'false' }}"
                    aria-controls="customers-filter-panel"
                >
                    Filters
                    <span class="customers-filter-badge" data-customers-filter-badge @if((int) ($activeFilterCount ?? 0) === 0) hidden @endif>
                        {{ (int) ($activeFilterCount ?? 0) }}
                    </span>
                </button>

                <div class="customers-control">
                    <label for="customers-sort">Sort</label>
                    <select id="customers-sort" name="sort" data-customers-live="change" @disabled($resultsDeferred)>
                        @foreach($sortOptions as $value => $label)
                            <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="customers-control">
                    <label for="customers-per-page">Rows</label>
                    <select id="customers-per-page" name="per_page" data-customers-live="change" @disabled($resultsDeferred)>
                        @foreach([25, 50, 100] as $count)
                            <option value="{{ $count }}" @selected($perPage === $count)>{{ $count }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="customers-toolbar-meta">
                <div class="customers-page-note" data-customers-page-note>
                    {{ $pageLabel }}
                </div>
                <div class="customers-live-note" data-customers-live-note aria-live="polite"></div>
            </div>

            <div class="customers-active-filters" data-customers-active-filters @if((int) ($activeFilterCount ?? 0) === 0) hidden @endif></div>

            <div class="customers-filter-panel" id="customers-filter-panel" data-customers-filter-panel @if(! $filtersOpenByDefault) hidden @endif>
                <div class="customers-filter-grid">
                    <div class="customers-filter-field">
                        <label for="candle_club">Candle Club</label>
                        <select id="candle_club" name="candle_club" data-customers-live="change">
                            <option value="all" @selected($filters['candle_club'] === 'all')>All customers</option>
                            <option value="yes" @selected($filters['candle_club'] === 'yes')>Active</option>
                            <option value="no" @selected($filters['candle_club'] === 'no')>Not active</option>
                        </select>
                    </div>

                    <div class="customers-filter-field">
                        <label for="candle_cash">{{ $resolvedRewardsBalanceLabel }}</label>
                        <select id="candle_cash" name="candle_cash" data-customers-live="change">
                            <option value="all" @selected($filters['candle_cash'] === 'all')>All balances</option>
                            <option value="yes" @selected($filters['candle_cash'] === 'yes')>Has balance</option>
                            <option value="no" @selected($filters['candle_cash'] === 'no')>No balance</option>
                        </select>
                    </div>

                    <div class="customers-filter-field">
                        <label for="referral">Referral</label>
                        <select id="referral" name="referral" data-customers-live="change">
                            <option value="all" @selected($filters['referral'] === 'all')>All states</option>
                            <option value="yes" @selected($filters['referral'] === 'yes')>Completed</option>
                            <option value="no" @selected($filters['referral'] === 'no')>Not completed</option>
                        </select>
                    </div>

                    <div class="customers-filter-field">
                        <label for="review">Review</label>
                        <select id="review" name="review" data-customers-live="change">
                            <option value="all" @selected($filters['review'] === 'all')>All states</option>
                            <option value="yes" @selected($filters['review'] === 'yes')>Completed</option>
                            <option value="no" @selected($filters['review'] === 'no')>Not completed</option>
                        </select>
                    </div>

                    <div class="customers-filter-field">
                        <label for="birthday">Birthday</label>
                        <select id="birthday" name="birthday" data-customers-live="change">
                            <option value="all" @selected($filters['birthday'] === 'all')>All states</option>
                            <option value="yes" @selected($filters['birthday'] === 'yes')>Completed</option>
                            <option value="no" @selected($filters['birthday'] === 'no')>Not completed</option>
                        </select>
                    </div>

                    <div class="customers-filter-field">
                        <label for="wholesale">Wholesale</label>
                        <select id="wholesale" name="wholesale" data-customers-live="change">
                            <option value="all" @selected($filters['wholesale'] === 'all')>All states</option>
                            <option value="yes" @selected($filters['wholesale'] === 'yes')>Eligible</option>
                            <option value="no" @selected($filters['wholesale'] === 'no')>Not eligible</option>
                        </select>
                    </div>
                </div>

                <div class="customers-filter-actions">
                    <div class="customers-filter-actions-copy">
                        {{ $resultsDeferred ? 'Filters are saved and apply as soon as you start searching.' : 'Filters update the table immediately.' }}
                    </div>
                    <div class="customers-filter-actions-buttons">
                        <button type="button" class="customers-button is-link" data-customers-clear-filters @if((int) ($activeFilterCount ?? 0) === 0) hidden @endif>
                            Clear filters
                        </button>
                        <noscript>
                            <button type="submit" class="customers-button is-primary">Apply search</button>
                        </noscript>
                    </div>
                </div>
            </div>
        </form>

        <section class="customers-results-shell" data-customers-results-shell aria-busy="false">
            <div class="customers-results-status" data-customers-results-status aria-live="polite"></div>
            <div data-customers-results>
                @include('shopify.partials.customers-manage-results', [
                    'customers' => $customers,
                    'filters' => $filters,
                    'sort' => $sort,
                    'direction' => $direction,
                    'resultsDeferred' => $resultsDeferred,
                ])
            </div>
        </section>

        <template data-customers-deferred-template>
            @include('shopify.partials.customers-manage-results', [
                'customers' => $customers,
                'filters' => $filters,
                'sort' => $sort,
                'direction' => $direction,
                'resultsDeferred' => true,
            ])
        </template>
    </section>

    <script>
        (() => {
            const root = document.querySelector('[data-customers-manage]');
            if (!root || root.dataset.customersManageBound === 'true') {
                return;
            }

            root.dataset.customersManageBound = 'true';

            const form = root.querySelector('[data-customers-form]');
            const resultsShell = root.querySelector('[data-customers-results-shell]');
            const resultsNode = root.querySelector('[data-customers-results]');
            const summaryNode = root.querySelector('[data-customers-summary]');
            const pageNoteNode = root.querySelector('[data-customers-page-note]');
            const liveNoteNode = root.querySelector('[data-customers-live-note]');
            const resultsStatusNode = root.querySelector('[data-customers-results-status]');
            const filtersPanel = root.querySelector('[data-customers-filter-panel]');
            const toggleFiltersButton = root.querySelector('[data-customers-toggle-filters]');
            const filterBadge = root.querySelector('[data-customers-filter-badge]');
            const clearFiltersButton = root.querySelector('[data-customers-clear-filters]');
            const activeFiltersNode = root.querySelector('[data-customers-active-filters]');
            const searchInput = root.querySelector('[data-customers-search]');
            const deferredTemplate = root.querySelector('[data-customers-deferred-template]');

            if (!form || !resultsShell || !resultsNode || !toggleFiltersButton) {
                return;
            }

            const defaultFilters = JSON.parse(root.dataset.defaultFilters || '{}');
            const endpoint = root.dataset.endpoint || window.location.pathname;
            const detailCache = (() => {
                try {
                    return window.sessionStorage;
                } catch (error) {
                    return null;
                }
            })();
            const detailCacheTtlMs = 60 * 1000;
            let controller = null;
            let debounceTimer = null;
            let requestSequence = 0;
            let lastRequestedUrl = null;
            let authHeadersPromise = null;
            const detailPrefetches = new Map();
            const deferredSummaryLabel = 'Search to load customers';
            const deferredPageLabel = 'Search to view matching records';

            function cleanValue(value) {
                return value == null ? '' : String(value).trim();
            }

            function hasSearchQuery() {
                return cleanValue(searchInput && searchInput.value).length > 0;
            }

            function filterFieldKeys() {
                return ['segment', 'candle_club', 'candle_cash', 'referral', 'review', 'birthday', 'wholesale'];
            }

            function activeFilterEntries() {
                return filterFieldKeys()
                    .map((key) => {
                        const field = form.elements.namedItem(key);
                        const value = cleanValue(field && field.value);
                        if (!value || value === cleanValue(defaultFilters[key] ?? 'all') || value === 'all') {
                            return null;
                        }

                        const label = field && field.closest('.customers-filter-field')?.querySelector('label');
                        const optionLabel = field && field.options[field.selectedIndex]
                            ? cleanValue(field.options[field.selectedIndex].textContent)
                            : value;

                        return {
                            key,
                            label: cleanValue(label && label.textContent) || key.replace(/_/g, ' '),
                            value: optionLabel,
                        };
                    })
                    .filter(Boolean);
            }

            function setFiltersOpen(open) {
                if (!filtersPanel) {
                    return;
                }

                filtersPanel.hidden = !open;
                toggleFiltersButton.classList.toggle('is-active', open);
                toggleFiltersButton.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            function syncFilterSummary() {
                const entries = activeFilterEntries();
                const count = entries.length;

                if (filterBadge) {
                    filterBadge.hidden = count === 0;
                    filterBadge.textContent = String(count);
                }

                if (clearFiltersButton) {
                    clearFiltersButton.hidden = count === 0;
                }

                if (!activeFiltersNode) {
                    return;
                }

                if (!count) {
                    activeFiltersNode.hidden = true;
                    activeFiltersNode.innerHTML = '';
                    return;
                }

                activeFiltersNode.hidden = false;
                activeFiltersNode.innerHTML = entries.map((entry) => (
                    `<span class="customers-filter-chip"><span class="customers-filter-chip__label">${entry.label}</span>${entry.value}</span>`
                )).join('');
            }

            function syncDeferredControls() {
                const deferred = !hasSearchQuery();
                root.dataset.resultsDeferred = deferred ? 'true' : 'false';
                form.querySelectorAll('[name="sort"], [name="per_page"]').forEach((field) => {
                    field.disabled = deferred;
                });
            }

            function renderDeferredState(statusMessage = 'Enter a search to load customers.') {
                if (deferredTemplate) {
                    resultsNode.innerHTML = deferredTemplate.innerHTML;
                }
                if (summaryNode) {
                    summaryNode.textContent = deferredSummaryLabel;
                }
                if (pageNoteNode) {
                    pageNoteNode.textContent = deferredPageLabel;
                }

                window.history.replaceState({}, '', buildUrl({}, true).toString());
                syncDeferredControls();
                setPendingState(false);
                releaseResultsHeight();
                setStatusMessage(statusMessage, 'neutral', false);
            }

            function setStatusMessage(message = '', tone = 'neutral', retryable = false) {
                if (!resultsStatusNode) {
                    return;
                }

                resultsStatusNode.dataset.tone = tone;
                if (!message) {
                    resultsStatusNode.innerHTML = '';
                    return;
                }

                const retryButton = retryable
                    ? '<button type="button" class="customers-results-status-action" data-customers-retry>Retry</button>'
                    : '';

                resultsStatusNode.innerHTML = `<span>${message}</span>${retryButton}`;
            }

            function lockResultsHeight() {
                if (!resultsNode) {
                    return;
                }

                const currentHeight = resultsNode.offsetHeight;
                if (currentHeight > 0) {
                    resultsShell.style.setProperty('--customers-results-height', `${currentHeight}px`);
                }
            }

            function releaseResultsHeight() {
                if (!resultsNode) {
                    return;
                }

                const nextHeight = resultsNode.offsetHeight;
                if (nextHeight > 0) {
                    resultsShell.style.setProperty('--customers-results-height', `${nextHeight}px`);
                } else {
                    resultsShell.style.removeProperty('--customers-results-height');
                }

                window.setTimeout(() => {
                    if (resultsShell.getAttribute('aria-busy') === 'false') {
                        resultsShell.style.removeProperty('--customers-results-height');
                    }
                }, 180);
            }

            function setPendingState(pending, message = '') {
                resultsShell.classList.toggle('is-loading', pending);
                resultsShell.setAttribute('aria-busy', pending ? 'true' : 'false');
                if (liveNoteNode) {
                    liveNoteNode.textContent = pending ? message : '';
                }
                if (pending) {
                    setStatusMessage(message, 'neutral', false);
                } else if (resultsStatusNode?.dataset.tone !== 'error') {
                    setStatusMessage('');
                }
            }

            async function resolveAuthHeaders() {
                const shopifyBridge = window.shopify;
                if (!shopifyBridge || typeof shopifyBridge.idToken !== 'function') {
                    throw new Error('Shopify Admin verification is unavailable. Reload this page from Shopify Admin and try again.');
                }

                if (!authHeadersPromise) {
                    authHeadersPromise = Promise.race([
                        Promise.resolve(shopifyBridge.idToken()),
                        new Promise((resolve) => window.setTimeout(() => resolve(null), 1500)),
                    ]).then((token) => {
                        if (typeof token !== 'string' || token.trim() === '') {
                            authHeadersPromise = null;
                            throw new Error('Shopify Admin verification is unavailable. Reload this page from Shopify Admin and try again.');
                        }

                        return {
                            'Accept': 'application/json',
                            'Authorization': `Bearer ${token.trim()}`,
                            'X-Requested-With': 'XMLHttpRequest',
                        };
                    }).catch((error) => {
                        authHeadersPromise = null;
                        throw error;
                    });
                }

                return authHeadersPromise;
            }

            function detailCacheKey(profileId) {
                return `forestry:customer-detail-deferred:${profileId}`;
            }

            function cacheDetailPrefetch(profileId, data) {
                if (!detailCache || !profileId || !data || typeof data !== 'object') {
                    return;
                }

                try {
                    detailCache.setItem(detailCacheKey(profileId), JSON.stringify({
                        stored_at: Date.now(),
                        data,
                    }));
                } catch (error) {
                    // Ignore session storage failures.
                }
            }

            async function prefetchCustomerDetail(target) {
                const endpointUrl = cleanValue(target?.dataset?.customerPrefetchEndpoint);
                const profileId = cleanValue(target?.dataset?.customerPrefetchProfileId);
                if (!endpointUrl || !profileId || detailPrefetches.has(profileId)) {
                    return;
                }

                if (detailCache) {
                    try {
                        const cached = detailCache.getItem(detailCacheKey(profileId));
                        if (cached) {
                            const parsed = JSON.parse(cached);
                            if (parsed && typeof parsed.stored_at === 'number' && (Date.now() - parsed.stored_at) < detailCacheTtlMs) {
                                return;
                            }
                        }
                    } catch (error) {
                        // Ignore malformed cache entries and refetch.
                    }
                }

                const promise = (async () => {
                    try {
                        const headers = await resolveAuthHeaders();
                        const response = await fetch(new URL(endpointUrl, window.location.origin).toString(), {
                            method: 'GET',
                            credentials: 'same-origin',
                            headers,
                        });

                        const payload = await response.json().catch(() => null);
                        if (!response.ok || !payload?.ok || !payload?.data) {
                            return;
                        }

                        cacheDetailPrefetch(profileId, payload.data);
                    } catch (error) {
                        // Prefetch is best-effort only.
                    } finally {
                        detailPrefetches.delete(profileId);
                    }
                })();

                detailPrefetches.set(profileId, promise);
                await promise;
            }

            function buildUrl(overrides = {}, resetPage = false) {
                const url = new URL(endpoint, window.location.origin);
                const formData = new FormData(form);

                formData.forEach((value, key) => {
                    const stringValue = cleanValue(value);
                    if (key in defaultFilters && stringValue === cleanValue(defaultFilters[key])) {
                        return;
                    }

                    if (stringValue !== '') {
                        url.searchParams.set(key, stringValue);
                    }
                });

                Object.entries(overrides).forEach(([key, value]) => {
                    const stringValue = cleanValue(value);
                    if (stringValue === '') {
                        url.searchParams.delete(key);
                        return;
                    }

                    url.searchParams.set(key, stringValue);
                });

                if (resetPage) {
                    url.searchParams.delete('page');
                }

                return url;
            }

            function syncFormFromUrl(url) {
                const params = url.searchParams;

                Object.entries(defaultFilters).forEach(([key, defaultValue]) => {
                    const field = form.elements.namedItem(key);
                    if (!field) {
                        return;
                    }

                    field.value = params.get(key) ?? String(defaultValue);
                });

                syncFilterSummary();
            }

            async function requestRows(url, pendingMessage = 'Updating customers…') {
                requestSequence += 1;
                const sequence = requestSequence;
                lastRequestedUrl = url;

                if (controller) {
                    controller.abort();
                }

                controller = new AbortController();
                lockResultsHeight();
                setPendingState(true, pendingMessage);

                try {
                    const headers = await resolveAuthHeaders();
                    const response = await fetch(url.toString(), {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers,
                        signal: controller.signal,
                    });

                    const payload = await response.json().catch(() => ({
                        ok: false,
                        message: 'Unexpected response from Backstage.',
                    }));

                    if (!response.ok || !payload.ok || !payload.data) {
                        throw new Error(payload.message || 'Customers could not be loaded.');
                    }

                    if (sequence !== requestSequence) {
                        return;
                    }

                    resultsNode.innerHTML = payload.data.results_html || '';
                    if (summaryNode) {
                        summaryNode.textContent = payload.data.summary_label || '';
                    }
                    if (pageNoteNode) {
                        pageNoteNode.textContent = payload.data.page_label || '';
                    }

                    window.history.replaceState({}, '', url.toString());
                    syncFormFromUrl(url);
                    syncDeferredControls();
                    setStatusMessage('');
                    releaseResultsHeight();
                } catch (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }

                    if (liveNoteNode) {
                        liveNoteNode.textContent = error instanceof Error ? error.message : 'Customers could not be loaded.';
                    }
                    setStatusMessage(
                        error instanceof Error ? error.message : 'Customers could not be loaded.',
                        'error',
                        true
                    );
                    releaseResultsHeight();
                } finally {
                    if (sequence === requestSequence) {
                        setPendingState(false);
                    }
                }
            }

            function queueRequest(delay = 240, pendingMessage = 'Updating customers…') {
                window.clearTimeout(debounceTimer);
                debounceTimer = window.setTimeout(() => {
                    if (!hasSearchQuery()) {
                        renderDeferredState();
                        return;
                    }

                    const url = buildUrl({}, true);
                    void requestRows(url, pendingMessage);
                }, delay);
            }

            syncFilterSummary();
            syncDeferredControls();

            toggleFiltersButton.addEventListener('click', () => {
                const expanded = toggleFiltersButton.getAttribute('aria-expanded') === 'true';
                setFiltersOpen(!expanded);
            });

            resultsStatusNode?.addEventListener('click', (event) => {
                const retryButton = event.target.closest('[data-customers-retry]');
                if (!retryButton || !lastRequestedUrl) {
                    return;
                }

                void requestRows(lastRequestedUrl, 'Retrying customers…');
            });

            clearFiltersButton?.addEventListener('click', () => {
                filterFieldKeys().forEach((key) => {
                    const field = form.elements.namedItem(key);
                    if (field) {
                        field.value = cleanValue(defaultFilters[key] ?? 'all');
                    }
                });

                syncFilterSummary();
                if (!hasSearchQuery()) {
                    renderDeferredState('Filters cleared. Enter a search to load customers.');
                    return;
                }

                const url = buildUrl({}, true);
                void requestRows(url, 'Clearing filters…');
            });

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                window.clearTimeout(debounceTimer);
                if (!hasSearchQuery()) {
                    renderDeferredState();
                    return;
                }
                const url = buildUrl({}, true);
                void requestRows(url, 'Searching customers…');
            });

            searchInput?.addEventListener('input', () => {
                syncDeferredControls();
                queueRequest(260, 'Searching customers…');
            });

            searchInput?.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();
                window.clearTimeout(debounceTimer);
                const url = buildUrl({}, true);
                void requestRows(url, 'Searching customers…');
            });

            form.querySelectorAll('[data-customers-live="change"]').forEach((field) => {
                field.addEventListener('change', () => {
                    syncFilterSummary();
                    syncDeferredControls();
                    if (!hasSearchQuery()) {
                        renderDeferredState('Enter a search to load customers.');
                        return;
                    }
                    const url = buildUrl({}, true);
                    void requestRows(url, 'Updating customers…');
                });
            });

            resultsNode.addEventListener('click', (event) => {
                const link = event.target.closest('a[href]');
                if (!link || !resultsNode.contains(link)) {
                    return;
                }

                const href = cleanValue(link.getAttribute('href'));
                if (!href || link.target === '_blank' || link.hasAttribute('download')) {
                    return;
                }

                const url = new URL(href, window.location.origin);
                const normalizedPath = url.pathname.replace(/\/+$/, '');
                if (url.origin !== window.location.origin || normalizedPath !== '/shopify/app/customers/manage') {
                    return;
                }

                event.preventDefault();
                syncFormFromUrl(url);
                void requestRows(url, 'Updating customers…');
            });

            ['mouseenter', 'focusin', 'touchstart'].forEach((eventName) => {
                resultsNode.addEventListener(eventName, (event) => {
                    const target = event.target.closest('[data-customer-prefetch-endpoint]');
                    if (!target || !resultsNode.contains(target)) {
                        return;
                    }

                    void prefetchCustomerDetail(target);
                }, { passive: true });
            });
        })();
    </script>
</x-shopify.customers-layout>
