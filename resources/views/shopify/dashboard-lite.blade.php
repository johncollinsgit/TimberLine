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
        /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrls */
        $embeddedUrls = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
        $embeddedContext = $embeddedUrls->contextQuery(
            request(),
            filled($host) ? (string) $host : null
        );

        $embeddedUrl = static fn (string $url): string => $embeddedUrls->append($url, $embeddedContext);

        // NOTE: Do NOT append Shopify signed query params to the "full" link.
        // Adding extra params breaks the original HMAC signature. Instead, rely on
        // the server-stored page context established on the initial signed entry.
        $fullDashboardHref = route('shopify.app', ['full' => 1], false);

        $liteEndpoint = route('shopify.app.api.dashboard-lite', [], false);
    @endphp

    <style>
        .sf-lite-shell {
            display: grid;
            gap: 16px;
        }

        .sf-lite-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.96);
            padding: 16px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.06);
        }

        .sf-lite-card h2 {
            margin: 0;
            font-size: 1rem;
            color: #0f172a;
        }

        .sf-lite-card p {
            margin: 6px 0 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.62);
            line-height: 1.45;
        }

        .sf-lite-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }

        .sf-lite-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            border-radius: 10px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: #fff;
            color: #0f172a;
            text-decoration: none;
            font-size: 12px;
            font-weight: 650;
            padding: 0 12px;
            white-space: nowrap;
        }

        .sf-lite-button--primary {
            border-color: #0f766e;
            background: rgba(15, 118, 110, 0.12);
            color: #115e59;
        }

        .sf-lite-meta {
            margin-top: 12px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.58);
        }

        .sf-lite-meta a {
            color: #0f766e;
            font-weight: 650;
            text-decoration: none;
        }

        .sf-lite-meta a:hover {
            text-decoration: underline;
        }

        .sf-lite-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .sf-lite-range {
            display: inline-flex;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: rgba(255, 255, 255, 0.9);
            border-radius: 999px;
            overflow: hidden;
        }

        .sf-lite-range button {
            appearance: none;
            border: 0;
            background: transparent;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 650;
            color: rgba(15, 23, 42, 0.72);
            cursor: pointer;
        }

        .sf-lite-range button[aria-pressed="true"] {
            background: rgba(15, 118, 110, 0.12);
            color: #115e59;
        }

        .sf-lite-updated {
            font-size: 12px;
            color: rgba(15, 23, 42, 0.58);
            white-space: nowrap;
        }

        .sf-lite-kpis {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            margin-top: 14px;
        }

        .sf-lite-kpi {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 14px;
            background: rgba(248, 250, 252, 0.72);
            padding: 12px;
            min-height: 88px;
        }

        .sf-lite-kpi-label {
            margin: 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.58);
        }

        .sf-lite-kpi-value {
            margin: 8px 0 0;
            font-size: 1.08rem;
            font-weight: 760;
            color: #0f172a;
            letter-spacing: -0.01em;
        }

        .sf-lite-kpi-meta {
            margin: 6px 0 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.52);
        }

        .sf-lite-grid {
            display: grid;
            gap: 16px;
            grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
            align-items: start;
        }

        .sf-lite-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        .sf-lite-table th,
        .sf-lite-table td {
            text-align: left;
            padding: 10px 10px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            vertical-align: top;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.74);
        }

        .sf-lite-table th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(15, 23, 42, 0.52);
            font-weight: 700;
        }

        .sf-lite-table strong {
            display: block;
            color: #0f172a;
            font-weight: 700;
            font-size: 12.5px;
            margin-bottom: 2px;
        }

        .sf-lite-muted {
            color: rgba(15, 23, 42, 0.52);
        }

        .sf-lite-empty {
            margin-top: 12px;
            border: 1px dashed rgba(15, 23, 42, 0.18);
            border-radius: 14px;
            background: rgba(248, 250, 252, 0.72);
            padding: 14px;
            color: rgba(15, 23, 42, 0.62);
            font-size: 13px;
        }

        .sf-lite-loading {
            opacity: 0.72;
        }

        @media (max-width: 1100px) {
            .sf-lite-kpis {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .sf-lite-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .sf-lite-kpis {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>

    <section
        class="sf-lite-shell"
        aria-label="Dashboard (Lite)"
        data-dashboard-lite
        data-dashboard-lite-endpoint="{{ $liteEndpoint }}"
        data-dashboard-lite-full-href="{{ $fullDashboardHref }}"
    >
        <article class="sf-lite-card">
            <h2>Dashboard</h2>
            <p>Fast loyalty snapshot for recent program activity.</p>

            <div class="sf-lite-toolbar">
                <div class="sf-lite-range" role="group" aria-label="Timeframe">
                    <button type="button" data-range="today" aria-pressed="false">Today</button>
                    <button type="button" data-range="7d" aria-pressed="true">7d</button>
                    <button type="button" data-range="30d" aria-pressed="false">30d</button>
                </div>

                <div class="sf-lite-updated" data-updated>Updated just now</div>
            </div>

            <div class="sf-lite-kpis" aria-label="Key metrics">
                <div class="sf-lite-kpi">
                    <p class="sf-lite-kpi-label">Customers purchased</p>
                    <p class="sf-lite-kpi-value" data-kpi="customersPurchased">—</p>
                    <p class="sf-lite-kpi-meta sf-lite-muted">Reward-aware customers</p>
                </div>
                <div class="sf-lite-kpi">
                    <p class="sf-lite-kpi-label">Purchases</p>
                    <p class="sf-lite-kpi-value" data-kpi="purchaseCount">—</p>
                    <p class="sf-lite-kpi-meta sf-lite-muted">Linked to loyalty profiles</p>
                </div>
                <div class="sf-lite-kpi">
                    <p class="sf-lite-kpi-label">Returning signal</p>
                    <p class="sf-lite-kpi-value" data-kpi="returningRatePct">—</p>
                    <p class="sf-lite-kpi-meta sf-lite-muted"><span data-kpi="returningCustomers">—</span> customers</p>
                </div>
                <div class="sf-lite-kpi">
                    <p class="sf-lite-kpi-label">Candle Cash earned</p>
                    <p class="sf-lite-kpi-value" data-kpi="candleCashEarned">—</p>
                    <p class="sf-lite-kpi-meta sf-lite-muted">Selected window</p>
                </div>
                <div class="sf-lite-kpi">
                    <p class="sf-lite-kpi-label">Candle Cash redeemed</p>
                    <p class="sf-lite-kpi-value" data-kpi="candleCashRedeemed">—</p>
                    <p class="sf-lite-kpi-meta sf-lite-muted">Selected window</p>
                </div>
                <div class="sf-lite-kpi">
                    <p class="sf-lite-kpi-label">Outstanding balance</p>
                    <p class="sf-lite-kpi-value" data-kpi="outstandingBalance">—</p>
                    <p class="sf-lite-kpi-meta sf-lite-muted">Current pool</p>
                </div>
            </div>

            <div class="sf-lite-meta">
                Open reward credit codes: <strong style="display:inline" data-kpi="openRewardCodes">$0.00</strong>
                <span class="sf-lite-muted">(issued, unredeemed; expiring)</span>
                · <a href="{{ $fullDashboardHref }}">Open full analytics</a>
            </div>
        </article>

        <div class="sf-lite-grid">
            <article class="sf-lite-card" aria-label="Recent customer purchase activity">
                <h2>Recent customer purchase activity</h2>
                <p>High-signal loyalty movement from recent reward-aware purchases.</p>

                <div data-activity>
                    <div class="sf-lite-empty">Loading recent activity…</div>
                </div>
            </article>

            <article class="sf-lite-card" aria-label="Program movement summary">
                <h2>Program movement</h2>
                <p>Total movement in the selected window.</p>

                <table class="sf-lite-table" aria-label="Movement summary">
                    <tbody>
                        <tr>
                            <th scope="row">Earned</th>
                            <td data-move="earned">—</td>
                        </tr>
                        <tr>
                            <th scope="row">Redeemed</th>
                            <td data-move="redeemed">—</td>
                        </tr>
                        <tr>
                            <th scope="row">Net</th>
                            <td data-move="net">—</td>
                        </tr>
                    </tbody>
                </table>

                <div class="sf-lite-actions" role="navigation" aria-label="Quick links">
                    <a class="sf-lite-button sf-lite-button--primary" href="{{ $embeddedUrl(route('shopify.app.customers.manage', [], false)) }}">Customers</a>
                    <a class="sf-lite-button" href="{{ $embeddedUrl(route('shopify.app.rewards', [], false)) }}">Rewards</a>
                    <a class="sf-lite-button" href="{{ $embeddedUrl(route('shopify.app.messaging', [], false)) }}">Messages</a>
                    <a class="sf-lite-button" href="{{ $embeddedUrl(route('shopify.app.settings', [], false)) }}">Settings</a>
                </div>
            </article>
        </div>
    </section>

    <script>
        (() => {
            const root = document.querySelector('[data-dashboard-lite]');
            if (!root) return;

            const endpoint = root.dataset.dashboardLiteEndpoint || '';
            const rangeKey = 'fb.dashboard_lite.range';
            const updatedNode = root.querySelector('[data-updated]');

            const kpiNodes = new Map();
            root.querySelectorAll('[data-kpi]').forEach((node) => {
                kpiNodes.set(node.getAttribute('data-kpi'), node);
            });

            const moveNodes = new Map();
            root.querySelectorAll('[data-move]').forEach((node) => {
                moveNodes.set(node.getAttribute('data-move'), node);
            });

            const activityHost = root.querySelector('[data-activity]');
            const rangeButtons = Array.from(root.querySelectorAll('[data-range]'));

            const state = {
                range: '7d',
                summaryAbort: null,
                activityAbort: null,
            };

            function setRange(nextRange) {
                state.range = nextRange;
                try {
                    window.localStorage.setItem(rangeKey, nextRange);
                } catch (e) {}

                rangeButtons.forEach((btn) => {
                    btn.setAttribute('aria-pressed', btn.dataset.range === nextRange ? 'true' : 'false');
                });
            }

            function timeAgoLabel(iso) {
                if (!iso) return 'Updated just now';
                const then = new Date(iso);
                if (Number.isNaN(then.getTime())) return 'Updated just now';
                const seconds = Math.max(0, Math.floor((Date.now() - then.getTime()) / 1000));
                if (seconds < 10) return 'Updated just now';
                if (seconds < 60) return `Updated ${seconds}s ago`;
                const minutes = Math.floor(seconds / 60);
                if (minutes < 60) return `Updated ${minutes}m ago`;
                const hours = Math.floor(minutes / 60);
                return `Updated ${hours}h ago`;
            }

            function setText(key, value) {
                const node = kpiNodes.get(key);
                if (!node) return;
                node.textContent = value;
            }

            function setMove(key, value) {
                const node = moveNodes.get(key);
                if (!node) return;
                node.textContent = value;
            }

            function setLoading(isLoading) {
                root.classList.toggle('sf-lite-loading', !!isLoading);
            }

            function renderSummary(data) {
                const kpis = (data && data.summary && data.summary.kpis) ? data.summary.kpis : null;
                const movement = (data && data.summary && data.summary.movement) ? data.summary.movement : null;

                if (!kpis) return;

                setText('customersPurchased', String(kpis.customersPurchased ?? 0));
                setText('purchaseCount', String(kpis.purchaseCount ?? 0));
                setText('returningCustomers', String(kpis.returningCustomers ?? 0));
                setText('returningRatePct', `${Number(kpis.returningRatePct ?? 0).toFixed(1)}%`);

                setText('candleCashEarned', (kpis.candleCashEarned && kpis.candleCashEarned.formatted) ? kpis.candleCashEarned.formatted : '$0.00');
                setText('candleCashRedeemed', (kpis.candleCashRedeemed && kpis.candleCashRedeemed.formatted) ? kpis.candleCashRedeemed.formatted : '$0.00');
                setText('outstandingBalance', (kpis.outstandingBalance && kpis.outstandingBalance.formatted) ? kpis.outstandingBalance.formatted : '$0.00');
                setText('openRewardCodes', (kpis.openRewardCodes && kpis.openRewardCodes.formatted) ? kpis.openRewardCodes.formatted : '$0.00');

                if (movement) {
                    setMove('earned', movement.earned && movement.earned.formatted ? movement.earned.formatted : '$0.00');
                    setMove('redeemed', movement.redeemed && movement.redeemed.formatted ? movement.redeemed.formatted : '$0.00');
                    setMove('net', movement.net && movement.net.formatted ? movement.net.formatted : '$0.00');
                }

                if (updatedNode && data.meta && data.meta.generatedAt) {
                    updatedNode.textContent = timeAgoLabel(data.meta.generatedAt);
                }
            }

            function renderActivity(data) {
                if (!activityHost) return;

                const activity = data && data.activity ? data.activity : null;
                const rows = activity && Array.isArray(activity.rows) ? activity.rows : [];

                if (!rows || rows.length === 0) {
                    activityHost.innerHTML = '<div class="sf-lite-empty">No recent loyalty-linked purchases in this window.</div>';
                    return;
                }

                const table = document.createElement('table');
                table.className = 'sf-lite-table';
                table.innerHTML = `
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Order</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Earned</th>
                            <th>Redeemed</th>
                            <th>Balance</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                `;

                const tbody = table.querySelector('tbody');

                const fmtDate = (iso) => {
                    if (!iso) return '—';
                    const d = new Date(iso);
                    if (Number.isNaN(d.getTime())) return '—';
                    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
                };

                const fmtMoney = (amount, currencyCode) => {
                    if (typeof amount !== 'number') return '—';
                    try {
                        return new Intl.NumberFormat(undefined, { style: 'currency', currency: currencyCode || 'USD' }).format(amount);
                    } catch (e) {
                        return '$' + amount.toFixed(2);
                    }
                };

                rows.forEach((row) => {
                    const customer = row.customer || {};
                    const order = row.order || {};
                    const orderTotal = (order.total || {});
                    const cc = row.candleCash || {};

                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>
                            <strong>${(customer.name || customer.email || 'Customer #' + (customer.id || '—'))}</strong>
                            <span class="sf-lite-muted">${customer.email ? customer.email : ''}</span>
                        </td>
                        <td>
                            <strong>${order.label || ('Order #' + (order.id || '—'))}</strong>
                            <span class="sf-lite-muted">#${order.id || '—'}</span>
                        </td>
                        <td>${fmtDate(order.orderedAt)}</td>
                        <td>${fmtMoney(orderTotal.amount, orderTotal.currencyCode)}</td>
                        <td>${cc.earnedWindow && cc.earnedWindow.formatted ? cc.earnedWindow.formatted : '$0.00'}</td>
                        <td>${cc.redeemedThisOrder && cc.redeemedThisOrder.formatted ? cc.redeemedThisOrder.formatted : '$0.00'}</td>
                        <td>${cc.balance && cc.balance.formatted ? cc.balance.formatted : '$0.00'}</td>
                    `;
                    tbody.appendChild(tr);
                });

                activityHost.innerHTML = '';
                activityHost.appendChild(table);
            }

            async function fetchLite(section) {
                if (!endpoint) {
                    return { ok: false };
                }

                const controller = new AbortController();
                if (section === 'summary') {
                    if (state.summaryAbort) state.summaryAbort.abort();
                    state.summaryAbort = controller;
                } else {
                    if (state.activityAbort) state.activityAbort.abort();
                    state.activityAbort = controller;
                }

                const headers = await window.ForestryEmbeddedApp.resolveEmbeddedAuthHeaders({ includeJsonContentType: false });
                const url = `${endpoint}?range=${encodeURIComponent(state.range)}&section=${encodeURIComponent(section)}&limit=20`;
                const res = await fetch(url, { method: 'GET', headers, signal: controller.signal, credentials: 'same-origin' });
                const json = await res.json().catch(() => null);
                return (json && json.ok && json.data) ? json.data : null;
            }

            async function refresh() {
                setLoading(true);

                try {
                    const summary = await fetchLite('summary');
                    if (summary) renderSummary(summary);
                } catch (e) {
                } finally {
                    setLoading(false);
                }

                if (activityHost) {
                    activityHost.innerHTML = '<div class="sf-lite-empty">Loading recent activity…</div>';
                }

                try {
                    const activity = await fetchLite('activity');
                    if (activity) renderActivity(activity);
                } catch (e) {
                    if (activityHost) {
                        activityHost.innerHTML = '<div class="sf-lite-empty">Unable to load recent activity. Reload from Shopify Admin.</div>';
                    }
                }
            }

            try {
                const stored = window.localStorage.getItem(rangeKey);
                if (stored === 'today' || stored === '7d' || stored === '30d') {
                    setRange(stored);
                } else {
                    setRange('7d');
                }
            } catch (e) {
                setRange('7d');
            }

            rangeButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const next = btn.dataset.range;
                    if (!next || next === state.range) return;
                    setRange(next);
                    refresh();
                }, { passive: true });
            });

            refresh();
        })();
    </script>
</x-shopify-embedded-shell>
