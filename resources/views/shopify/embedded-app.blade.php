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
        $showChartControls = ! $isSetupMode;

        $latestSyncAt = null;
        $latestSyncAtRaw = (string) (data_get($importSummary, 'latest_run.finished_at')
            ?: data_get($importSummary, 'latest_run.started_at')
            ?: '');
        if ($latestSyncAtRaw !== '') {
            try {
                $latestSyncAt = \Carbon\CarbonImmutable::parse($latestSyncAtRaw);
            } catch (\Throwable) {
                $latestSyncAt = null;
            }
        }
        $syncIsStale = $importState === 'imported'
            && $latestSyncAt !== null
            && $latestSyncAt->lt(now()->subDays(3));

        $resolvedRewardsLabel = trim((string) ($rewardsLabel ?? data_get($displayLabels ?? [], 'rewards_label', 'Rewards')));
        if ($resolvedRewardsLabel === '') {
            $resolvedRewardsLabel = 'Rewards';
        }

        $warnings = [];
        if ($importState === 'attention') {
            $warnings[] = [
                'title' => 'Fix customer sync',
                'detail' => 'The latest sync did not complete successfully.',
                'action' => ['label' => 'Open sync settings', 'href' => route('shopify.app.integrations', [], false)],
            ];
        }
        if ($syncIsStale) {
            $warnings[] = [
                'title' => 'Refresh customer sync',
                'detail' => 'Customer data has not synced in the last 3 days.',
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
                    ? 'Reminder emails are off until sender settings are complete.'
                    : 'Email sender setup still needs review.',
                'action' => ['label' => 'Open email settings', 'href' => route('shopify.app.settings', [], false)],
            ];
        }
        $warnings = array_slice($warnings, 0, 3);

        $setupItems = [
            [
                'title' => 'Sync customers',
                'status' => (string) ($importSummary['label'] ?? 'Not started'),
                'done' => $importState === 'imported',
                'action' => [
                    'label' => match ($importState) {
                        'imported' => 'Review sync',
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
        $setupItems = array_slice($setupItems, 0, 5);

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

        $embeddedContext = \App\Support\Shopify\ShopifyEmbeddedContextQuery::fromRequest(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => \App\Support\Shopify\ShopifyEmbeddedContextQuery::appendToUrl($url, $embeddedContext);

        $contextFields = collect($embeddedContext)
            ->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')
            ->all();

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
        $recentActivity = array_slice($recentActivity, 0, 4);

        $homePrimaryAction = $isSetupMode
            ? ['label' => 'Complete setup', 'href' => route('shopify.app.start', [], false)]
            : ['label' => 'View rewards analytics', 'href' => route('shopify.app.rewards', [], false)];
        $chartSubtitle = $isSetupMode
            ? 'Trend data appears after sync and rewards setup.'
            : (string) data_get($dashboardData, 'chart.subtitle', 'Track revenue and rewards activity over time.');
    @endphp

    <style>
        .embedded-home {
            display: grid;
            gap: 16px;
        }

        .embedded-home-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 12px;
            background: #fff;
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

        .embedded-home-header h2 {
            margin: 0;
            font-size: 1rem;
            color: #0f172a;
        }

        .embedded-home-header p {
            margin: 4px 0 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.6);
        }

        .embedded-home-actions {
            display: inline-flex;
            align-items: center;
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
        }

        .embedded-home-kpi {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 10px;
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

        .embedded-home-checklist-row {
            display: grid;
            gap: 6px;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 10px;
            padding: 10px 12px;
            background: rgba(248, 250, 252, 0.72);
        }

        .embedded-home-checklist-title {
            margin: 0;
            font-size: 13px;
            color: #0f172a;
            font-weight: 600;
        }

        .embedded-home-checklist-status {
            margin: 2px 0 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.58);
        }

        .embedded-home-chart-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .embedded-home-chart-head h3 {
            margin: 0;
            font-size: 0.95rem;
            color: #0f172a;
        }

        .embedded-home-chart-head p {
            margin: 4px 0 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.58);
        }

        .embedded-home-chart-filter {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .embedded-home-chart-filter select {
            min-height: 34px;
            border-radius: 8px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: #fff;
            color: #0f172a;
            padding: 6px 8px;
            font-size: 12px;
        }

        .embedded-home-chart-note {
            font-size: 12px;
            color: rgba(15, 23, 42, 0.58);
        }

        .embedded-home-chart {
            min-height: 300px;
        }

        .embedded-home-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 16px;
        }

        .embedded-home-queue,
        .embedded-home-activity {
            display: grid;
            gap: 8px;
            margin-top: 10px;
        }

        .embedded-home-queue-item,
        .embedded-home-activity-item {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 10px;
            background: rgba(248, 250, 252, 0.72);
            padding: 10px 12px;
        }

        .embedded-home-queue-item h4,
        .embedded-home-activity-item h4 {
            margin: 0;
            font-size: 13px;
            color: #0f172a;
        }

        .embedded-home-queue-item p,
        .embedded-home-activity-item p {
            margin: 4px 0 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.6);
        }

        .embedded-home-empty-note {
            margin: 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.6);
            padding: 8px 0;
        }

        .embedded-home-status {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 10px;
            padding: 10px 12px;
            background: rgba(248, 250, 252, 0.7);
            margin-top: 12px;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.66);
        }

        @media (max-width: 980px) {
            .embedded-home-kpis,
            .embedded-home-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 700px) {
            .embedded-home-kpis,
            .embedded-home-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <section class="embedded-home" data-embedded-home>
        <article class="embedded-home-card">
            <div class="embedded-home-header">
                <div>
                    <h2>Home</h2>
                    <p>Revenue and setup at a glance.</p>
                </div>
                <div class="embedded-home-actions">
                    <a class="embedded-home-button" href="{{ $embeddedUrl($homePrimaryAction['href']) }}">{{ $homePrimaryAction['label'] }}</a>
                    <a class="embedded-home-link" href="{{ $embeddedUrl(route('shopify.app.customers.manage', [], false)) }}">Open customers</a>
                </div>
            </div>

            @if($isSetupMode)
                <div class="embedded-home-checklist" aria-label="Setup checklist">
                    @foreach($setupItems as $item)
                        <div class="embedded-home-checklist-row">
                            <div>
                                <p class="embedded-home-checklist-title">{{ $item['title'] }}</p>
                                <p class="embedded-home-checklist-status">{{ $item['status'] }}</p>
                            </div>
                            <a class="embedded-home-link" href="{{ $embeddedUrl((string) ($item['action']['href'] ?? route('shopify.app.start', [], false))) }}">{{ (string) ($item['action']['label'] ?? 'Open') }}</a>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="embedded-home-kpis" aria-label="Key metrics">
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

        <article class="embedded-home-card @if($isSetupMode) embedded-home-card--subdued @endif">
            <div class="embedded-home-chart-head">
                <div>
                    <h3>Revenue and engagement trend</h3>
                    <p>{{ $chartSubtitle }}</p>
                </div>
                @if($showChartControls)
                    <form method="GET" action="{{ request()->url() }}" class="embedded-home-chart-filter">
                        @foreach($contextFields as $key => $value)
                            <input type="hidden" name="{{ $key }}" value="{{ (string) $value }}" />
                        @endforeach
                        <input type="hidden" name="comparison" value="{{ (string) data_get($dashboardData, 'query.comparison', 'previous_period') }}" />
                        <select name="timeframe" aria-label="Date range">
                            @foreach($timeframeOptions as $option)
                                @php($value = (string) ($option['value'] ?? ''))
                                @php($label = (string) ($option['label'] ?? $value))
                                <option value="{{ $value }}" @selected($currentTimeframe === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="embedded-home-link">Update range</button>
                    </form>
                @else
                    <span class="embedded-home-chart-note">Finish setup to start trend tracking.</span>
                @endif
            </div>
            <div id="embedded-home-chart" class="embedded-home-chart" aria-label="Home trend chart"></div>
            <script id="embedded-home-chart-data" type="application/json">
                {!! json_encode([
                    'labels' => $chartLabels,
                    'series' => $chartSeries,
                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
            </script>
        </article>

        <div class="embedded-home-grid">
            <article class="embedded-home-card">
                <h3>Attention needed</h3>
                @if($warnings === [])
                    <p class="embedded-home-empty-note">No issues right now.</p>
                @else
                    <div class="embedded-home-queue">
                        @foreach($warnings as $warning)
                            <div class="embedded-home-queue-item">
                                <h4>{{ $warning['title'] }}</h4>
                                <p>{{ $warning['detail'] }}</p>
                                <a class="embedded-home-link" href="{{ $embeddedUrl((string) data_get($warning, 'action.href', route('shopify.app.settings', [], false))) }}">{{ (string) data_get($warning, 'action.label', 'Open') }}</a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </article>

            <article class="embedded-home-card">
                <h3>Recent activity</h3>
                <div class="embedded-home-activity">
                    @foreach($recentActivity as $activity)
                        <div class="embedded-home-activity-item">
                            <h4>{{ $activity['title'] }}</h4>
                            <p>{{ $activity['detail'] }} · {{ $activity['time'] }}</p>
                        </div>
                    @endforeach
                </div>
            </article>
        </div>

        @if(! $authorized)
            <div class="embedded-home-status">
                Open this app from Shopify Admin to load store data.
            </div>
        @endif
    </section>

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
                    type: 'line',
                    height: 300,
                    toolbar: { show: false },
                    zoom: { enabled: false },
                },
                stroke: {
                    curve: 'smooth',
                    width: 3,
                },
                colors: ['#0f766e', '#334155', '#1d4ed8'],
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
                tooltip: {
                    theme: 'light',
                },
                dataLabels: { enabled: false },
                series,
            });

            chart.render();
        })();
    </script>
</x-shopify-embedded-shell>
