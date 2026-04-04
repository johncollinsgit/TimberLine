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
        $summary = is_array($analytics['summary'] ?? null) ? $analytics['summary'] : [];
        $chart = is_array($analytics['chart'] ?? null) ? $analytics['chart'] : [];
        $messagesPaginator = $analytics['messages'] ?? null;
        $messages = $messagesPaginator instanceof \Illuminate\Pagination\LengthAwarePaginator
            ? $messagesPaginator
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25, 1, ['path' => request()->url(), 'query' => request()->query()]);

        $filters = is_array($messageAnalyticsFilters ?? null) ? $messageAnalyticsFilters : [];
        $detail = is_array($messageAnalyticsDetail ?? null) ? $messageAnalyticsDetail : null;
        $selectedMessageKey = trim((string) ($messageAnalyticsSelectedMessageKey ?? ''));
        $attribution = is_array($messageAnalyticsAttribution ?? null) ? $messageAnalyticsAttribution : [];
        $attributionWindowDays = max(1, (int) ($attribution['window_days'] ?? 7));
        $setupGuide = is_array($messagingSetupGuide ?? null) ? $messagingSetupGuide : [];
        $setupConfigured = strtolower(trim((string) ($setupGuide['status'] ?? 'not_started'))) === 'configured';
        $setupSteps = collect((array) ($setupGuide['steps'] ?? []))
            ->filter(fn ($step) => is_array($step) && trim((string) ($step['label'] ?? '')) !== '')
            ->values();

        $filterKeys = [
            'date_from',
            'date_to',
            'channel',
            'opened',
            'clicked',
            'has_orders',
            'url_search',
            'customer',
            'message',
            'per_page',
            'page',
            'message_key',
        ];
        $embeddedContextQuery = collect(request()->query())
            ->except($filterKeys)
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
        @if(is_array($messagingModuleState))
            <x-tenancy.module-state-card
                :module-state="$messagingModuleState"
                title="Messaging module state"
                description="Visibility and access follow tenant entitlement + module-state conventions."
            >
                <div class="message-analytics-setup-guide">
                    @if($setupConfigured)
                        <h4>Setup complete for this tenant</h4>
                        <p class="message-analytics-muted">For new tenants, use the same sequence below and then click “Mark setup complete.”</p>
                    @else
                        <h4>How to set this up</h4>
                        <p class="message-analytics-muted">Complete these steps in order, then mark the module configured.</p>
                    @endif

                    @if($setupSteps->isNotEmpty())
                        <ol class="message-analytics-setup-list" aria-label="Messaging setup checklist">
                            @foreach($setupSteps as $step)
                                @php
                                    $stepDone = (bool) ($step['done'] ?? false);
                                @endphp
                                <li data-done="{{ $stepDone ? 'true' : 'false' }}">
                                    <strong>{{ $stepDone ? 'Done:' : 'Next:' }} {{ (string) ($step['label'] ?? '') }}</strong>
                                    @if(filled($step['hint'] ?? null))
                                        <span class="message-analytics-muted">{{ (string) $step['hint'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif

                    <div class="message-analytics-setup-actions">
                        <a class="message-analytics-button" href="{{ route('shopify.app.settings', $embeddedContextQuery, false) }}">Open Settings</a>
                        <a class="message-analytics-button" href="{{ route('shopify.app.messaging', $embeddedContextQuery, false) }}">Open Messaging</a>
                        @if(! $setupConfigured && (bool) ($setupGuide['can_mark_complete'] ?? false))
                            <button
                                type="button"
                                class="message-analytics-button message-analytics-button--primary"
                                data-mark-setup-complete
                                data-endpoint="{{ (string) data_get($setupGuide, 'actions.complete_endpoint', route('shopify.app.api.messaging.setup.complete', [], false)) }}"
                            >
                                Mark setup complete
                            </button>
                        @endif
                    </div>

                    <p class="message-analytics-setup-inline-status" id="message-analytics-setup-status" hidden></p>
                </div>
            </x-tenancy.module-state-card>
        @endif

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

            <article class="message-analytics-card">
                <h3>Filters</h3>
                <form method="GET" action="{{ route('shopify.app.messaging.analytics', [], false) }}" class="message-analytics-filters">
                    @foreach($embeddedContextQuery as $key => $value)
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}" />
                    @endforeach

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
                        <a class="message-analytics-button" href="{{ route('shopify.app.messaging.analytics', $embeddedContextQuery, false) }}">Reset filters</a>
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
            </article>

            <article class="message-analytics-card">
                <h3>Engagement Trend</h3>
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
                <h3>Message performance</h3>
                <p class="message-analytics-muted">One row per message send batch. Use View to inspect exact clicked URLs and attributed orders.</p>

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
                                            <div class="message-analytics-muted">{{ (string) ($row['source_label'] ?? 'shopify_embedded_messaging') }}</div>
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

            @if($selectedMessageKey !== '')
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
                                            <tr><th>Batch ID</th><td>{{ (string) data_get($detail, 'metadata.batch_id', '—') }}</td></tr>
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
                const setupCompleteButton = document.querySelector('[data-mark-setup-complete]');
                const setupStatusNode = document.getElementById('message-analytics-setup-status');

                function setSetupStatus(message, tone = 'neutral') {
                    if (!setupStatusNode) {
                        return;
                    }

                    const text = typeof message === 'string' ? message.trim() : '';
                    if (text === '') {
                        setupStatusNode.hidden = true;
                        setupStatusNode.textContent = '';
                        setupStatusNode.removeAttribute('data-tone');
                        return;
                    }

                    setupStatusNode.hidden = false;
                    setupStatusNode.textContent = text;
                    setupStatusNode.setAttribute('data-tone', tone);
                }

                function authFailureMessage(status, fallbackMessage) {
                    const messages = {
                        missing_api_auth: 'Shopify Admin verification is unavailable. Reload from Shopify Admin and try again.',
                        invalid_session_token: 'Shopify Admin verification failed. Reload from Shopify Admin and try again.',
                        expired_session_token: 'Your Shopify Admin session expired. Reload from Shopify Admin and try again.',
                    };

                    return messages[status] || fallbackMessage || null;
                }

                async function resolveEmbeddedAuthHeaders() {
                    if (!window.shopify || typeof window.shopify.idToken !== 'function') {
                        throw new Error(authFailureMessage('missing_api_auth', 'Shopify Admin verification is unavailable.'));
                    }

                    let token = null;
                    try {
                        token = await Promise.race([
                            Promise.resolve(window.shopify.idToken()),
                            new Promise((resolve) => window.setTimeout(() => resolve(null), 1500)),
                        ]);
                    } catch (error) {
                        throw new Error(authFailureMessage('invalid_session_token', 'Shopify Admin verification failed.'));
                    }

                    if (typeof token !== 'string' || token.trim() === '') {
                        throw new Error(authFailureMessage('missing_api_auth', 'Shopify Admin verification is unavailable.'));
                    }

                    return {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        Authorization: `Bearer ${token.trim()}`,
                    };
                }

                async function postJson(url) {
                    const headers = await resolveEmbeddedAuthHeaders();
                    const response = await fetch(url, {
                        method: 'POST',
                        headers,
                        credentials: 'same-origin',
                    });

                    const payload = await response.json().catch(() => ({
                        ok: false,
                        message: 'Unexpected response from Backstage.',
                    }));

                    if (!response.ok) {
                        throw new Error(
                            authFailureMessage(payload?.status, payload?.message || 'Request failed.')
                            || payload?.message
                            || 'Request failed.'
                        );
                    }

                    return payload;
                }

                if (setupCompleteButton) {
                    setupCompleteButton.addEventListener('click', async () => {
                        const endpoint = String(setupCompleteButton.getAttribute('data-endpoint') || '').trim();
                        if (endpoint === '') {
                            return;
                        }

                        setupCompleteButton.disabled = true;
                        setSetupStatus('Marking setup complete…');

                        try {
                            await postJson(endpoint);
                            setSetupStatus('Messaging setup marked complete. Reloading…', 'success');
                            window.setTimeout(() => window.location.reload(), 500);
                        } catch (error) {
                            const message = error instanceof Error ? error.message : 'Could not mark setup complete.';
                            setSetupStatus(message, 'error');
                            setupCompleteButton.disabled = false;
                        }
                    });
                }

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
