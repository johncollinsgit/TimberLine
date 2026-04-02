<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-subnav="$pageSubnav ?? []"
    :page-actions="$pageActions"
>
    @php
        $bootstrap = is_array($dashboardBootstrap ?? null) ? $dashboardBootstrap : [];
        $dashboardData = is_array($bootstrap['initialData'] ?? null) ? $bootstrap['initialData'] : [];
        $journey = is_array($merchantJourney ?? null) ? $merchantJourney : [];

        $importSummary = is_array($journey['import_summary'] ?? null) ? $journey['import_summary'] : [];
        $importState = (string) ($importSummary['state'] ?? 'not_started');
        $customerSummary = is_array($journey['customer_summary'] ?? null)
            ? $journey['customer_summary']
            : ['total_profiles' => 0, 'reachable_profiles' => 0, 'customers_with_points' => 0];
        $moduleStates = (array) ($journey['module_states'] ?? []);
        $rewardsState = is_array($moduleStates['rewards'] ?? null) ? $moduleStates['rewards'] : [];
        $rewardsSetupStatus = strtolower(trim((string) ($rewardsState['setup_status'] ?? 'not_started')));

        $emailReadiness = (array) data_get($dashboardData, 'candleCashEngagement.reminderEligibility.emailReadiness', []);
        $emailReady = (bool) ($emailReadiness['canSendLive'] ?? false);
        $emailMissing = (array) ($emailReadiness['missingReasons'] ?? []);

        $isSetupMode = $importState !== 'imported' || $rewardsSetupStatus !== 'live';
        $syncStaleAfterDays = max(1, (int) ($importSummary['stale_after_days'] ?? config('shopify_embedded.sync_stale_after_days', 3)));
        $syncIsStale = (bool) ($importSummary['is_stale'] ?? false);

        $navigationDisplayLabels = is_array($appNavigation['displayLabels'] ?? null) ? $appNavigation['displayLabels'] : [];
        $resolvedRewardsLabel = trim((string) ($rewardsLabel ?? data_get($navigationDisplayLabels, 'rewards_label', 'Rewards')));
        if ($resolvedRewardsLabel === '') {
            $resolvedRewardsLabel = 'Rewards';
        }

        $warnings = [];
        if ($importState === 'attention') {
            $warnings[] = [
                'title' => 'Fix customer sync',
                'detail' => 'The last sync did not complete.',
                'action' => ['label' => 'Retry sync', 'href' => route('shopify.app.integrations', [], false)],
            ];
        }
        if ($syncIsStale) {
            $warnings[] = [
                'title' => 'Refresh customer sync',
                'detail' => 'Customer data has not synced in the last '.$syncStaleAfterDays.' day'.($syncStaleAfterDays === 1 ? '' : 's').'.',
                'action' => ['label' => 'Retry sync', 'href' => route('shopify.app.integrations', [], false)],
            ];
        }
        if ($rewardsSetupStatus !== 'live') {
            $warnings[] = [
                'title' => 'Enable rewards rules',
                'detail' => 'Rewards are not live until rules are active.',
                'action' => ['label' => 'Open rewards settings', 'href' => route('shopify.embedded.rewards.notifications', [], false)],
            ];
        }
        if (! $emailReady) {
            $warnings[] = [
                'title' => 'Verify email sender',
                'detail' => $emailMissing !== []
                    ? 'Reminder emails stay off until sender settings are complete.'
                    : 'Email sender setup still needs review.',
                'action' => ['label' => 'Open email settings', 'href' => route('shopify.app.settings', [], false)],
            ];
        }
        $warnings = array_slice($warnings, 0, 3);

        $setupItems = [
            [
                'title' => 'Sync customers',
                'status' => (string) ($importSummary['label'] ?? 'Not synced'),
                'done' => $importState === 'imported' && ! $syncIsStale,
                'action' => [
                    'label' => match ($importState) {
                        'imported' => $syncIsStale ? 'Retry sync' : 'View sync status',
                        'in_progress' => 'View sync status',
                        'attention' => 'Retry sync',
                        default => 'Sync customers',
                    },
                    'href' => route('shopify.app.integrations', [], false),
                ],
            ],
            [
                'title' => 'Enable rewards rules',
                'status' => $rewardsSetupStatus === 'live' ? 'Live' : 'Needs setup',
                'done' => $rewardsSetupStatus === 'live',
                'action' => ['label' => 'Open rewards settings', 'href' => route('shopify.embedded.rewards.notifications', [], false)],
            ],
            [
                'title' => 'Verify email sender',
                'status' => $emailReady ? 'Ready' : 'Missing setup',
                'done' => $emailReady,
                'action' => ['label' => 'Open email settings', 'href' => route('shopify.app.settings', [], false)],
            ],
            [
                'title' => 'Review live status',
                'status' => $warnings === [] ? 'Good' : 'Needs review',
                'done' => $warnings === [],
                'action' => ['label' => 'Open rewards analytics', 'href' => route('shopify.app.rewards', [], false)],
            ],
        ];

        $kpis = array_slice((array) ($dashboardData['topMetrics'] ?? []), 0, 4);
        if ($kpis === []) {
            $kpis = [
                [
                    'label' => 'Loyalty-attributed revenue',
                    'formattedValue' => '$0',
                    'deltaLabel' => 'No prior period',
                ],
                [
                    'label' => 'Returning customer rate',
                    'formattedValue' => '0%',
                    'deltaLabel' => 'No prior period',
                ],
                [
                    'label' => 'Rewards redeemed',
                    'formattedValue' => '0',
                    'deltaLabel' => 'No prior period',
                ],
                [
                    'label' => 'Customers with points',
                    'formattedValue' => number_format((int) ($customerSummary['customers_with_points'] ?? 0)),
                    'deltaLabel' => 'Current total',
                ],
            ];
        }

        $seriesPoints = (array) data_get($dashboardData, 'chart.series', []);
        $seriesOptions = collect((array) data_get($dashboardData, 'chart.seriesOptions', []));
        $selectedSeries = $seriesOptions->filter(fn (array $item): bool => (bool) ($item['selected'] ?? false));
        if ($selectedSeries->isEmpty()) {
            $selectedSeries = $seriesOptions->take(1);
        }

        $chartLabels = array_map(
            static fn (array $point): string => (string) ($point['label'] ?? ''),
            $seriesPoints
        );

        $chartSeries = $selectedSeries->map(function (array $option) use ($seriesPoints): array {
            $key = (string) ($option['key'] ?? 'metric');

            return [
                'name' => (string) ($option['label'] ?? 'Metric'),
                'data' => array_map(
                    static fn (array $point): float => (float) data_get($point, 'values.'.$key, 0),
                    $seriesPoints
                ),
            ];
        })->values()->all();

        $timeframeOptions = (array) data_get($dashboardData, 'config.timeframeOptions', []);
        $currentTimeframe = (string) data_get($dashboardData, 'query.timeframe', 'last_30_days');
        $currentTimeframeLabel = collect($timeframeOptions)->firstWhere('value', $currentTimeframe)['label'] ?? 'Last 30 days';

        $embeddedContext = \App\Support\Shopify\ShopifyEmbeddedContextQuery::fromRequest(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => \App\Support\Shopify\ShopifyEmbeddedContextQuery::appendToUrl($url, $embeddedContext);

        $recentActivity = [];
        if (filled(data_get($importSummary, 'latest_run.source_label'))) {
            $recentActivity[] = [
                'title' => (string) data_get($importSummary, 'latest_run.source_label'),
                'detail' => (string) data_get($importSummary, 'latest_run.status_label', 'Status unknown'),
                'time' => (string) (data_get($importSummary, 'latest_run.finished_at_display')
                    ?: data_get($importSummary, 'latest_run.started_at_display')
                    ?: 'No timestamp'),
            ];
        }
        $recentActivity[] = [
            'title' => $resolvedRewardsLabel.' rules',
            'detail' => $rewardsSetupStatus === 'live' ? 'Live' : 'Needs setup',
            'time' => 'Current status',
        ];
        $recentActivity[] = [
            'title' => 'Reminder emails',
            'detail' => $emailReady ? 'Enabled' : 'Disabled',
            'time' => 'Current status',
        ];

        $unauthorizedTitle = ($status ?? '') === 'open_from_shopify'
            ? 'Open this app from Shopify Admin'
            : 'We could not verify this Shopify request';
        $unauthorizedDetail = ($status ?? '') === 'open_from_shopify'
            ? 'Open this app from Shopify Admin to load store data.'
            : 'Open the app again from Shopify Admin to load store data.';
    @endphp

    <style>
        .embedded-home {
            display: grid;
            gap: 16px;
        }

        .embedded-home-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.96);
            padding: 16px;
        }

        .embedded-home-card--subdued {
            background: rgba(248, 250, 252, 0.88);
        }

        .embedded-home-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .embedded-home-header h2,
        .embedded-home-chart-head h3,
        .embedded-home-column h3 {
            margin: 0;
            font-size: 1rem;
            color: #0f172a;
        }

        .embedded-home-header p,
        .embedded-home-chart-head p,
        .embedded-home-column p {
            margin: 4px 0 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.6);
        }

        .embedded-home-actions {
            display: inline-flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .embedded-home-button,
        .embedded-home-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            border-radius: 8px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: #fff;
            color: #0f172a;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            padding: 0 12px;
        }

        .embedded-home-button {
            border-color: #0f766e;
            background: rgba(15, 118, 110, 0.12);
            color: #115e59;
        }

        .embedded-home-kpis {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-top: 12px;
            opacity: {{ $isSetupMode ? '0.72' : '1' }};
        }

        .embedded-home-kpi {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
            padding: 12px;
            background: rgba(248, 250, 252, 0.72);
        }

        .embedded-home-kpi-label {
            margin: 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.62);
        }

        .embedded-home-kpi-value {
            margin: 8px 0 0;
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
        }

        .embedded-home-kpi-meta {
            margin: 6px 0 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.52);
        }

        .embedded-home-checklist {
            display: grid;
            gap: 8px;
            margin-top: 12px;
        }

        .embedded-home-checklist-row,
        .embedded-home-list-row {
            display: grid;
            gap: 6px;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
            padding: 10px 12px;
            background: rgba(248, 250, 252, 0.72);
        }

        .embedded-home-checklist-title,
        .embedded-home-list-title {
            margin: 0;
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
        }

        .embedded-home-checklist-status,
        .embedded-home-list-detail,
        .embedded-home-list-time {
            margin: 2px 0 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.58);
        }

        .embedded-home-chart {
            min-height: 280px;
            margin-top: 12px;
            opacity: {{ $isSetupMode ? '0.72' : '1' }};
        }

        .embedded-home-chart-empty {
            min-height: 220px;
            margin-top: 12px;
            border: 1px dashed rgba(15, 23, 42, 0.18);
            border-radius: 12px;
            display: grid;
            place-items: center;
            color: rgba(15, 23, 42, 0.58);
            font-size: 13px;
            background: rgba(248, 250, 252, 0.72);
        }

        .embedded-home-columns {
            display: grid;
            gap: 16px;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        }

        .embedded-home-column-list {
            display: grid;
            gap: 8px;
            margin-top: 12px;
        }

        @media (max-width: 980px) {
            .embedded-home-kpis,
            .embedded-home-columns {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 700px) {
            .embedded-home-kpis,
            .embedded-home-columns {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @if(! $authorized)
        <section class="embedded-home">
            <article class="embedded-home-card embedded-home-card--subdued">
                <h2>{{ $unauthorizedTitle }}</h2>
                <p>{{ $unauthorizedDetail }}</p>
            </article>
        </section>
    @else
        <section class="embedded-home" data-embedded-home>
            <article class="embedded-home-card">
                <div class="embedded-home-header">
                    <div>
                        <h2>{{ $isSetupMode ? 'Setup progress' : 'Performance summary' }}</h2>
                        <p>{{ $isSetupMode ? 'Finish the core setup steps to go live.' : 'Track revenue, engagement, and program health.' }}</p>
                    </div>
                    <div class="embedded-home-actions">
                        <a class="embedded-home-button" href="{{ $embeddedUrl($isSetupMode ? route('shopify.app.start', [], false) : route('shopify.app.rewards', [], false)) }}">
                            {{ $isSetupMode ? 'Complete setup' : 'View rewards analytics' }}
                        </a>
                        <a class="embedded-home-link" href="{{ $embeddedUrl(route('shopify.app.customers.manage', [], false)) }}">Open customers</a>
                    </div>
                </div>

                @if($isSetupMode)
                    <div class="embedded-home-checklist" aria-label="Setup checklist">
                        @foreach(array_slice($setupItems, 0, 4) as $item)
                            <div class="embedded-home-checklist-row">
                                <div>
                                    <p class="embedded-home-checklist-title">{{ $item['title'] }}</p>
                                    <p class="embedded-home-checklist-status">{{ $item['status'] }}</p>
                                </div>
                                <a class="embedded-home-link" href="{{ $embeddedUrl($item['action']['href']) }}">{{ $item['action']['label'] }}</a>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="embedded-home-kpis" aria-label="Home metrics">
                        @foreach($kpis as $metric)
                            <article class="embedded-home-kpi">
                                <p class="embedded-home-kpi-label">{{ (string) ($metric['label'] ?? 'Metric') }}</p>
                                <p class="embedded-home-kpi-value">{{ (string) ($metric['formattedValue'] ?? '0') }}</p>
                                <p class="embedded-home-kpi-meta">{{ (string) ($metric['deltaLabel'] ?? 'No prior period') }}</p>
                            </article>
                        @endforeach
                    </div>
                @endif
            </article>

            <article class="embedded-home-card">
                <div class="embedded-home-chart-head">
                    <div>
                        <h3>Trend</h3>
                        <p>{{ $isSetupMode ? 'Trend data appears after sync and rewards setup.' : 'Timeframe: '.$currentTimeframeLabel.'.' }}</p>
                    </div>
                </div>

                @if($chartSeries !== [])
                    <div id="embedded-home-chart" class="embedded-home-chart" aria-label="Home trend chart"></div>
                    <script id="embedded-home-chart-data" type="application/json">
                        {!! json_encode([
                            'labels' => $chartLabels,
                            'series' => $chartSeries,
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
                    </script>
                @else
                    <div class="embedded-home-chart-empty">Trend data will appear after sync completes.</div>
                @endif
            </article>

            <div class="embedded-home-columns">
                <article class="embedded-home-card embedded-home-column">
                    <h3>Attention needed</h3>
                    <p>High-signal items only.</p>
                    <div class="embedded-home-column-list">
                        @forelse($warnings as $warning)
                            <div class="embedded-home-list-row">
                                <div>
                                    <p class="embedded-home-list-title">{{ $warning['title'] }}</p>
                                    <p class="embedded-home-list-detail">{{ $warning['detail'] }}</p>
                                </div>
                                <a class="embedded-home-link" href="{{ $embeddedUrl($warning['action']['href']) }}">{{ $warning['action']['label'] }}</a>
                            </div>
                        @empty
                            <div class="embedded-home-list-row">
                                <div>
                                    <p class="embedded-home-list-title">No blockers</p>
                                    <p class="embedded-home-list-detail">Setup and sync look healthy.</p>
                                </div>
                            </div>
                        @endforelse
                    </div>
                </article>

                <article class="embedded-home-card embedded-home-column">
                    <h3>Recent activity</h3>
                    <p>Latest sync and program status.</p>
                    <div class="embedded-home-column-list">
                        @foreach(array_slice($recentActivity, 0, 4) as $item)
                            <div class="embedded-home-list-row">
                                <div>
                                    <p class="embedded-home-list-title">{{ $item['title'] }}</p>
                                    <p class="embedded-home-list-detail">{{ $item['detail'] }}</p>
                                </div>
                                <p class="embedded-home-list-time">{{ $item['time'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </article>
            </div>
        </section>

        @if($chartSeries !== [])
            <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
            <script>
                (() => {
                    const node = document.getElementById('embedded-home-chart');
                    const payloadNode = document.getElementById('embedded-home-chart-data');
                    if (!node || !payloadNode || typeof window.ApexCharts === 'undefined') {
                        return;
                    }

                    let payload;
                    try {
                        payload = JSON.parse(payloadNode.textContent || '{}');
                    } catch (error) {
                        payload = { labels: [], series: [] };
                    }

                    const labels = Array.isArray(payload.labels) ? payload.labels : [];
                    const series = Array.isArray(payload.series) ? payload.series : [];

                    const chart = new window.ApexCharts(node, {
                        chart: {
                            type: 'area',
                            height: 280,
                            toolbar: { show: false },
                            zoom: { enabled: false },
                        },
                        stroke: {
                            curve: 'smooth',
                            width: 3,
                        },
                        fill: {
                            type: 'gradient',
                            gradient: {
                                shadeIntensity: 1,
                                opacityFrom: 0.24,
                                opacityTo: 0.04,
                                stops: [0, 95, 100],
                            },
                        },
                        colors: ['#0f766e', '#334155', '#1d4ed8'],
                        series,
                        xaxis: {
                            categories: labels,
                            labels: {
                                style: {
                                    colors: '#64748b',
                                    fontSize: '11px',
                                },
                            },
                            axisBorder: { show: false },
                            axisTicks: { show: false },
                        },
                        yaxis: {
                            labels: {
                                style: {
                                    colors: '#64748b',
                                    fontSize: '11px',
                                },
                            },
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
                    });

                    chart.render();
                })();
            </script>
        @endif
    @endif
</x-shopify-embedded-shell>
