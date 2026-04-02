@extends('shopify.rewards-layout')

@section('rewards-content')
    @php
        $analytics = is_array($analytics ?? null) ? $analytics : [];
        $seriesPoints = (array) data_get($analytics, 'chart.series', []);
        $seriesOptions = collect((array) data_get($analytics, 'chart.seriesOptions', []));
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

        $topMetrics = array_slice((array) ($analytics['topMetrics'] ?? []), 0, 4);
        $attributionSources = array_slice((array) data_get($analytics, 'attribution.sources', []), 0, 5);
        $financialItems = array_slice((array) data_get($analytics, 'financialSummary.items', []), 0, 4);
        $hasAnalyticsData = (bool) data_get($analytics, 'flags.hasAnyData', false);
        $embeddedContext = \App\Support\Shopify\ShopifyEmbeddedContextQuery::fromRequest(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => \App\Support\Shopify\ShopifyEmbeddedContextQuery::appendToUrl($url, $embeddedContext);
    @endphp

    <style>
        .rewards-overview-stack {
            display: grid;
            gap: 16px;
        }

        .rewards-overview-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 12px;
            background: #fff;
            padding: 16px;
        }

        .rewards-overview-card h3 {
            margin: 0;
            font-size: 1rem;
            color: #0f172a;
        }

        .rewards-overview-card p {
            margin: 6px 0 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.6);
        }

        .rewards-overview-metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-top: 12px;
        }

        .rewards-overview-metric {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 10px;
            padding: 12px;
            background: rgba(248, 250, 252, 0.72);
        }

        .rewards-overview-metric h4 {
            margin: 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.62);
            font-weight: 500;
        }

        .rewards-overview-metric p {
            margin: 8px 0 0;
            font-size: 1.05rem;
            color: #0f172a;
            font-weight: 700;
        }

        .rewards-overview-metric small {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.52);
        }

        .rewards-overview-chart {
            min-height: 300px;
            margin-top: 12px;
        }

        .rewards-overview-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 16px;
        }

        .rewards-overview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .rewards-overview-table th,
        .rewards-overview-table td {
            text-align: left;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            padding: 10px 8px;
            font-size: 13px;
        }

        .rewards-overview-table th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: rgba(15, 23, 42, 0.56);
        }

        .rewards-overview-empty {
            border: 1px dashed rgba(15, 23, 42, 0.2);
            border-radius: 10px;
            background: rgba(248, 250, 252, 0.7);
            padding: 16px;
            margin-top: 10px;
            display: grid;
            gap: 8px;
        }

        .rewards-overview-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
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

        @media (max-width: 980px) {
            .rewards-overview-metrics,
            .rewards-overview-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 700px) {
            .rewards-overview-metrics,
            .rewards-overview-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <section class="rewards-overview-stack" data-rewards-overview>
        @if($rewardsEditorAvailable ?? true)
            @include('shared.candle-cash.rewards-overview', [
                'overview' => $dashboard ?? [],
                'earnUrl' => route('shopify.embedded.rewards.earn'),
                'redeemUrl' => route('shopify.embedded.rewards.redeem'),
                'theme' => 'embedded',
                'rewardsLabel' => $rewardsLabel ?? null,
                'rewardsBalanceLabel' => $rewardsBalanceLabel ?? null,
                'displayLabels' => $displayLabels ?? [],
            ])
        @else
            <div class="rewards-note">
                {{ $rewardsEditorMessage ?: 'This rewards editor is unavailable for this store.' }}
            </div>
        @endif

        <article class="rewards-overview-card">
            <h3>Detailed analytics</h3>
            <p>See rewards impact and trend data.</p>

            @if(! $hasAnalyticsData)
                <div class="rewards-overview-empty">
                    <p>No analytics yet. Finish setup to start tracking.</p>
                    <a class="rewards-overview-link" href="{{ $embeddedUrl(route('shopify.app.start', [], false)) }}">Finish setup</a>
                </div>
            @else
                <div class="rewards-overview-metrics" aria-label="Rewards analytics metrics">
                    @foreach($topMetrics as $metric)
                        <article class="rewards-overview-metric">
                            <h4>{{ (string) ($metric['label'] ?? 'Metric') }}</h4>
                            <p>{{ (string) ($metric['formattedValue'] ?? '0') }}</p>
                            <small>{{ (string) ($metric['deltaLabel'] ?? 'No prior period') }}</small>
                        </article>
                    @endforeach
                </div>

                <div id="rewards-overview-chart" class="rewards-overview-chart" aria-label="Rewards trend chart"></div>
                <script id="rewards-overview-chart-data" type="application/json">
                    {!! json_encode([
                        'labels' => $chartLabels,
                        'series' => $chartSeries,
                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
                </script>

                <div class="rewards-overview-grid">
                    <article class="rewards-overview-card">
                        <h3>Attribution</h3>
                        <table class="rewards-overview-table">
                            <thead>
                                <tr>
                                    <th>Source</th>
                                    <th>Revenue</th>
                                    <th>Orders</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($attributionSources as $source)
                                    <tr>
                                        <td>{{ (string) ($source['label'] ?? 'Unknown') }}</td>
                                        <td>{{ (string) ($source['formattedRevenue'] ?? '$0') }}</td>
                                        <td>{{ number_format((int) ($source['orders'] ?? 0)) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </article>

                    <article class="rewards-overview-card">
                        <h3>Financial summary</h3>
                        <table class="rewards-overview-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($financialItems as $item)
                                    <tr>
                                        <td>{{ (string) ($item['label'] ?? 'Item') }}</td>
                                        <td>{{ (string) ($item['formattedValue'] ?? '$0') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </article>
                </div>
            @endif
        </article>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script>
        (() => {
            const node = document.getElementById('rewards-overview-chart');
            const payloadNode = document.getElementById('rewards-overview-chart-data');
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
                dataLabels: { enabled: false },
                series,
            });

            chart.render();
        })();
    </script>
@endsection
