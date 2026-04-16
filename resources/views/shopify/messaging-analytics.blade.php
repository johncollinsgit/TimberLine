<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-subnav="$pageSubnav ?? []"
    :page-actions="$pageActions"
>
    @php
        $messagingModuleState = is_array($messagingModuleState ?? null) ? $messagingModuleState : null;
        $messagingAccess = is_array($messagingAccess ?? null) ? $messagingAccess : [];
        $messagingEnabled = (bool) ($messagingAccess['enabled'] ?? false);
        $messagingStatus = trim((string) ($messagingAccess['status'] ?? ''));
        $messagingMessage = trim((string) ($messagingAccess['message'] ?? ''));

        $analytics = is_array($messageAnalytics ?? null) ? $messageAnalytics : [];
        $storefrontTracking = is_array($storefrontTracking ?? null) ? $storefrontTracking : [];
        $trackingHealth = is_array($storefrontTracking['health_summary'] ?? null) ? $storefrontTracking['health_summary'] : [];
        $trackingEvents = is_array($trackingHealth['events'] ?? null) ? $trackingHealth['events'] : [];
        $trackingTheme = is_array($trackingHealth['theme_embed'] ?? null) ? $trackingHealth['theme_embed'] : [];
        $trackingWebPixel = is_array($trackingHealth['web_pixel'] ?? null) ? $trackingHealth['web_pixel'] : [];
        $trackingScopes = is_array($trackingHealth['scopes'] ?? null) ? $trackingHealth['scopes'] : [];
        $trackingNative = is_array($storefrontTracking['shopify_native'] ?? null) ? $storefrontTracking['shopify_native'] : [];
        $trackingMissingScopes = array_values((array) ($trackingScopes['missing_requested'] ?? []));
        $trackingSetupInference = strtolower(trim((string) ($trackingHealth['setup_inference'] ?? 'configuration_only')));
        $analyticsTab = strtolower(trim((string) ($messageAnalyticsTab ?? request()->query('analytics_tab', 'home'))));
        if (! in_array($analyticsTab, ['home', 'performance', 'history', 'sales_success'], true)) {
            $analyticsTab = 'home';
        }
        $analyticsTabs = [
            ['key' => 'home', 'label' => 'Home'],
            ['key' => 'performance', 'label' => 'Message performance'],
            ['key' => 'history', 'label' => 'History outcomes'],
            ['key' => 'sales_success', 'label' => 'Sales Success'],
        ];
        $summary = is_array($analytics['summary'] ?? null) ? $analytics['summary'] : [];
        $chart = is_array($analytics['chart'] ?? null) ? $analytics['chart'] : [];
        $historyOutcomes = is_array($analytics['history_outcomes'] ?? null) ? $analytics['history_outcomes'] : [];
        $historySummary = is_array($historyOutcomes['summary'] ?? null) ? $historyOutcomes['summary'] : [];
        $historyRows = collect((array) ($historyOutcomes['rows'] ?? []))
            ->filter(fn ($row) => is_array($row))
            ->values();
        $salesSuccess = is_array($analytics['sales_success'] ?? null) ? $analytics['sales_success'] : [];
        $salesSuccessSummary = is_array($salesSuccess['summary'] ?? null) ? $salesSuccess['summary'] : [];
        $salesSuccessRows = collect((array) ($salesSuccess['rows'] ?? []))
            ->filter(fn ($row) => is_array($row))
            ->values();
        $messagesPaginator = $analytics['messages'] ?? null;
        $messages = $messagesPaginator instanceof \Illuminate\Pagination\LengthAwarePaginator
            ? $messagesPaginator
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25, 1, ['path' => request()->url(), 'query' => request()->query()]);

        $filters = is_array($messageAnalyticsFilters ?? null) ? $messageAnalyticsFilters : [];
        $detail = is_array($messageAnalyticsDetail ?? null) ? $messageAnalyticsDetail : null;
        $selectedMessageKey = trim((string) ($messageAnalyticsSelectedMessageKey ?? ''));
        $attribution = is_array($messageAnalyticsAttribution ?? null) ? $messageAnalyticsAttribution : [];
        $attributionWindowDays = max(1, (int) ($attribution['window_days'] ?? 7));
        $filterKeys = [
            'date_from',
            'date_to',
            'channel',
            'scope',
            'opened',
            'clicked',
            'has_orders',
            'url_search',
            'customer',
            'message',
            'per_page',
            'page',
            'message_key',
            'analytics_tab',
        ];
        $embeddedContextQuery = collect(request()->query())
            ->except($filterKeys)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();
        $tabQueryBase = collect(request()->query())
            ->except(['analytics_tab', 'page', 'message_key'])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        $formatMoney = static fn (int $cents): string => '$'.number_format(max(0, $cents) / 100, 2);
        $formatPercent = static fn (float|int $value): string => number_format((float) $value, 2).'%';
        $formatDateTime = static fn (?string $value): string => $value
            ? \Carbon\CarbonImmutable::parse($value)->setTimezone(config('app.timezone', 'UTC'))->format('M j, Y g:i A')
            : '—';
        $formatDate = static fn (?string $value): string => $value
            ? \Carbon\CarbonImmutable::parse($value)->setTimezone(config('app.timezone', 'UTC'))->format('M j, Y')
            : '—';
        $historyOutcomeLabel = static function (string $value): string {
            return match (strtolower(trim($value))) {
                'sale' => 'Sale',
                'responded' => 'Responded',
                'clicked' => 'Clicked',
                'opened' => 'Opened',
                default => 'Sent',
            };
        };
        $hasInferredOrdersWithoutClicks = (int) ($summary['attributed_orders'] ?? 0) > 0
            && (int) ($summary['total_clicks'] ?? 0) === 0;
    @endphp

    <style>
        .message-analytics-root {
            display: grid;
            gap: 14px;
            width: 100%;
            max-width: 1320px;
            margin: 0 auto;
        }

        .message-analytics-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
            padding: 14px;
            display: grid;
            gap: 10px;
        }

        .message-analytics-card h2,
        .message-analytics-card h3,
        .message-analytics-card h4,
        .message-analytics-card p {
            margin: 0;
        }

        .message-analytics-card[data-tone="error"] {
            border-color: rgba(180, 35, 24, 0.2);
            background: rgba(180, 35, 24, 0.06);
        }

        .message-analytics-muted {
            color: rgba(15, 23, 42, 0.62);
            font-size: 13px;
            line-height: 1.5;
        }

        .message-analytics-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .message-analytics-summary-card {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
            background: rgba(248, 250, 252, 0.72);
            padding: 10px;
            display: grid;
            gap: 4px;
        }

        .message-analytics-summary-card span {
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.56);
            font-weight: 700;
        }

        .message-analytics-summary-card strong {
            color: #0f172a;
            font-size: 1.3rem;
            line-height: 1;
            font-weight: 800;
        }

        .message-analytics-filters {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
        }

        .message-analytics-field {
            display: grid;
            gap: 6px;
        }

        .message-analytics-field--wide {
            grid-column: span 2;
        }

        .message-analytics-field label {
            font-size: 11px;
            color: rgba(15, 23, 42, 0.62);
            font-weight: 700;
        }

        .message-analytics-field input,
        .message-analytics-field select {
            width: 100%;
            min-height: 38px;
            border-radius: 10px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: #fff;
            color: #0f172a;
            padding: 0 10px;
            box-sizing: border-box;
            font-size: 13px;
        }

        .message-analytics-field input:focus,
        .message-analytics-field select:focus {
            outline: none;
            border-color: rgba(15, 143, 97, 0.36);
            box-shadow: 0 0 0 3px rgba(15, 143, 97, 0.12);
        }

        .message-analytics-filter-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: flex-end;
            grid-column: 1 / -1;
        }

        .message-analytics-button {
            min-height: 36px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: #fff;
            color: #0f172a;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
            padding: 0 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .message-analytics-button--primary {
            border-color: rgba(15, 143, 97, 0.35);
            background: rgba(15, 143, 97, 0.12);
            color: #0e7a53;
        }

        .message-analytics-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .message-analytics-tab {
            min-height: 36px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(248, 250, 252, 0.7);
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
            padding: 0 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .message-analytics-tab.is-active {
            border-color: rgba(15, 143, 97, 0.35);
            background: rgba(15, 143, 97, 0.12);
            color: #0e7a53;
        }

        .message-analytics-table-wrap {
            overflow: auto;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
        }

        .message-analytics-table {
            width: 100%;
            min-width: 1200px;
            border-collapse: collapse;
            font-size: 13px;
        }

        .message-analytics-table th,
        .message-analytics-table td {
            text-align: left;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            padding: 10px;
            vertical-align: top;
        }

        .message-analytics-table th {
            font-size: 11px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.56);
        }

        .message-analytics-table a {
            color: #0f6f4c;
            text-decoration: none;
            font-weight: 700;
        }

        .message-analytics-status {
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 700;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(248, 250, 252, 0.72);
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .message-analytics-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .message-analytics-links {
            display: inline-flex;
            gap: 6px;
            align-items: center;
        }

        .message-analytics-chart {
            min-height: 300px;
        }

        .message-analytics-series-picker {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .message-analytics-series-toggle {
            align-items: center;
            appearance: none;
            background: rgba(255, 255, 255, 0.75);
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 999px;
            cursor: pointer;
            display: inline-flex;
            gap: 0.6rem;
            padding: 0.5rem 0.8rem;
            text-align: left;
        }

        .message-analytics-series-toggle.is-active,
        .message-analytics-series-toggle[aria-pressed="true"] {
            background: rgba(15, 143, 97, 0.12);
            border-color: rgba(15, 143, 97, 0.28);
        }

        .message-analytics-series-swatch {
            width: 0.8rem;
            height: 0.8rem;
            border-radius: 999px;
            flex: 0 0 auto;
        }

        .message-analytics-series-copy {
            display: grid;
            gap: 1px;
        }

        .message-analytics-series-copy strong {
            font-size: 12px;
            color: #0f172a;
        }

        .message-analytics-series-copy span {
            font-size: 11px;
            color: rgba(15, 23, 42, 0.62);
        }

        .message-analytics-detail-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 12px;
        }

        .message-analytics-meta-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
        }

        .message-analytics-meta-card {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 10px;
            background: rgba(248, 250, 252, 0.72);
            padding: 10px;
            display: grid;
            gap: 4px;
        }

        .message-analytics-meta-card span {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: rgba(15, 23, 42, 0.56);
            font-weight: 700;
        }

        .message-analytics-meta-card strong {
            color: #0f172a;
            font-size: 13px;
            line-height: 1.4;
        }

        .message-analytics-empty {
            border-radius: 12px;
            border: 1px dashed rgba(15, 23, 42, 0.2);
            background: rgba(248, 250, 252, 0.78);
            padding: 14px;
            display: grid;
            gap: 8px;
        }

        .message-analytics-setup-guide {
            border-radius: 12px;
            border: 1px dashed rgba(15, 23, 42, 0.22);
            background: rgba(248, 250, 252, 0.82);
            padding: 12px;
            display: grid;
            gap: 8px;
        }

        .message-analytics-setup-guide h4 {
            margin: 0;
            font-size: 13px;
            color: #0f172a;
        }

        .message-analytics-setup-list {
            margin: 0;
            padding-left: 18px;
            display: grid;
            gap: 6px;
            font-size: 13px;
            color: #0f172a;
        }

        .message-analytics-setup-list li {
            display: grid;
            gap: 2px;
        }

        .message-analytics-setup-list li strong {
            font-weight: 700;
        }

        .message-analytics-setup-list li[data-done="true"] strong {
            color: #0e7a53;
        }

        .message-analytics-setup-list li[data-done="false"] strong {
            color: #9a3412;
        }

        .message-analytics-setup-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .message-analytics-setup-inline-status {
            margin: 0;
            font-size: 12px;
            line-height: 1.5;
            color: rgba(15, 23, 42, 0.76);
        }

        .message-analytics-setup-inline-status[data-tone="success"] {
            color: #0f766e;
        }

        .message-analytics-setup-inline-status[data-tone="error"] {
            color: #b42318;
        }

        @media (max-width: 1200px) {
            .message-analytics-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .message-analytics-filters {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .message-analytics-detail-grid,
            .message-analytics-meta-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 760px) {
            .message-analytics-summary,
            .message-analytics-filters {
                grid-template-columns: minmax(0, 1fr);
            }

            .message-analytics-field--wide {
                grid-column: span 1;
            }
        }
    </style>

    <section class="message-analytics-root">
        @if(! $authorized)
            <article class="message-analytics-card">
                <h2>Message analytics requires Shopify context</h2>
                <p class="message-analytics-muted">Open this page from Shopify Admin so Backstage can verify the store session and tenant scope.</p>
            </article>
        @elseif(! $messagingEnabled)
            <article class="message-analytics-card" data-tone="error">
                <h2>Message analytics is locked</h2>
                <p class="message-analytics-muted">{{ $messagingMessage !== '' ? $messagingMessage : 'Messaging is not enabled for this tenant.' }}</p>
                @if($messagingStatus !== '')
                    <p class="message-analytics-muted">Status: {{ $messagingStatus }}</p>
                @endif
            </article>
        @else
            <article class="message-analytics-card">
                <h2>Message Analytics</h2>
                <p class="message-analytics-muted">Attributed orders and revenue use a configurable last-click model (window: {{ $attributionWindowDays }} days). This is directional attribution, not guaranteed causation.</p>
            </article>

            @if($storefrontTracking !== [])
                <article class="message-analytics-card">
                    <h3>Storefront tracking health</h3>
                    <p class="message-analytics-muted">
                        Snapshot from tracking config plus recent funnel events. This helps separate configured state from proven live signal flow.
                    </p>

                    <section class="message-analytics-meta-grid" aria-label="Storefront tracking health snapshot">
                        <article class="message-analytics-meta-card">
                            <span>Theme embed inferred</span>
                            <strong>{{ (bool) ($trackingTheme['inferred_enabled'] ?? false) ? 'Yes' : 'No' }}</strong>
                            <span>{{ number_format((int) ($trackingTheme['event_count'] ?? 0)) }} recent theme events</span>
                        </article>
                        <article class="message-analytics-meta-card">
                            <span>Web pixel status</span>
                            <strong>{{ (bool) ($trackingWebPixel['connected'] ?? false) ? 'Connected' : 'Disconnected' }}</strong>
                            <span>{{ number_format((int) ($trackingWebPixel['event_count'] ?? 0)) }} recent pixel events</span>
                        </article>
                        <article class="message-analytics-meta-card">
                            <span>Recent storefront events</span>
                            <strong>{{ number_format((int) ($trackingEvents['recent_count'] ?? 0)) }}</strong>
                            <span>Last: {{ \Illuminate\Support\Str::of((string) ($trackingEvents['last_event_type'] ?? 'none'))->replace('_', ' ')->title() }}</span>
                        </article>
                        <article class="message-analytics-meta-card">
                            <span>Recent checkout completion</span>
                            <strong>{{ (bool) ($trackingEvents['checkout_completion_seen_recently'] ?? false) ? 'Seen' : 'Not seen' }}</strong>
                            <span>{{ $formatDateTime((string) ($trackingEvents['last_checkout_completed_at'] ?? null)) }}</span>
                        </article>
                        <article class="message-analytics-meta-card">
                            <span>Scope verification</span>
                            <strong>{{ (bool) ($trackingScopes['verified'] ?? false) ? 'Verified' : 'Pending' }}</strong>
                            <span>Source: {{ (string) ($trackingScopes['source'] ?? 'stored_snapshot') }}</span>
                        </article>
                    </section>

                    <div class="message-analytics-empty">
                        <p class="message-analytics-muted">
                            Setup basis: {{ $trackingSetupInference === 'recent_storefront_events' ? 'recent event data + config' : 'config only (no recent events yet)' }}.
                        </p>
                        @if($trackingMissingScopes !== [])
                            <p class="message-analytics-muted">
                                Missing requested Shopify scopes: {{ implode(', ', $trackingMissingScopes) }}.
                            </p>
                        @endif
                        @if((bool) data_get($trackingNative, 'analytics_and_reports.requested', false) && ! (bool) data_get($trackingNative, 'analytics_and_reports.granted', false))
                            <p class="message-analytics-muted">
                                Shopify native analytics/report scopes are requested but not currently confirmed as granted for this token.
                            </p>
                        @endif
                        @if(! (bool) data_get($trackingNative, 'analytics_and_reports.api_calls_detected', false))
                            <p class="message-analytics-muted">
                                Backstage does not currently query Shopify native analytics/report APIs for storefront funnel reporting.
                            </p>
                        @endif
                    </div>

                    <div class="message-analytics-setup-actions">
                        <a class="message-analytics-button" href="{{ route('shopify.app.messaging.setup', $embeddedContextQuery, false) }}">Open Tracking Setup</a>
                    </div>

                    <details class="message-analytics-setup-guide">
                        <summary>Raw tracking diagnostics</summary>
                        <pre class="message-analytics-muted" style="white-space: pre-wrap; margin: 0;">{{ json_encode($storefrontTracking, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                </article>
            @endif

            <article class="message-analytics-card">
                <h3>Analytics tabs</h3>
                <div class="message-analytics-tabs" role="tablist" aria-label="Message analytics sections">
                    @foreach($analyticsTabs as $tab)
                        @php
                            $isActiveTab = $analyticsTab === (string) ($tab['key'] ?? '');
                        @endphp
                        <a
                            role="tab"
                            aria-selected="{{ $isActiveTab ? 'true' : 'false' }}"
                            class="message-analytics-tab{{ $isActiveTab ? ' is-active' : '' }}"
                            href="{{ route('shopify.app.messaging.analytics', array_merge($tabQueryBase, ['analytics_tab' => (string) ($tab['key'] ?? 'home')]), false) }}"
                        >
                            {{ (string) ($tab['label'] ?? 'Tab') }}
                        </a>
                    @endforeach
                </div>
                <p class="message-analytics-muted">
                    Home keeps the first load focused on trend, filters, and summary. Use the other tabs for deeper operational tables.
                </p>
            </article>

            @if($analyticsTab === 'home')
            <article class="message-analytics-card">
                <h3>Engagement Trend</h3>
                <p class="message-analytics-muted">Toggle metrics on/off to compare email and text outcomes over time.</p>
                @php
                    $series = collect((array) ($chart['series'] ?? []))
                        ->map(function (array $row): array {
                            $values = array_map('intval', (array) ($row['data'] ?? []));
                            return [
                                'key' => (string) ($row['key'] ?? 'metric'),
                                'name' => (string) ($row['name'] ?? 'Metric'),
                                'color' => (string) ($row['color'] ?? '#0f766e'),
                                'selected' => (bool) ($row['selected'] ?? false),
                                'data' => $values,
                                'total' => array_sum($values),
                            ];
                        })
                        ->values();
                @endphp

                @if((bool) ($chart['empty'] ?? true) || $series->isEmpty())
                    <div class="message-analytics-empty">
                        <p class="message-analytics-muted">No tracked opens or clicks are available in this range yet.</p>
                        <p class="message-analytics-muted">Older SMS sends without tracked links cannot backfill click events. New sends are tracked automatically.</p>
                    </div>
                @else
                    <div class="message-analytics-series-picker" data-message-analytics-series-picker>
                        @foreach($series as $row)
                            @php
                                $isActive = (bool) ($row['selected'] ?? false);
                            @endphp
                            <button
                                type="button"
                                class="message-analytics-series-toggle{{ $isActive ? ' is-active' : '' }}"
                                data-series-key="{{ $row['key'] }}"
                                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                            >
                                <span class="message-analytics-series-swatch" style="background-color: {{ $row['color'] }}"></span>
                                <span class="message-analytics-series-copy">
                                    <strong>{{ $row['name'] }}</strong>
                                    <span>{{ number_format((int) ($row['total'] ?? 0)) }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>

                    <div id="message-analytics-chart" class="message-analytics-chart" aria-label="Message analytics trend chart"></div>
                    <script id="message-analytics-chart-data" type="application/json">
                        {!! json_encode([
                            'labels' => array_values((array) ($chart['labels'] ?? [])),
                            'series' => $series->all(),
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
                    </script>
                @endif
            </article>

            <article class="message-analytics-card">
                <h3>Filters</h3>
                <form method="GET" action="{{ route('shopify.app.messaging.analytics', [], false) }}" class="message-analytics-filters">
                    @foreach($embeddedContextQuery as $key => $value)
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}" />
                    @endforeach
                    <input type="hidden" name="analytics_tab" value="home" />
                    <input type="hidden" name="scope" value="{{ (string) ($filters['scope'] ?? 'all') }}" />

                    <div class="message-analytics-field">
                        <label for="analytics-date-from">Date from</label>
                        <input id="analytics-date-from" type="date" name="date_from" value="{{ (string) ($filters['date_from'] ?? '') }}" />
                    </div>

                    <div class="message-analytics-field">
                        <label for="analytics-date-to">Date to</label>
                        <input id="analytics-date-to" type="date" name="date_to" value="{{ (string) ($filters['date_to'] ?? '') }}" />
                    </div>

                    <div class="message-analytics-field">
                        <label for="analytics-channel">Channel</label>
                        <select id="analytics-channel" name="channel">
                            @foreach(['all' => 'All channels', 'email' => 'Email', 'sms' => 'Text'] as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['channel'] ?? 'all') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="message-analytics-field">
                        <label for="analytics-opened">Open state</label>
                        <select id="analytics-opened" name="opened">
                            @foreach(['all' => 'All', 'opened' => 'Opened', 'not_opened' => 'Not opened'] as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['opened'] ?? 'all') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="message-analytics-field">
                        <label for="analytics-clicked">Click state</label>
                        <select id="analytics-clicked" name="clicked">
                            @foreach(['all' => 'All', 'clicked' => 'Clicked', 'not_clicked' => 'Not clicked'] as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['clicked'] ?? 'all') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="message-analytics-field message-analytics-field--wide">
                        <label for="analytics-message">Campaign or message</label>
                        <input id="analytics-message" type="text" name="message" value="{{ (string) ($filters['message'] ?? '') }}" placeholder="Subject, batch, source label" />
                    </div>

                    <div class="message-analytics-field message-analytics-field--wide">
                        <label for="analytics-url-search">Specific URL or domain</label>
                        <input id="analytics-url-search" type="text" name="url_search" value="{{ (string) ($filters['url_search'] ?? '') }}" placeholder="example.com or full URL" />
                    </div>

                    <div class="message-analytics-field">
                        <label for="analytics-customer">Customer</label>
                        <input id="analytics-customer" type="text" name="customer" value="{{ (string) ($filters['customer'] ?? '') }}" placeholder="Name, email, phone" />
                    </div>

                    <div class="message-analytics-field">
                        <label for="analytics-per-page">Rows</label>
                        <select id="analytics-per-page" name="per_page">
                            @foreach([25, 50, 100] as $size)
                                <option value="{{ $size }}" @selected(((int) ($filters['per_page'] ?? 25)) === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="message-analytics-field" style="align-content:end;">
                        <label>
                            <input type="checkbox" name="has_orders" value="1" @checked((bool) ($filters['has_orders'] ?? false)) />
                            Has attributed orders
                        </label>
                    </div>

                    <div class="message-analytics-filter-actions">
                        <a class="message-analytics-button" href="{{ route('shopify.app.messaging.analytics', array_merge($embeddedContextQuery, ['analytics_tab' => 'home']), false) }}">Reset filters</a>
                        <button class="message-analytics-button message-analytics-button--primary" type="submit">Apply filters</button>
                    </div>
                </form>
            </article>

            <article class="message-analytics-card">
                <h3>Summary</h3>
                <div class="message-analytics-summary" aria-label="Message analytics summary cards">
                    <article class="message-analytics-summary-card">
                        <span>Messages sent</span>
                        <strong>{{ number_format((int) ($summary['messages_sent'] ?? 0)) }}</strong>
                    </article>
                    <article class="message-analytics-summary-card">
                        <span>Messages delivered</span>
                        <strong>{{ number_format((int) ($summary['messages_delivered'] ?? 0)) }}</strong>
                    </article>
                    <article class="message-analytics-summary-card">
                        <span>Messages opened</span>
                        <strong>{{ number_format((int) ($summary['messages_opened'] ?? 0)) }}</strong>
                    </article>
                    <article class="message-analytics-summary-card">
                        <span>Unique opens</span>
                        <strong>{{ number_format((int) ($summary['unique_opens'] ?? 0)) }}</strong>
                    </article>
                    <article class="message-analytics-summary-card">
                        <span>Total clicks</span>
                        <strong>{{ number_format((int) ($summary['total_clicks'] ?? 0)) }}</strong>
                    </article>
                    <article class="message-analytics-summary-card">
                        <span>Unique clicks</span>
                        <strong>{{ number_format((int) ($summary['unique_clicks'] ?? 0)) }}</strong>
                    </article>
                    <article class="message-analytics-summary-card">
                        <span>Attributed orders</span>
                        <strong>{{ number_format((int) ($summary['attributed_orders'] ?? 0)) }}</strong>
                    </article>
                    <article class="message-analytics-summary-card">
                        <span>Attributed revenue</span>
                        <strong>{{ $formatMoney((int) ($summary['attributed_revenue_cents'] ?? 0)) }}</strong>
                    </article>
                    <article class="message-analytics-summary-card">
                        <span>Click to order conversion</span>
                        <strong>{{ $formatPercent((float) ($summary['click_to_order_conversion_rate'] ?? 0)) }}</strong>
                    </article>
                </div>
                @if($hasInferredOrdersWithoutClicks)
                    <div class="message-analytics-empty">
                        <p class="message-analytics-muted">
                            This range includes attributed orders inferred from coupon or landing-page signals. Tracked click events were not available for at least one send in this range.
                        </p>
                    </div>
                @endif
            </article>

            @endif

            @if($analyticsTab === 'performance')
            <article class="message-analytics-card">
                <h3>Message performance</h3>
                <p class="message-analytics-muted">One row per email batch or logical SMS send run. Use View to inspect exact clicked URLs and attributed orders.</p>

                @if($messages->count() === 0)
                    <div class="message-analytics-empty">
                        <p class="message-analytics-muted">
                            @if((int) ($summary['messages_sent'] ?? 0) === 0)
                                No tracked message sends are available yet for this tenant/store/date range.
                            @else
                                No rows match the current filters.
                            @endif
                        </p>
                    </div>
                @else
                    <div class="message-analytics-table-wrap">
                        <table class="message-analytics-table" aria-label="Message analytics table">
                            <thead>
                                <tr>
                                    <th>Message</th>
                                    <th>Channel</th>
                                    <th>Sent</th>
                                    <th>Recipients</th>
                                    <th>Opens</th>
                                    <th>Open rate</th>
                                    <th>Clicks</th>
                                    <th>Click rate</th>
                                    <th>Orders</th>
                                    <th>Revenue</th>
                                    <th>Top link</th>
                                    <th>Status</th>
                                    <th>Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($messages as $row)
                                    @php
                                        $messageKey = (string) ($row['message_key'] ?? '');
                                        $detailHref = request()->fullUrlWithQuery([
                                            'message_key' => $messageKey,
                                            'page' => null,
                                        ]);
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong>{{ (string) ($row['message_name'] ?? 'Message') }}</strong>
                                            <div class="message-analytics-muted">
                                                {{ (string) ($row['source_label'] ?? 'shopify_embedded_messaging') }}
                                                @if((string) ($row['channel'] ?? '') === 'sms' && (int) ($row['batch_count'] ?? 0) > 1)
                                                    · {{ number_format((int) ($row['batch_count'] ?? 0)) }} batches rolled up
                                                @endif
                                            </div>
                                        </td>
                                        <td>{{ strtoupper((string) ($row['channel'] ?? '')) }}</td>
                                        <td>{{ $formatDate((string) ($row['sent_at'] ?? null)) }}</td>
                                        <td>{{ number_format((int) ($row['recipients_count'] ?? 0)) }}</td>
                                        <td>{{ number_format((int) ($row['opens'] ?? 0)) }}</td>
                                        <td>{{ $formatPercent((float) ($row['open_rate'] ?? 0)) }}</td>
                                        <td>{{ number_format((int) ($row['clicks'] ?? 0)) }}</td>
                                        <td>{{ $formatPercent((float) ($row['click_rate'] ?? 0)) }}</td>
                                        <td>{{ number_format((int) ($row['attributed_orders'] ?? 0)) }}</td>
                                        <td>{{ $formatMoney((int) ($row['attributed_revenue_cents'] ?? 0)) }}</td>
                                        <td>
                                            @if(filled($row['top_clicked_link'] ?? null))
                                                <span title="{{ (string) $row['top_clicked_link'] }}">{{ \Illuminate\Support\Str::limit((string) $row['top_clicked_link'], 48) }}</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td><span class="message-analytics-status">{{ (string) ($row['status'] ?? 'sent') }}</span></td>
                                        <td><a href="{{ $detailHref }}">View</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="message-analytics-pagination">
                        <p class="message-analytics-muted">
                            Showing {{ number_format((int) $messages->firstItem()) }}-{{ number_format((int) $messages->lastItem()) }} of {{ number_format((int) $messages->total()) }} messages.
                        </p>
                        <div class="message-analytics-links">
                            @if($messages->onFirstPage())
                                <span class="message-analytics-button">Previous</span>
                            @else
                                <a class="message-analytics-button" href="{{ $messages->previousPageUrl() }}">Previous</a>
                            @endif

                            <span class="message-analytics-muted">Page {{ $messages->currentPage() }} of {{ $messages->lastPage() }}</span>

                            @if($messages->hasMorePages())
                                <a class="message-analytics-button" href="{{ $messages->nextPageUrl() }}">Next</a>
                            @else
                                <span class="message-analytics-button">Next</span>
                            @endif
                        </div>
                    </div>
                @endif
            </article>

            @endif

            @if($analyticsTab === 'history')
            <article class="message-analytics-card">
                <h3>Recent Message History Outcomes</h3>
                <p class="message-analytics-muted">
                    Mirrors workspace History with operational outcomes.
                    Rows: {{ number_format((int) ($historySummary['total_rows'] ?? 0)) }} ·
                    Responded: {{ number_format((int) ($historySummary['responded_rows'] ?? 0)) }} ·
                    Attributed orders: {{ number_format((int) ($historySummary['attributed_orders'] ?? 0)) }}
                </p>
                <p class="message-analytics-muted">“Responded” is based on inbound SMS webhook matching and is directional, not guaranteed causation.</p>

                @if($historyRows->isEmpty())
                    <div class="message-analytics-empty">
                        <p class="message-analytics-muted">No history rows match the current filter set yet.</p>
                    </div>
                @else
                    <div class="message-analytics-table-wrap">
                        <table class="message-analytics-table" aria-label="Recent message outcomes table">
                            <thead>
                                <tr>
                                    <th>Sent</th>
                                    <th>Message</th>
                                    <th>Channel</th>
                                    <th>Customer</th>
                                    <th>Opened</th>
                                    <th>Clicked</th>
                                    <th>Orders</th>
                                    <th>Revenue</th>
                                    <th>Outcome</th>
                                    <th>Reply at</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($historyRows as $row)
                                    @php
                                        $profileId = (int) ($row['profile_id'] ?? 0);
                                        $chatHref = $profileId > 0
                                            ? route('shopify.app.customers.detail', array_merge(['marketingProfile' => $profileId], $embeddedContextQuery), false).'#message-customer'
                                            : null;
                                        $rowOutcome = $historyOutcomeLabel((string) ($row['outcome'] ?? 'sent'));
                                    @endphp
                                    <tr>
                                        <td>{{ $formatDateTime((string) ($row['sent_at'] ?? null)) }}</td>
                                        <td>
                                            <strong>{{ (string) ($row['message_name'] ?? 'Message') }}</strong>
                                            <div class="message-analytics-muted">{{ \Illuminate\Support\Str::limit((string) ($row['message_preview'] ?? ''), 72) }}</div>
                                        </td>
                                        <td>{{ strtoupper((string) ($row['channel'] ?? '')) }}</td>
                                        <td>
                                            <strong>{{ (string) ($row['profile_name'] ?? 'Customer') }}</strong>
                                            <div class="message-analytics-muted">{{ (string) ($row['recipient'] ?? '—') }}</div>
                                        </td>
                                        <td>{{ (bool) ($row['opened'] ?? false) ? 'Yes' : 'No' }}</td>
                                        <td>{{ (bool) ($row['clicked'] ?? false) ? 'Yes' : 'No' }}</td>
                                        <td>{{ number_format((int) ($row['attributed_orders'] ?? 0)) }}</td>
                                        <td>{{ $formatMoney((int) ($row['attributed_revenue_cents'] ?? 0)) }}</td>
                                        <td>
                                            <span class="message-analytics-status">{{ $rowOutcome }}</span>
                                            @if(filled($row['response_preview'] ?? null))
                                                <div class="message-analytics-muted">{{ \Illuminate\Support\Str::limit((string) $row['response_preview'], 52) }}</div>
                                            @endif
                                        </td>
                                        <td>{{ $formatDateTime((string) ($row['responded_at'] ?? null)) }}</td>
                                        <td>
                                            @if($chatHref !== null)
                                                <a href="{{ $chatHref }}">Open chat</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </article>

            @endif

            @if($analyticsTab === 'sales_success')
                <article class="message-analytics-card">
                    <h3>Sales Success</h3>
                    <p class="message-analytics-muted">These are purchases that can be tied to a sent message in your current date range and filters.</p>
                    <p class="message-analytics-muted">
                        Orders: {{ number_format((int) ($salesSuccessSummary['total_orders'] ?? 0)) }} ·
                        From email: {{ number_format((int) ($salesSuccessSummary['email_orders'] ?? 0)) }} ·
                        From text: {{ number_format((int) ($salesSuccessSummary['text_orders'] ?? 0)) }} ·
                        Total value: {{ $formatMoney((int) ($salesSuccessSummary['total_value_cents'] ?? 0)) }}
                    </p>

                    @if($salesSuccessRows->isEmpty())
                        <div class="message-analytics-empty">
                            <p class="message-analytics-muted">No message-linked purchases matched this filter set yet.</p>
                        </div>
                    @else
                        <div class="message-analytics-table-wrap">
                            <table class="message-analytics-table" aria-label="Sales success orders table">
                                <thead>
                                    <tr>
                                        <th>Order</th>
                                        <th>Customer</th>
                                        <th>Came from</th>
                                        <th>Message</th>
                                        <th>Pages before purchase</th>
                                        <th>Purchased on</th>
                                        <th>Order value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($salesSuccessRows as $row)
                                        <tr>
                                            <td>{{ (string) ($row['order_number'] ?? '#'.(int) ($row['order_id'] ?? 0)) }}</td>
                                            <td>
                                                <strong>{{ (string) ($row['customer'] ?? 'Customer') }}</strong>
                                                @if(filled($row['customer_email'] ?? null))
                                                    <div class="message-analytics-muted">{{ (string) $row['customer_email'] }}</div>
                                                @endif
                                            </td>
                                            <td>{{ (string) ($row['channel_label'] ?? 'Message') }}</td>
                                            <td>{{ (string) ($row['message_name'] ?? 'Message') }}</td>
                                            <td>{{ (string) ($row['pages_followed'] ?? 'Page path before purchase was not captured for this order yet.') }}</td>
                                            <td>{{ $formatDateTime((string) ($row['purchase_at'] ?? null)) }}</td>
                                            <td>{{ $formatMoney((int) ($row['value_cents'] ?? 0)) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </article>
            @endif

            @if($analyticsTab === 'performance' && $selectedMessageKey !== '')
                <article class="message-analytics-card" id="message-analytics-detail">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
                        <div>
                            <h3>Message detail</h3>
                            <p class="message-analytics-muted">URL-level clicks and attributed order outcomes for the selected message.</p>
                        </div>
                        <a class="message-analytics-button" href="{{ request()->fullUrlWithQuery(['message_key' => null]) }}">Close detail</a>
                    </div>

                    @if(! is_array($detail))
                        <div class="message-analytics-empty">
                            <p class="message-analytics-muted">This message detail could not be loaded. It may no longer exist for this tenant/store scope.</p>
                        </div>
                    @else
                        @if((int) ($detail['attributed_orders'] ?? 0) > 0 && (int) ($detail['clicks'] ?? 0) === 0)
                            <div class="message-analytics-empty">
                                <p class="message-analytics-muted">
                                    This message has attributed orders inferred from coupon or landing-page signals. Raw click tracking was not available for this send.
                                </p>
                            </div>
                        @endif

                        <section class="message-analytics-meta-grid" aria-label="Message detail summary">
                            <article class="message-analytics-meta-card">
                                <span>Message</span>
                                <strong>{{ (string) ($detail['message_name'] ?? 'Message') }}</strong>
                            </article>
                            <article class="message-analytics-meta-card">
                                <span>Channel</span>
                                <strong>{{ strtoupper((string) ($detail['channel'] ?? '')) }}</strong>
                            </article>
                            <article class="message-analytics-meta-card">
                                <span>Status</span>
                                <strong>{{ (string) ($detail['status'] ?? 'sent') }}</strong>
                            </article>
                            <article class="message-analytics-meta-card">
                                <span>Recipients</span>
                                <strong>{{ number_format((int) ($detail['recipients_count'] ?? 0)) }}</strong>
                            </article>
                            <article class="message-analytics-meta-card">
                                <span>Opens / clicks</span>
                                <strong>{{ number_format((int) ($detail['opens'] ?? 0)) }} / {{ number_format((int) ($detail['clicks'] ?? 0)) }}</strong>
                            </article>
                            <article class="message-analytics-meta-card">
                                <span>Attributed revenue</span>
                                <strong>{{ $formatMoney((int) ($detail['attributed_revenue_cents'] ?? 0)) }}</strong>
                            </article>
                        </section>

                        <section class="message-analytics-detail-grid">
                            <article class="message-analytics-card">
                                <h4>Message metadata</h4>
                                <div class="message-analytics-table-wrap">
                                    <table class="message-analytics-table" style="min-width:560px;">
                                        <tbody>
                                            @if((string) data_get($detail, 'metadata.batch_scope') === 'logical_run')
                                                <tr>
                                                    <th>Run batches</th>
                                                    <td>
                                                        {{ number_format((int) data_get($detail, 'metadata.batch_count', 0)) }}
                                                        @if(count((array) data_get($detail, 'metadata.batch_ids', [])) > 0)
                                                            <div class="message-analytics-muted">
                                                                {{ \Illuminate\Support\Str::limit(implode(', ', (array) data_get($detail, 'metadata.batch_ids', [])), 120) }}
                                                            </div>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @else
                                                <tr><th>Batch ID</th><td>{{ (string) data_get($detail, 'metadata.batch_id', '—') }}</td></tr>
                                            @endif
                                            <tr><th>Source label</th><td>{{ (string) data_get($detail, 'metadata.source_label', '—') }}</td></tr>
                                            <tr><th>Subject</th><td>{{ (string) data_get($detail, 'metadata.subject', '—') }}</td></tr>
                                            <tr><th>Sent at</th><td>{{ $formatDateTime((string) ($detail['sent_at'] ?? null)) }}</td></tr>
                                            <tr><th>Last sent at</th><td>{{ $formatDateTime((string) ($detail['last_sent_at'] ?? null)) }}</td></tr>
                                            <tr><th>Delivered</th><td>{{ number_format((int) ($detail['delivered'] ?? 0)) }}</td></tr>
                                            <tr><th>Unique opens</th><td>{{ number_format((int) ($detail['unique_opens'] ?? 0)) }}</td></tr>
                                            <tr><th>Unique clicks</th><td>{{ number_format((int) ($detail['unique_clicks'] ?? 0)) }}</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </article>

                            <article class="message-analytics-card">
                                <h4>Open timeline</h4>
                                @if(count((array) ($detail['opens_timeline'] ?? [])) === 0)
                                    <p class="message-analytics-muted">No open events were recorded for this message yet.</p>
                                @else
                                    <div class="message-analytics-table-wrap">
                                        <table class="message-analytics-table" style="min-width:360px;">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Open events</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach((array) ($detail['opens_timeline'] ?? []) as $point)
                                                    <tr>
                                                        <td>{{ $formatDate((string) ($point['date'] ?? null)) }}</td>
                                                        <td>{{ number_format((int) ($point['count'] ?? 0)) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </article>
                        </section>

                        <article class="message-analytics-card">
                            <h4>Links</h4>
                            @if(count((array) ($detail['links'] ?? [])) === 0)
                                <p class="message-analytics-muted">No tracked link clicks were recorded for this message yet.</p>
                            @else
                                <div class="message-analytics-table-wrap">
                                    <table class="message-analytics-table" aria-label="Message link analytics">
                                        <thead>
                                            <tr>
                                                <th>Link label</th>
                                                <th>Raw URL</th>
                                                <th>Normalized URL</th>
                                                <th>Clicks</th>
                                                <th>Unique clicks</th>
                                                <th>First click</th>
                                                <th>Last click</th>
                                                <th>Orders</th>
                                                <th>Revenue</th>
                                                <th>Conversion</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach((array) ($detail['links'] ?? []) as $link)
                                                <tr>
                                                    <td>{{ (string) ($link['link_label'] ?? 'Link') }}</td>
                                                    <td>
                                                        @if(filled($link['url'] ?? null))
                                                            <span title="{{ (string) $link['url'] }}">{{ \Illuminate\Support\Str::limit((string) $link['url'], 54) }}</span>
                                                        @else
                                                            —
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if(filled($link['normalized_url'] ?? null))
                                                            <span title="{{ (string) $link['normalized_url'] }}">{{ \Illuminate\Support\Str::limit((string) $link['normalized_url'], 54) }}</span>
                                                        @else
                                                            —
                                                        @endif
                                                    </td>
                                                    <td>{{ number_format((int) ($link['click_count'] ?? 0)) }}</td>
                                                    <td>{{ number_format((int) ($link['unique_click_count'] ?? 0)) }}</td>
                                                    <td>{{ $formatDateTime((string) ($link['first_click_at'] ?? null)) }}</td>
                                                    <td>{{ $formatDateTime((string) ($link['last_click_at'] ?? null)) }}</td>
                                                    <td>{{ number_format((int) ($link['attributed_orders'] ?? 0)) }}</td>
                                                    <td>{{ $formatMoney((int) ($link['attributed_revenue_cents'] ?? 0)) }}</td>
                                                    <td>{{ $formatPercent((float) ($link['conversion_rate'] ?? 0)) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </article>

                        <article class="message-analytics-card">
                            <div>
                                <h4>Storefront funnel</h4>
                                <p class="message-analytics-muted">Future tracked email sessions can show what happened after the click: landing, product interest, cart activity, and checkout progression.</p>
                            </div>

                            @php($funnel = is_array($detail['funnel'] ?? null) ? $detail['funnel'] : ['summary' => [], 'products' => [], 'events' => []])
                            @php($funnelSummary = is_array($funnel['summary'] ?? null) ? $funnel['summary'] : [])
                            @php($funnelProducts = (array) ($funnel['products'] ?? []))
                            @php($funnelEvents = (array) ($funnel['events'] ?? []))

                            @if(array_sum(array_map(fn ($value) => (int) $value, $funnelSummary)) === 0)
                                <p class="message-analytics-muted">No storefront funnel events have been captured for this message yet. Once the Forestry storefront tracking embed and pixel are deployed and enabled, session, product, cart, and checkout events will appear here.</p>
                            @else
                                <section class="message-analytics-meta-grid" aria-label="Storefront funnel summary">
                                    <article class="message-analytics-meta-card">
                                        <span>Sessions started</span>
                                        <strong>{{ number_format((int) ($funnelSummary['sessions_started'] ?? 0)) }}</strong>
                                    </article>
                                    <article class="message-analytics-meta-card">
                                        <span>Landing page views</span>
                                        <strong>{{ number_format((int) ($funnelSummary['landing_page_views'] ?? 0)) }}</strong>
                                    </article>
                                    <article class="message-analytics-meta-card">
                                        <span>Product views</span>
                                        <strong>{{ number_format((int) ($funnelSummary['product_views'] ?? 0)) }}</strong>
                                    </article>
                                    <article class="message-analytics-meta-card">
                                        <span>Wishlist adds</span>
                                        <strong>{{ number_format((int) ($funnelSummary['wishlist_adds'] ?? 0)) }}</strong>
                                    </article>
                                    <article class="message-analytics-meta-card">
                                        <span>Add to cart</span>
                                        <strong>{{ number_format((int) ($funnelSummary['add_to_cart'] ?? 0)) }}</strong>
                                    </article>
                                    <article class="message-analytics-meta-card">
                                        <span>Checkout started</span>
                                        <strong>{{ number_format((int) ($funnelSummary['checkout_started'] ?? 0)) }}</strong>
                                    </article>
                                    <article class="message-analytics-meta-card">
                                        <span>Checkout completed</span>
                                        <strong>{{ number_format((int) ($funnelSummary['checkout_completed'] ?? 0)) }}</strong>
                                    </article>
                                </section>

                                <div class="message-analytics-empty">
                                    <p class="message-analytics-muted">
                                        Candidate checkout abandonments: {{ number_format((int) ($funnelSummary['checkout_abandoned_candidates'] ?? 0)) }}.
                                        This is directional until storefront completion events are fully wired for every path.
                                    </p>
                                </div>

                                <section class="message-analytics-detail-grid">
                                    <article class="message-analytics-card">
                                        <h4>Top products</h4>
                                        @if(count($funnelProducts) === 0)
                                            <p class="message-analytics-muted">No product-specific storefront events have been recorded for this message yet.</p>
                                        @else
                                            <div class="message-analytics-table-wrap">
                                                <table class="message-analytics-table" style="min-width:640px;" aria-label="Storefront funnel top products">
                                                    <thead>
                                                        <tr>
                                                            <th>Product</th>
                                                            <th>ID</th>
                                                            <th>Views</th>
                                                            <th>Wishlist adds</th>
                                                            <th>Add to cart</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach($funnelProducts as $product)
                                                            <tr>
                                                                <td>{{ (string) ($product['product_title'] ?? 'Product') }}</td>
                                                                <td>{{ (string) ($product['product_id'] ?? $product['product_handle'] ?? '—') }}</td>
                                                                <td>{{ number_format((int) ($product['product_views'] ?? 0)) }}</td>
                                                                <td>{{ number_format((int) ($product['wishlist_adds'] ?? 0)) }}</td>
                                                                <td>{{ number_format((int) ($product['add_to_cart'] ?? 0)) }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endif
                                    </article>

                                    <article class="message-analytics-card">
                                        <h4>Recent funnel events</h4>
                                        @if(count($funnelEvents) === 0)
                                            <p class="message-analytics-muted">No recent storefront funnel events were matched to this message.</p>
                                        @else
                                            <div class="message-analytics-table-wrap">
                                                <table class="message-analytics-table" style="min-width:640px;" aria-label="Storefront funnel events">
                                                    <thead>
                                                        <tr>
                                                            <th>Event</th>
                                                            <th>Product</th>
                                                            <th>Page</th>
                                                            <th>Occurred</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach($funnelEvents as $event)
                                                            <tr>
                                                                <td>{{ \Illuminate\Support\Str::of((string) ($event['event_type'] ?? 'event'))->replace('_', ' ')->title() }}</td>
                                                                <td>{{ (string) ($event['product_title'] ?? $event['product_id'] ?? '—') }}</td>
                                                                <td>{{ (string) ($event['page_path'] ?? '—') }}</td>
                                                                <td>{{ $formatDateTime((string) ($event['occurred_at'] ?? null)) }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endif
                                    </article>
                                </section>
                            @endif
                        </article>

                        <article class="message-analytics-card">
                            <h4>Attributed orders</h4>
                            @if(count((array) ($detail['orders'] ?? [])) === 0)
                                <p class="message-analytics-muted">No orders were attributed to this message within the configured window.</p>
                            @else
                                <div class="message-analytics-table-wrap">
                                    <table class="message-analytics-table" aria-label="Attributed orders table">
                                        <thead>
                                            <tr>
                                                <th>Order</th>
                                                <th>Customer</th>
                                                <th>Email</th>
                                                <th>Evidence</th>
                                                <th>Landing page</th>
                                                <th>Referrer / source</th>
                                                <th>URL</th>
                                                <th>Clicked at</th>
                                                <th>Ordered at</th>
                                                <th>Revenue</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach((array) ($detail['orders'] ?? []) as $order)
                                                <tr>
                                                    <td>{{ (string) ($order['order_number'] ?? '#'.(int) ($order['order_id'] ?? 0)) }}</td>
                                                    <td>{{ (string) ($order['customer'] ?? 'Customer') }}</td>
                                                    <td>{{ (string) ($order['customer_email'] ?? '—') }}</td>
                                                    <td>{{ (string) ($order['attribution_method'] ?? 'Attributed order') }}</td>
                                                    <td>
                                                        @if(filled($order['landing_page'] ?? null))
                                                            <span title="{{ (string) $order['landing_page'] }}">{{ \Illuminate\Support\Str::limit((string) $order['landing_page'], 48) }}</span>
                                                        @else
                                                            —
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @php($sourceDetail = (string) ($order['referrer'] ?? $order['source_summary'] ?? ''))
                                                        @if($sourceDetail !== '')
                                                            <span title="{{ $sourceDetail }}">{{ \Illuminate\Support\Str::limit($sourceDetail, 44) }}</span>
                                                        @elseif(filled($order['source_summary'] ?? null))
                                                            <span title="{{ (string) $order['source_summary'] }}">{{ \Illuminate\Support\Str::limit((string) $order['source_summary'], 44) }}</span>
                                                        @else
                                                            —
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if(filled($order['url'] ?? null))
                                                            <span title="{{ (string) $order['url'] }}">{{ \Illuminate\Support\Str::limit((string) $order['url'], 52) }}</span>
                                                        @else
                                                            —
                                                        @endif
                                                    </td>
                                                    <td>{{ $formatDateTime((string) ($order['click_at'] ?? null)) }}</td>
                                                    <td>{{ $formatDateTime((string) ($order['ordered_at'] ?? null)) }}</td>
                                                    <td>{{ $formatMoney((int) ($order['revenue_cents'] ?? 0)) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </article>
                    @endif
                </article>
            @endif
        @endif
    </section>

    @if($authorized && $messagingEnabled)
        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
        <script>
            (function () {
                const chartNode = document.getElementById('message-analytics-chart');
                const dataNode = document.getElementById('message-analytics-chart-data');
                if (!chartNode || !dataNode || typeof window.ApexCharts === 'undefined') {
                    return;
                }

                let payload;
                try {
                    payload = JSON.parse(dataNode.textContent || '{}');
                } catch (error) {
                    payload = { labels: [], series: [] };
                }

                const labels = Array.isArray(payload.labels) ? payload.labels : [];
                const allSeries = Array.isArray(payload.series) ? payload.series : [];
                const toggleButtons = Array.from(document.querySelectorAll('[data-series-key]'));

                if (labels.length === 0 || allSeries.length === 0 || toggleButtons.length === 0) {
                    return;
                }

                const selected = new Set(
                    allSeries
                        .filter((row) => Boolean(row.selected))
                        .map((row) => String(row.key || '').trim())
                        .filter(Boolean)
                );

                if (selected.size === 0 && allSeries.length > 0) {
                    selected.add(String(allSeries[0].key || '').trim());
                }

                const colorByKey = allSeries.reduce((map, row) => {
                    const key = String(row.key || '').trim();
                    if (key !== '') {
                        map[key] = String(row.color || '#0f766e');
                    }
                    return map;
                }, {});

                function activeSeries() {
                    const series = allSeries
                        .filter((row) => selected.has(String(row.key || '').trim()))
                        .map((row) => ({
                            name: String(row.name || row.key || 'Metric'),
                            data: Array.isArray(row.data) ? row.data.map((value) => Number(value || 0)) : [],
                            key: String(row.key || '').trim(),
                        }));

                    if (series.length > 0) {
                        return series;
                    }

                    const fallback = allSeries[0] || { key: 'metric', name: 'Metric', data: [] };
                    return [{
                        name: String(fallback.name || fallback.key || 'Metric'),
                        data: Array.isArray(fallback.data) ? fallback.data.map((value) => Number(value || 0)) : [],
                        key: String(fallback.key || 'metric').trim(),
                    }];
                }

                function activeColors(seriesRows) {
                    return seriesRows.map((row) => colorByKey[row.key] || '#0f766e');
                }

                const chart = new window.ApexCharts(chartNode, {
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
                    colors: activeColors(activeSeries()),
                    series: activeSeries(),
                });

                chart.render();

                function syncButtons() {
                    toggleButtons.forEach((button) => {
                        const key = String(button.getAttribute('data-series-key') || '').trim();
                        const isActive = selected.has(key);
                        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                        button.classList.toggle('is-active', isActive);
                    });
                }

                function refreshChart() {
                    const seriesRows = activeSeries();
                    chart.updateOptions({ colors: activeColors(seriesRows) }, false, false, false);
                    chart.updateSeries(seriesRows, true);
                }

                toggleButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        const key = String(button.getAttribute('data-series-key') || '').trim();
                        if (key === '') {
                            return;
                        }

                        if (selected.has(key)) {
                            if (selected.size === 1) {
                                return;
                            }
                            selected.delete(key);
                        } else {
                            selected.add(key);
                        }

                        syncButtons();
                        refreshChart();
                    });
                });

                syncButtons();
            })();
        </script>
    @endif
</x-shopify-embedded-shell>
