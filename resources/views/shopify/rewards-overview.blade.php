@extends('shopify.rewards-layout')

@section('rewards-content')
    @php
        $analyticsEnabled = (bool) ($analyticsEnabled ?? false);
        $analyticsEndpoint = trim((string) ($analyticsEndpoint ?? ''));
        $analyticsPayload = is_array($analytics ?? null) ? $analytics : [];
        $chartPayload = is_array($analyticsPayload['chart'] ?? null) ? $analyticsPayload['chart'] : [];
        $chartRows = collect($chartPayload['series'] ?? []);
        $chartLabels = $chartRows
            ->map(fn ($row): string => (string) ($row['label'] ?? ''))
            ->filter(fn ($label): bool => $label !== '')
            ->values()
            ->all();
        $featuredSeriesKeys = ['rewards_sales', 'candle_cash_earned', 'candle_cash_redeemed'];
        $chartSeriesOptions = collect($chartPayload['seriesOptions'] ?? []);
        $chartSeries = $chartSeriesOptions
            ->filter(fn ($option): bool => in_array($option['key'] ?? '', $featuredSeriesKeys, true))
            ->map(function ($option) use ($chartRows) {
                $key = (string) ($option['key'] ?? '');
                $values = $chartRows
                    ->map(fn ($row): float => (float) data_get($row, 'values.'.$key, 0))
                    ->all();

                return [
                    'key' => $key,
                    'name' => (string) ($option['label'] ?? 'Metric'),
                    'color' => (string) ($option['color'] ?? '#0f766e'),
                    'selected' => (bool) ($option['selected'] ?? true),
                    'total' => array_sum($values),
                    'data' => $values,
                ];
            })
            ->values()
            ->all();

        if (empty($chartSeries) && $chartSeriesOptions->isNotEmpty() && $chartRows->isNotEmpty()) {
            $option = $chartSeriesOptions->first();
            $key = (string) ($option['key'] ?? '');
            if ($key !== '') {
                $values = $chartRows
                    ->map(fn ($row): float => (float) data_get($row, 'values.'.$key, 0))
                    ->all();
                $chartSeries = [[
                    'key' => $key,
                    'name' => (string) ($option['label'] ?? 'Metric'),
                    'color' => (string) ($option['color'] ?? '#0f766e'),
                    'selected' => true,
                    'total' => array_sum($values),
                    'data' => $values,
                ]];
            }
        }

        $chartEmpty = (bool) ($chartPayload['empty'] ?? true);
        $chartTitle = (string) ($chartPayload['title'] ?? 'Performance trend');
        $chartSubtitle = (string) ($chartPayload['subtitle'] ?? 'Rewards activity across earned credit, redemptions, and the sales they drive.');
        $selectedPeriodMetrics = collect((array) ($analyticsPayload['topMetrics'] ?? []))
            ->filter(fn ($metric): bool => in_array((string) ($metric['key'] ?? ''), [
                'candle_cash_earned',
                'earned_candle_cash_outstanding',
                'time_to_first_redemption',
                'customers_with_unredeemed_earned',
            ], true))
            ->values()
            ->all();
        $balanceLiability = (array) ($analyticsPayload['balanceLiability'] ?? []);
        $liabilityMetrics = [
            [
                'label' => 'Total Active Candle Cash',
                'formattedValue' => (string) data_get($balanceLiability, 'totalCurrentBalance.formattedAmount', '$0.00'),
                'caption' => (bool) data_get($balanceLiability, 'reconciled', false)
                    ? 'Ledger replay matches candle_cash_balances.'
                    : 'Ledger replay does not currently reconcile to candle_cash_balances.',
            ],
            [
                'label' => 'Legacy Growave Candle Cash',
                'formattedValue' => (string) data_get($balanceLiability, 'legacyMigrated.formattedAmount', '$0.00'),
                'caption' => 'Non-expiring migrated Candle Cash that stays in the normal customer balance.',
            ],
            [
                'label' => 'Expiring Program Candle Cash',
                'formattedValue' => (string) data_get($balanceLiability, 'programExpiring.formattedAmount', '$0.00'),
                'caption' => 'New program-earned Candle Cash still governed by the active rewards expiration policy.',
            ],
        ];
        $manualNonExpiringLiability = (float) data_get($balanceLiability, 'manualNonExpiring.amount', 0);
        $attributionSources = (array) data_get($analyticsPayload, 'attribution.sources', []);
        $financialItems = (array) data_get($analyticsPayload, 'financialSummary.items', []);
        $netProfit = (array) data_get($analyticsPayload, 'financialSummary.netProfit', []);
        $hasAnalyticsData = (bool) data_get($analyticsPayload, 'flags.hasAnyData', false);
        $hasAttributionData = $hasAnalyticsData && collect($attributionSources)->contains(fn ($source) => (float) ($source['revenue'] ?? 0) > 0);
        $hasFinancialData = $hasAnalyticsData && count($financialItems) > 0;
        $shouldRenderChart = count($chartLabels) > 0 && count($chartSeries) > 0;
        $chartSeriesJson = json_encode([
            'labels' => $chartLabels,
            'series' => $chartSeries,
            'empty' => $chartEmpty,
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    @endphp

    <style>
        .rewards-shell {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .rewards-tab-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .rewards-tab {
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            padding: 6px 18px;
            background: #fff;
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .rewards-tab.is-active {
            border-color: #059669;
            background: rgba(16, 185, 129, 0.16);
            color: #065f46;
        }

        .rewards-tab-panel {
            display: none;
            gap: 18px;
        }

        .rewards-tab-panel.is-active {
            display: flex;
            flex-direction: column;
        }

        .rewards-metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .rewards-metric-card {
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #fff;
            padding: 16px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.04);
        }

        .rewards-metric-label {
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.55);
        }

        .rewards-metric-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: #0f172a;
        }

        .rewards-metric-caption {
            margin: 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.6);
        }

        .rewards-chart-card {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #fff;
            padding: 20px;
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.05);
            display: grid;
            gap: 12px;
        }

        .rewards-chart-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .rewards-chart-heading h3 {
            margin: 0;
            font-size: 1.4rem;
        }

        .rewards-chart-heading p {
            margin: 4px 0 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.65);
        }

        .rewards-analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }

        .rewards-table-card {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #fff;
            padding: 18px;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.05);
        }

        .rewards-table-card table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .rewards-table-card th,
        .rewards-table-card td {
            padding: 8px 6px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        }

        .rewards-table-card th {
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.08em;
            color: rgba(15, 23, 42, 0.55);
        }

        .rewards-table-card td strong {
            color: #0f172a;
            display: block;
        }

        .rewards-chart-empty {
            font-size: 13px;
            color: rgba(15, 23, 42, 0.6);
        }

        .rewards-explanation-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .rewards-explanation-card {
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #fff;
            padding: 16px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.04);
        }

        .rewards-explanation-card h4 {
            margin: 0 0 8px;
            font-size: 1.1rem;
        }

        .rewards-explanation-card p,
        .rewards-explanation-card ul {
            margin: 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.65);
            line-height: 1.5;
        }

        .rewards-config-hero {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(16, 185, 129, 0.12);
            padding: 18px;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }

        .rewards-config-hero h3 {
            margin: 0;
            font-size: 1.4rem;
        }

        .rewards-config-status {
            border-radius: 999px;
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 700;
            color: #065f46;
            background: rgba(16, 185, 129, 0.24);
        }

        .rewards-config-status.is-locked {
            color: #92400e;
            background: rgba(249, 115, 22, 0.15);
        }

        .rewards-config {
            display: grid;
            gap: 12px;
        }

        .rewards-config-modules {
            display: grid;
            gap: 12px;
        }

        .rewards-module-card {
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #fff;
            padding: 16px;
            display: grid;
            gap: 14px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.04);
        }

        .rewards-module-card strong {
            font-size: 1rem;
            display: block;
            margin-bottom: 4px;
        }

        .rewards-module-card p {
            margin: 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.65);
        }

        .rewards-module-toggle {
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.2);
            background: rgba(248, 250, 252, 0.9);
            color: #0f172a;
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .rewards-module-toggle.is-active {
            border-color: rgba(5, 150, 105, 0.4);
            background: rgba(5, 150, 105, 0.16);
            color: #065f46;
        }

        .rewards-module-controls {
            display: grid;
            gap: 10px;
        }

        .rewards-module-field label {
            font-size: 12px;
            color: rgba(15, 23, 42, 0.7);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .rewards-module-field input {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid rgba(15, 23, 42, 0.18);
            font-size: 13px;
        }

        .rewards-module-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .rewards-module-save {
            border-radius: 10px;
            border: none;
            background: #065f46;
            color: #fff;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .rewards-module-save:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .rewards-module-status {
            font-size: 12px;
            color: rgba(15, 23, 42, 0.65);
        }

        .rewards-config-note {
            margin: 0;
            padding: 12px;
            border-radius: 12px;
            border: 1px dashed rgba(15, 23, 42, 0.18);
            color: rgba(15, 23, 42, 0.7);
            font-size: 13px;
        }

        .rewards-config-error {
            margin: 0;
            font-size: 13px;
            color: #b91c1c;
        }

        @media (max-width: 768px) {
            .rewards-config-hero {
                flex-direction: column;
                align-items: flex-start;
            }

            .rewards-module-actions {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>

    <section class="rewards-shell">
        <div class="rewards-tab-bar" role="tablist" aria-label="Rewards pages">
            <button type="button" class="rewards-tab is-active" data-rewards-tab="overview" aria-selected="true">Overview</button>
            <button type="button" class="rewards-tab" data-rewards-tab="explanation" aria-selected="false">Rewards Explanation</button>
            <button type="button" class="rewards-tab" data-rewards-tab="configuration" aria-selected="false">Configuration</button>
        </div>

        <div class="rewards-tab-panel is-active" data-tab-panel="overview">
            @if(! $analyticsEnabled)
                <p class="rewards-config-note">
                    Analytics are loaded on demand to keep Rewards fast inside Shopify Admin.
                    @if($analyticsEndpoint !== '')
                        <a href="{{ $analyticsEndpoint }}" style="font-weight:700;color:#065f46;text-decoration:none;">Open full analytics</a>.
                    @endif
                </p>
            @endif

            <article class="rewards-chart-card">
                <div class="rewards-chart-heading">
                    <div>
                        <h3>{{ $chartTitle }}</h3>
                        <p>{{ $chartSubtitle }}</p>
                    </div>
                </div>
                @if($shouldRenderChart)
                    <div class="message-analytics-series-picker" data-rewards-series-picker aria-label="Select chart metrics"></div>
                    <div id="rewards-engagement-chart" class="message-analytics-chart" aria-label="Rewards engagement trend"></div>
                @endif
                <p class="rewards-chart-empty" data-rewards-chart-empty>
                    @if(! $analyticsEnabled)
                        Open full analytics to view trends, attribution, and financial overlays.
                    @elseif($hasAnalyticsData)
                        No reward activity has been recorded in this timeframe yet.
                    @else
                        Reward activity will appear here once the system ingests campaign and redemption data.
                    @endif
                </p>
            </article>

            <div class="rewards-metric-grid">
                @foreach($liabilityMetrics as $metric)
                    <article class="rewards-metric-card">
                        <span class="rewards-metric-label">{{ (string) ($metric['label'] ?? 'Metric') }}</span>
                        <span class="rewards-metric-value">{{ (string) ($metric['formattedValue'] ?? '$0') }}</span>
                        <p class="rewards-metric-caption">{{ (string) ($metric['caption'] ?? '') }}</p>
                    </article>
                @endforeach
            </div>

            @if($manualNonExpiringLiability > 0)
                <p class="rewards-chart-empty">Manual non-expiring Candle Cash outside the Growave migration: {{ (string) data_get($balanceLiability, 'manualNonExpiring.formattedAmount', '$0.00') }}</p>
            @endif

            <div class="rewards-metric-grid">
                @if(count($selectedPeriodMetrics) === 0)
                    <article class="rewards-metric-card">
                        <span class="rewards-metric-label">Data status</span>
                        <p class="rewards-metric-caption">Awaiting selected-period program reward activity.</p>
                    </article>
                @else
                    @foreach($selectedPeriodMetrics as $metric)
                        <article class="rewards-metric-card">
                            <span class="rewards-metric-label">{{ (string) ($metric['label'] ?? 'Metric') }}</span>
                            <span class="rewards-metric-value">{{ (string) ($metric['formattedValue'] ?? '$0') }}</span>
                            <small class="rewards-metric-caption">{{ (string) ($metric['deltaLabel'] ?? '') }}</small>
                            <p class="rewards-metric-caption">{{ (string) ($metric['caption'] ?? '') }}</p>
                        </article>
                    @endforeach
                @endif
            </div>

            <div class="rewards-analytics-grid">
                <article class="rewards-table-card">
                    <div class="rewards-chart-heading">
                        <div>
                            <h3>Attribution</h3>
                            <p>Revenue influence associated with reward activity.</p>
                        </div>
                    </div>
                    @if($hasAttributionData)
                        <table aria-label="Reward attribution table">
                            <thead>
                                <tr>
                                    <th>Source</th>
                                    <th>Revenue</th>
                                    <th>Orders</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($attributionSources as $source)
                                    @php
                                        $revenue = (float) ($source['revenue'] ?? 0);
                                        $formatted = (string) ($source['formattedRevenue'] ?? '$0');
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong>{{ (string) ($source['label'] ?? 'Source') }}</strong>
                                            <small>{{ (string) ($source['description'] ?? '') }}</small>
                                        </td>
                                        <td>{{ $formatted }}</td>
                                        <td>{{ number_format((int) ($source['orders'] ?? 0)) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="rewards-chart-empty">
                            @if(! $analyticsEnabled)
                                Open full analytics to view attribution rows.
                            @else
                                Attribution rows are still warming up. This table will populate once reward-driven revenue and orders arrive.
                            @endif
                        </p>
                    @endif
                </article>

                <article class="rewards-table-card">
                    <div class="rewards-chart-heading">
                        <div>
                            <h3>Financial summary</h3>
                            <p>Gross revenue touched, reward cost absorbed, and retained incremental value.</p>
                        </div>
                    </div>
                    @if($hasFinancialData)
                        <table aria-label="Financial summary table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($financialItems as $item)
                                    <tr>
                                        <td>
                                            <strong>{{ (string) ($item['label'] ?? 'Item') }}</strong>
                                            <small>{{ (string) ($item['detail'] ?? '') }}</small>
                                        </td>
                                        <td>{{ (string) ($item['formattedValue'] ?? '$0') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @if($netProfit)
                            <div class="rewards-module-actions" style="margin-top:12px;">
                                <span>{{ (string) ($netProfit['label'] ?? 'Net profit created') }}</span>
                                <strong>{{ (string) ($netProfit['formattedValue'] ?? '$0') }}</strong>
                                <small>{{ (string) ($netProfit['detail'] ?? 'Confidence noted from profit traceability.') }}</small>
                            </div>
                        @endif
                    @else
                        <p class="rewards-chart-empty">
                            @if(! $analyticsEnabled)
                                Open full analytics to view the financial summary.
                            @else
                                Financial overlays appear once reward-attributed sales and redemption costs are available.
                            @endif
                        </p>
                    @endif
                </article>
            </div>
        </div>

        <div class="rewards-tab-panel" data-tab-panel="explanation" hidden>
            <div class="rewards-explanation-grid">
                @foreach([
                    [
                        'title' => 'What rewards are',
                        'copy' => 'Digital credit customers earn for completing desired actions, stored per customer and ready for redemption in future orders.',
                        'items' => [
                            'Connected to Candle Cash so ledger state stays accurate across inbox + storefront.',
                            'Program terminology adapts to your tenant labels and branding.',
                        ],
                    ],
                    [
                        'title' => 'How customers earn',
                        'copy' => 'Configured tasks and automations issue reward credit instantly when criteria are met.',
                        'items' => [
                            'Signups, Google reviews, appointments, and milestone orders trigger earn events.',
                            'Earned credit appears on customer profiles and can feed remarketing automations.',
                        ],
                    ],
                    [
                        'title' => 'How credit is redeemed',
                        'copy' => 'Customers convert earned credit into discounts or offers at checkout.',
                        'items' => [
                            'Redemptions reduce reward liability while still contributing to attributable revenue.',
                            'Redeem rules sync to storefront coupon/redemption pages automatically.',
                        ],
                    ],
                    [
                        'title' => 'Attribution & retention',
                        'copy' => 'The analytics above tie earned credit, redemptions, and orders together so you can see the loop complete.',
                        'items' => [
                            'Gross revenue touched shows all orders connected to reward-led conversions.',
                            'Incremental retained revenue subtracts reward cost so you see true lift.',
                        ],
                    ],
                ] as $card)
                    <article class="rewards-explanation-card">
                        <h4>{{ $card['title'] }}</h4>
                        <p>{{ $card['copy'] }}</p>
                        <ul>
                            @foreach($card['items'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>
        </div>

        <div class="rewards-tab-panel" data-tab-panel="configuration" hidden>
            <article class="rewards-config-hero">
                <div>
                    <h3>Rewards configured status</h3>
                    <p>
                        @if($rewardsEditorAvailable ?? false)
                            @if($rewardsEditorEditable ?? false)
                                Earn and redeem modules are editable in this tab.
                            @else
                                Plan access restricts edits; configuration stays read-only for this tenant.
                            @endif
                        @else
                            Rewards configuration is temporarily unavailable for this store.
                        @endif
                    </p>
                </div>
                <span class="rewards-config-status{{ ($rewardsEditorEditable ?? false) ? '' : ' is-locked' }}" data-config-access>
                    {{ ($rewardsEditorEditable ?? false) ? 'Editable' : 'Read only' }}
                </span>
            </article>

            <div class="rewards-config" data-rewards-config>
                <p class="rewards-config-note" data-config-status>Loading reward modules…</p>
                <div
                    class="rewards-config-modules"
                    data-config-modules
                    data-endpoint="{{ $dataEndpoint }}"
                    data-earn-template="{{ $earnUpdateEndpointTemplate }}"
                    data-redeem-template="{{ $redeemUpdateEndpointTemplate }}"
                ></div>
                <p class="rewards-config-error" data-config-error></p>
            </div>
        </div>
    </section>

    @if($shouldRenderChart)
        <script id="rewards-engagement-chart-data" type="application/json">
            {!! $chartSeriesJson !!}
        </script>
    @endif
    <script>
        (() => {
            const tabs = document.querySelectorAll('[data-rewards-tab]');
            const panels = document.querySelectorAll('[data-tab-panel]');

            if (!tabs.length) {
                return;
            }

            const activate = (target) => {
                tabs.forEach((tab) => {
                    const matches = tab.getAttribute('data-rewards-tab') === target;
                    tab.setAttribute('aria-selected', matches ? 'true' : 'false');
                    tab.classList.toggle('is-active', matches);
                });
                panels.forEach((panel) => {
                    const matches = panel.getAttribute('data-tab-panel') === target;
                    panel.hidden = !matches;
                    panel.classList.toggle('is-active', matches);
                });
            };

            tabs.forEach((tab) => {
                tab.addEventListener('click', () => {
                    activate(tab.getAttribute('data-rewards-tab'));
                });
            });
        })();

        @if($shouldRenderChart)
            (() => {
                const apexChartsSrc = 'https://cdn.jsdelivr.net/npm/apexcharts';
                const globalLoaderKey = '__forestryApexChartsLoader';

                if (window.ApexCharts || window[globalLoaderKey]) {
                    return;
                }

                window[globalLoaderKey] = new Promise((resolve, reject) => {
                    const existing = document.querySelector('script[data-apexcharts-loader="1"]');
                    if (existing instanceof HTMLScriptElement) {
                        existing.addEventListener('load', () => resolve(window.ApexCharts), { once: true });
                        existing.addEventListener('error', () => reject(new Error('ApexCharts failed to load.')), { once: true });
                        return;
                    }

                    const script = document.createElement('script');
                    script.src = apexChartsSrc;
                    script.async = true;
                    script.dataset.apexchartsLoader = '1';
                    script.addEventListener('load', () => resolve(window.ApexCharts), { once: true });
                    script.addEventListener('error', () => reject(new Error('ApexCharts failed to load.')), { once: true });
                    document.head.appendChild(script);
                });
            })();

            (() => {
                const chartNode = document.getElementById('rewards-engagement-chart');
                const dataNode = document.getElementById('rewards-engagement-chart-data');
                const picker = document.querySelector('[data-rewards-series-picker]');
                const emptyNode = document.querySelector('[data-rewards-chart-empty]');
                const apexChartsLoader = window.__forestryApexChartsLoader;

                if (!chartNode || !dataNode || !picker || !emptyNode) {
                    return;
                }

            const chartData = JSON.parse(dataNode.textContent || '{}');
            const labels = Array.isArray(chartData.labels) ? chartData.labels : [];
            const seriesConfig = Array.isArray(chartData.series) ? chartData.series : [];
            let activeKeys = seriesConfig
                .filter((config) => Boolean(config.selected))
                .map((config) => config.key);

            if (!activeKeys.length && seriesConfig.length) {
                activeKeys = [seriesConfig[0].key];
            }

            let chart = null;

            const renderPicker = () => {
                picker.innerHTML = seriesConfig
                    .map((config) => {
                        const isActive = activeKeys.includes(config.key);
                        return `
                            <button type="button" class="message-analytics-series-toggle${isActive ? ' is-active' : ''}" data-series-key="${config.key}" aria-pressed="${isActive}">
                                <span class="message-analytics-series-swatch" style="background-color: ${config.color}"></span>
                                <span class="message-analytics-series-copy">
                                    <strong>${config.name}</strong>
                                    <span>$${config.total.toFixed(2)}</span>
                                </span>
                            </button>
                        `;
                    })
                    .join('');
            };

            const getActiveSeries = (keys) => {
                return seriesConfig
                    .filter((config) => keys.includes(config.key))
                    .map((config) => ({
                        name: config.name,
                        data: Array.isArray(config.data) ? config.data : [],
                        color: config.color,
                        key: config.key,
                    }));
            };

            const renderChart = (keys) => {
                if (typeof window.ApexCharts === 'undefined') {
                    return;
                }

                const activeSeries = getActiveSeries(keys);

                if (chart) {
                    chart.destroy();
                }

                if (!labels.length || !activeSeries.length) {
                    chartNode.style.display = 'none';
                    emptyNode.classList.add('is-visible');
                    return;
                }

                emptyNode.classList.toggle('is-visible', chartData.empty || activeSeries.length === 0);
                chartNode.style.display = '';

                chart = new ApexCharts(chartNode, {
                    chart: {
                        type: 'line',
                        height: 320,
                        toolbar: { show: false },
                        zoom: { enabled: false },
                    },
                    stroke: { curve: 'smooth', width: 3 },
                    series: activeSeries,
                    colors: activeSeries.map((series) => series.color),
                    xaxis: {
                        categories: labels,
                        labels: { style: { colors: '#64748b', fontSize: '11px' } },
                        axisBorder: { show: false },
                        axisTicks: { show: false },
                    },
                    yaxis: {
                        labels: { style: { colors: '#64748b', fontSize: '11px' } },
                        forceNiceScale: true,
                    },
                    grid: {
                        borderColor: 'rgba(148, 163, 184, 0.25)',
                        strokeDashArray: 4,
                    },
                    legend: {
                        position: 'top',
                        horizontalAlign: 'left',
                        fontSize: '12px',
                    },
                    dataLabels: { enabled: false },
                    tooltip: {
                        shared: true,
                        intersect: false,
                        y: {
                            formatter: (value) => `$${Number(value).toFixed(2)}`,
                        },
                    },
                });

                chart.render();
            };

            picker.addEventListener('click', (event) => {
                const button = event.target.closest('[data-series-key]');
                if (!button) {
                    return;
                }
                const key = button.getAttribute('data-series-key');
                if (activeKeys.includes(key)) {
                    if (activeKeys.length === 1) {
                        return;
                    }
                    activeKeys = activeKeys.filter((item) => item !== key);
                } else {
                    activeKeys = [...activeKeys, key];
                }
                renderPicker();
                renderChart(activeKeys);
            });

            const loadChart = () => {
                renderPicker();
                renderChart(activeKeys);
            };

            if (typeof window.ApexCharts !== 'undefined') {
                loadChart();
                return;
            }

            emptyNode.textContent = 'Loading rewards trend...';
            emptyNode.classList.add('is-visible');
            chartNode.style.display = 'none';

            Promise.resolve(apexChartsLoader)
                .then(() => {
                    loadChart();
                })
                .catch(() => {
                    emptyNode.textContent = 'Rewards trend is temporarily unavailable.';
                    emptyNode.classList.add('is-visible');
                    chartNode.style.display = 'none';
                });
        })();
        @endif

        (() => {
            const configRoot = document.querySelector('[data-rewards-config]');
            if (!configRoot) {
                return;
            }

            const modulesGrid = configRoot.querySelector('[data-config-modules]');
            const statusMessage = configRoot.querySelector('[data-config-status]');
            const errorField = configRoot.querySelector('[data-config-error]');
            const statusBadge = document.querySelector('[data-config-access]');
            const editable = {{ $rewardsEditorEditable ? 'true' : 'false' }};
            const available = {{ $rewardsEditorAvailable ? 'true' : 'false' }};
            const endpoint = modulesGrid?.dataset.endpoint ?? '';
            const earnTemplate = modulesGrid?.dataset.earnTemplate ?? '';
            const redeemTemplate = modulesGrid?.dataset.redeemTemplate ?? '';

            if (!modulesGrid || !endpoint) {
                return;
            }

            if (!available) {
                modulesGrid.innerHTML = '<p class="rewards-config-note">Configuration is not exposed for this workspace.</p>';
                statusMessage.textContent = 'Configuration is not available right now.';
                if (statusBadge) {
                    statusBadge.textContent = 'Unavailable';
                    statusBadge.classList.add('is-locked');
                }
                return;
            }

            statusMessage.textContent = editable
                ? 'Modules can be adjusted directly in this dashboard.'
                : 'Plan access restricts edits; configuration remains read only.';
            if (statusBadge) {
                statusBadge.textContent = editable ? 'Editable' : 'Read only';
                statusBadge.classList.toggle('is-locked', !editable);
            }

            const state = { earn: [], redeem: [] };

            function resolveEmbeddedAuthHeaders() {
                const resolver = window.ForestryEmbeddedApp?.resolveEmbeddedAuthHeaders;
                if (typeof resolver !== 'function') {
                    return Promise.reject(new Error('Shopify Admin verification is unavailable.'));
                }
                return resolver();
            }

            function fetchJson(url, options = {}) {
                return resolveEmbeddedAuthHeaders().then((headers) =>
                    fetch(url, {
                        credentials: 'same-origin',
                        ...options,
                        headers: {
                            ...headers,
                            ...(options.headers || {}),
                        },
                    }).then((response) =>
                        response.json().then((payload) => {
                            if (!response.ok) {
                                const error = new Error(payload?.message || 'Request failed.');
                                error.payload = payload;
                                throw error;
                            }
                            return payload;
                        })
                    )
                );
            }

            function moduleMarkup(kind, module) {
                const toggleLabel = module.enabled ? 'Enabled' : 'Disabled';

                return `
                    <article class="rewards-module-card" data-module-kind="${kind}" data-module-id="${module.id}">
                        <div class="rewards-module-actions" style="justify-content:space-between;">
                            <div>
                                <strong>${module.title}</strong>
                                <p>${module.description ?? ''}</p>
                            </div>
                            <button
                                type="button"
                                class="rewards-module-toggle${module.enabled ? ' is-active' : ''}"
                                data-action="toggle"
                                data-module-kind="${kind}"
                                data-module-id="${module.id}"
                                aria-pressed="${module.enabled}"
                            >
                                ${toggleLabel}
                            </button>
                        </div>
                        <div class="rewards-module-controls">
                            <div class="rewards-module-field">
                                <label>
                                    Reward amount
                                    <input type="number" min="0" step="0.01" value="${module.value ?? 0}" data-module-input data-module-property="value" ${editable ? '' : 'disabled'} />
                                </label>
                            </div>
                            ${kind === 'redeem' ? `
                                <div class="rewards-module-field">
                                    <label>
                                        Reward display
                                        <input type="text" value="${module.rewardValue ?? ''}" data-module-input data-module-property="rewardValue" ${editable ? '' : 'disabled'} />
                                    </label>
                                </div>
                            ` : ''}
                        </div>
                        <div class="rewards-module-actions" style="margin-top:6px;">
                            <button
                                type="button"
                                class="rewards-module-save"
                                data-action="save"
                                data-module-kind="${kind}"
                                data-module-id="${module.id}"
                                ${editable ? '' : 'disabled'}
                            >Save</button>
                            <span class="rewards-module-status" data-module-status>${module.statusMessage || ''}</span>
                        </div>
                    </article>
                `;
            }

            function renderModules() {
                const sections = [];
                if (state.earn.length) {
                    sections.push(`
                        <div>
                            <h4>Earn modules</h4>
                            <div class="rewards-config-modules">
                                ${state.earn.map((item) => moduleMarkup('earn', item)).join('')}
                            </div>
                        </div>
                    `);
                }
                if (state.redeem.length) {
                    sections.push(`
                        <div>
                            <h4>Redeem modules</h4>
                            <div class="rewards-config-modules">
                                ${state.redeem.map((item) => moduleMarkup('redeem', item)).join('')}
                            </div>
                        </div>
                    `);
                }
                modulesGrid.innerHTML = sections.join('') || '<p class="rewards-config-note">No modules are configured yet.</p>';
            }

            function normalizeValue(value) {
                const parsed = Number(value);
                return Number.isNaN(parsed) ? 0 : parsed;
            }

            function loadConfig() {
                fetchJson(endpoint)
                    .then((response) => {
                        const payload = response.data || {};
                        const earnItems = Array.isArray(payload.earn?.items) ? payload.earn.items : [];
                        const redeemItems = Array.isArray(payload.redeem?.items) ? payload.redeem.items : [];
                        state.earn = earnItems.map((item) => ({
                            id: item.id,
                            title: item.title,
                            description: item.description,
                            enabled: !!item.enabled,
                            value: normalizeValue(item.candle_cash_value ?? item.reward_amount ?? 0),
                            sortOrder: item.sort_order ?? 0,
                            statusMessage: '',
                        }));
                        state.redeem = redeemItems.map((item) => ({
                            id: item.id,
                            title: item.title,
                            description: item.description,
                            enabled: !!item.enabled,
                            value: normalizeValue(item.candle_cash_cost ?? 0),
                            rewardValue: item.reward_value ?? '',
                            statusMessage: '',
                        }));
                        renderModules();
                        errorField.textContent = '';
                    })
                    .catch((error) => {
                        errorField.textContent = error.message || 'Unable to load configuration data right now.';
                        modulesGrid.innerHTML = '<p class="rewards-config-note">Configuration data cannot be loaded at the moment.</p>';
                    });
            }

            function saveModule(kind, id) {
                const module = state[kind]?.find((item) => item.id === id);
                if (!module) {
                    return;
                }
                const template = kind === 'earn' ? earnTemplate : redeemTemplate;
                const placeholder = kind === 'earn' ? '__TASK__' : '__REWARD__';
                const url = template.replace(placeholder, encodeURIComponent(module.id));
                const payload = kind === 'earn'
                    ? {
                        candle_cash_value: module.value,
                        enabled: module.enabled,
                        title: module.title,
                        description: module.description,
                        sort_order: module.sortOrder,
                    }
                    : {
                        candle_cash_cost: module.value,
                        reward_value: module.rewardValue,
                        enabled: module.enabled,
                        title: module.title,
                        description: module.description,
                    };

                module.statusMessage = 'Saving…';
                renderModules();

                fetchJson(url, {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                })
                    .then((response) => {
                        const rule = response.rule || {};
                        const updated = {
                            ...module,
                            value: normalizeValue(rule.candle_cash_cost ?? rule.candle_cash_value ?? rule.reward_amount ?? module.value),
                            enabled: !!rule.enabled,
                            statusMessage: 'Saved',
                        };
                        if (kind === 'redeem') {
                            updated.rewardValue = rule.reward_value ?? module.rewardValue;
                        }
                        state[kind] = state[kind].map((item) => (item.id === id ? updated : item));
                        renderModules();
                    })
                    .catch((error) => {
                        module.statusMessage = error.payload?.message || error.message || 'Save failed';
                        renderModules();
                    });
            }

            modulesGrid.addEventListener('click', (event) => {
                if (!editable) {
                    return;
                }
                const toggle = event.target.closest('[data-action="toggle"]');
                if (toggle) {
                    const kind = toggle.dataset.moduleKind;
                    const id = Number(toggle.dataset.moduleId);
                    const module = state[kind]?.find((item) => item.id === id);
                    if (!module) {
                        return;
                    }
                    module.enabled = !module.enabled;
                    module.statusMessage = module.enabled ? 'Enabled locally' : 'Disabled locally';
                    renderModules();
                }

                const save = event.target.closest('[data-action="save"]');
                if (save) {
                    const kind = save.dataset.moduleKind;
                    const id = Number(save.dataset.moduleId);
                    saveModule(kind, id);
                }
            });

            modulesGrid.addEventListener('input', (event) => {
                if (!editable) {
                    return;
                }
                const input = event.target.closest('[data-module-input]');
                if (!input) {
                    return;
                }
                const card = input.closest('[data-module-id]');
                if (!card) {
                    return;
                }
                const kind = card.dataset.moduleKind;
                const id = Number(card.dataset.moduleId);
                const module = state[kind]?.find((item) => item.id === id);
                if (!module) {
                    return;
                }
                const property = input.dataset.moduleProperty;
                if (property === 'value') {
                    module.value = normalizeValue(input.value);
                } else if (property === 'rewardValue') {
                    module.rewardValue = input.value;
                }
                module.statusMessage = 'Unsaved changes';
                renderModules();
            });

            loadConfig();
        })();
    </script>
@endsection
