@extends('shopify.rewards-layout')

@section('rewards-content')
    <style>
        .birthday-analytics-root {
            display: grid;
            gap: 16px;
        }

        .birthday-analytics-panel,
        .birthday-analytics-card {
            border-radius: 18px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.05);
            padding: 18px;
        }

        .birthday-analytics-heading {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }

        .birthday-analytics-heading h2 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
        }

        .birthday-analytics-heading p {
            margin: 8px 0 0;
            font-size: 14px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.72);
            max-width: 760px;
        }

        .birthday-analytics-filters {
            margin-top: 14px;
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(8, minmax(0, 1fr));
        }

        .birthday-analytics-field {
            display: grid;
            gap: 6px;
        }

        .birthday-analytics-field label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.52);
        }

        .birthday-analytics-field input,
        .birthday-analytics-field select {
            min-height: 40px;
            border-radius: 10px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            padding: 8px 10px;
            font-size: 13px;
            color: #0f172a;
            background: #ffffff;
        }

        .birthday-analytics-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: flex-end;
        }

        .birthday-analytics-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #ffffff;
            padding: 0 14px;
            font-size: 12px;
            font-weight: 700;
            color: rgba(15, 23, 42, 0.82);
            cursor: pointer;
        }

        .birthday-analytics-button--primary {
            border-color: rgba(4, 120, 87, 0.24);
            background: rgba(4, 120, 87, 0.12);
            color: #065f46;
        }

        .birthday-analytics-button:disabled {
            opacity: 0.58;
            cursor: not-allowed;
        }

        .birthday-analytics-alert {
            margin-top: 12px;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            padding: 10px 12px;
            font-size: 13px;
            line-height: 1.5;
            color: rgba(15, 23, 42, 0.74);
            background: rgba(248, 250, 252, 0.95);
        }

        .birthday-analytics-alert[hidden] {
            display: none;
        }

        .birthday-analytics-alert[data-tone="error"] {
            border-color: rgba(180, 35, 24, 0.25);
            color: #b42318;
            background: rgba(180, 35, 24, 0.08);
        }

        .birthday-analytics-alert[data-tone="success"] {
            border-color: rgba(4, 120, 87, 0.26);
            color: #047857;
            background: rgba(4, 120, 87, 0.12);
        }

        .birthday-analytics-kpis {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(6, minmax(0, 1fr));
        }

        .birthday-analytics-kpi {
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.95);
            padding: 14px;
        }

        .birthday-analytics-kpi span {
            display: block;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.5);
        }

        .birthday-analytics-kpi strong {
            display: block;
            margin-top: 8px;
            font-size: 1.15rem;
            color: #0f172a;
        }

        .birthday-analytics-kpi small {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.62);
            line-height: 1.45;
        }

        .birthday-analytics-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .birthday-analytics-trend-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .birthday-analytics-card h3 {
            margin: 0;
            font-size: 1rem;
            color: #0f172a;
        }

        .birthday-analytics-card p {
            margin: 8px 0 0;
            font-size: 13px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.67);
        }

        .birthday-analytics-funnel {
            margin-top: 12px;
            display: grid;
            gap: 8px;
        }

        .birthday-analytics-funnel-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border-radius: 10px;
            background: rgba(248, 250, 252, 0.95);
            border: 1px solid rgba(15, 23, 42, 0.07);
            padding: 10px 12px;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.78);
        }

        .birthday-analytics-list {
            margin-top: 12px;
            display: grid;
            gap: 8px;
        }

        .birthday-analytics-list-row {
            border-radius: 10px;
            border: 1px solid rgba(15, 23, 42, 0.07);
            background: rgba(248, 250, 252, 0.95);
            padding: 10px 12px;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.78);
            display: flex;
            justify-content: space-between;
            gap: 8px;
            align-items: center;
        }

        .birthday-analytics-table {
            margin-top: 12px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid rgba(15, 23, 42, 0.07);
        }

        .birthday-analytics-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .birthday-analytics-table th,
        .birthday-analytics-table td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
            font-size: 12px;
            text-align: left;
            color: rgba(15, 23, 42, 0.72);
        }

        .birthday-analytics-table th {
            background: rgba(248, 250, 252, 0.97);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.48);
        }

        .birthday-analytics-notes {
            margin-top: 12px;
            display: grid;
            gap: 8px;
        }

        .birthday-analytics-chart {
            margin-top: 12px;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.95);
            padding: 10px;
        }

        .birthday-analytics-chart svg {
            width: 100%;
            height: 200px;
            display: block;
        }

        .birthday-analytics-chart-legend {
            margin-top: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px 12px;
        }

        .birthday-analytics-chart-legend span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.72);
        }

        .birthday-analytics-chart-legend i {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            display: inline-block;
        }

        .birthday-analytics-chart-note {
            margin-top: 8px;
            font-size: 12px;
            line-height: 1.45;
            color: rgba(15, 23, 42, 0.62);
        }

        .birthday-analytics-note {
            border-radius: 10px;
            border: 1px dashed rgba(15, 23, 42, 0.14);
            background: rgba(248, 250, 252, 0.75);
            padding: 10px 12px;
            font-size: 13px;
            line-height: 1.5;
            color: rgba(15, 23, 42, 0.72);
        }

        .birthday-analytics-empty {
            margin-top: 12px;
            border-radius: 12px;
            border: 1px dashed rgba(15, 23, 42, 0.15);
            background: rgba(248, 250, 252, 0.8);
            padding: 18px;
            font-size: 14px;
            color: rgba(15, 23, 42, 0.7);
            line-height: 1.6;
        }

        .birthday-analytics-skeleton {
            border-radius: 14px;
            min-height: 76px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: linear-gradient(90deg, rgba(241, 245, 249, 0.85), rgba(226, 232, 240, 0.55), rgba(241, 245, 249, 0.85));
            background-size: 220% 100%;
            animation: birthday-analytics-shimmer 1.4s ease infinite;
        }

        @keyframes birthday-analytics-shimmer {
            0% {
                background-position: 100% 50%;
            }
            100% {
                background-position: 0 50%;
            }
        }

        @media (max-width: 1060px) {
            .birthday-analytics-kpis {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .birthday-analytics-filters {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .birthday-analytics-grid,
            .birthday-analytics-trend-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 700px) {
            .birthday-analytics-kpis {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .birthday-analytics-filters {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>

    <section class="birthday-analytics-root" id="shopify-birthday-analytics">
        <script type="application/json" id="birthday-analytics-bootstrap">@json($birthdayAnalyticsBootstrap ?? [])</script>

        <div class="birthday-analytics-panel">
            <div class="birthday-analytics-heading">
                <div>
                    <h2>Birthday Email Analytics</h2>
                    <p>
                        Track the birthday funnel from reward issuance to delivery engagement and redemption revenue using canonical delivery records.
                    </p>
                </div>
            </div>

            <form class="birthday-analytics-filters" id="birthday-analytics-filters">
                <div class="birthday-analytics-field">
                    <label for="birthday-date-from">Start Date</label>
                    <input id="birthday-date-from" name="date_from" type="date">
                </div>
                <div class="birthday-analytics-field">
                    <label for="birthday-date-to">End Date</label>
                    <input id="birthday-date-to" name="date_to" type="date">
                </div>
                <div class="birthday-analytics-field">
                    <label for="birthday-provider">Provider</label>
                    <select id="birthday-provider" name="provider">
                        <option value="">All providers</option>
                    </select>
                </div>
                <div class="birthday-analytics-field">
                    <label for="birthday-provider-resolution-source">Resolution Source</label>
                    <select id="birthday-provider-resolution-source" name="provider_resolution_source">
                        <option value="">All resolution paths</option>
                    </select>
                </div>
                <div class="birthday-analytics-field">
                    <label for="birthday-provider-readiness-status">Readiness Status</label>
                    <select id="birthday-provider-readiness-status" name="provider_readiness_status">
                        <option value="">All readiness states</option>
                    </select>
                </div>
                <div class="birthday-analytics-field">
                    <label for="birthday-template">Template</label>
                    <select id="birthday-template" name="template_key">
                        <option value="">All templates</option>
                    </select>
                </div>
                <div class="birthday-analytics-field">
                    <label for="birthday-status">Delivery Status</label>
                    <select id="birthday-status" name="status">
                        <option value="all">All statuses</option>
                        <option value="attempted">attempted</option>
                        <option value="sent">sent</option>
                        <option value="delivered">delivered</option>
                        <option value="opened">opened</option>
                        <option value="clicked">clicked</option>
                        <option value="failed">failed</option>
                        <option value="bounced">bounced</option>
                        <option value="unsupported">unsupported</option>
                    </select>
                </div>
                <div class="birthday-analytics-field">
                    <label for="birthday-comparison-mode">Compare By</label>
                    <select id="birthday-comparison-mode" name="comparison_mode">
                        <option value="template">template</option>
                        <option value="provider">provider</option>
                        <option value="period">period</option>
                    </select>
                </div>
                <div class="birthday-analytics-field" data-period-view hidden>
                    <label for="birthday-period-view">Period View</label>
                    <select id="birthday-period-view" name="period_view">
                        <option value="raw">Raw totals</option>
                        <option value="per_day">Per-day normalized</option>
                    </select>
                </div>
                <div class="birthday-analytics-field" data-compare-range hidden>
                    <label for="birthday-compare-from">Compare Start</label>
                    <input id="birthday-compare-from" name="compare_from" type="date">
                </div>
                <div class="birthday-analytics-field" data-compare-range hidden>
                    <label for="birthday-compare-to">Compare End</label>
                    <input id="birthday-compare-to" name="compare_to" type="date">
                </div>
                <div class="birthday-analytics-actions">
                    <button type="submit" class="birthday-analytics-button birthday-analytics-button--primary" id="birthday-apply-filters">
                        Apply Filters
                    </button>
                    <button type="button" class="birthday-analytics-button" id="birthday-reset-filters">
                        Reset
                    </button>
                    <button type="button" class="birthday-analytics-button" id="birthday-export-filters">
                        Export CSV
                    </button>
                </div>
            </form>

            <div class="birthday-analytics-alert" id="birthday-analytics-alert" hidden></div>
        </div>

        <div class="birthday-analytics-kpis" id="birthday-analytics-kpis"></div>

        <div class="birthday-analytics-grid birthday-analytics-trend-grid">
            <section class="birthday-analytics-card">
                <h3>Sends Over Time</h3>
                <p>Daily attempted, sent, and failed birthday sends for the selected tenant and filters.</p>
                <div class="birthday-analytics-chart" id="birthday-analytics-trend-sends"></div>
            </section>

            <section class="birthday-analytics-card">
                <h3>Engagement Over Time</h3>
                <p>Daily delivered, opened, and clicked counts from canonical provider/webhook events.</p>
                <div class="birthday-analytics-chart" id="birthday-analytics-trend-engagement"></div>
            </section>

            <section class="birthday-analytics-card">
                <h3>Redemption & Revenue Over Time</h3>
                <p>Daily coupon redemptions and attributed birthday revenue for this filtered cohort.</p>
                <div class="birthday-analytics-chart" id="birthday-analytics-trend-redemption"></div>
            </section>
        </div>

        <div class="birthday-analytics-grid">
            <section class="birthday-analytics-card">
                <h3>Birthday Funnel</h3>
                <p>Issued to redeemed progression for the selected tenant and filters.</p>
                <div class="birthday-analytics-funnel" id="birthday-analytics-funnel"></div>
            </section>

            <section class="birthday-analytics-card">
                <h3>Status Breakdown</h3>
                <p>Normalized delivery states across attempted birthday sends.</p>
                <div class="birthday-analytics-list" id="birthday-analytics-statuses"></div>
            </section>
        </div>

        <div class="birthday-analytics-grid">
            <section class="birthday-analytics-card">
                <h3>Provider Breakdown</h3>
                <p>Attempted, sent, engagement, and failure totals by provider.</p>
                <div class="birthday-analytics-table" id="birthday-analytics-providers"></div>
            </section>

            <section class="birthday-analytics-card">
                <h3>Top Failure Reasons</h3>
                <p>Most common failure outcomes from canonical delivery metadata and webhook states.</p>
                <div class="birthday-analytics-list" id="birthday-analytics-failures"></div>
            </section>
        </div>

        <div class="birthday-analytics-grid">
            <section class="birthday-analytics-card">
                <h3>Provider Resolution Context</h3>
                <p>Tenant-configured vs fallback vs unresolved delivery attempts from canonical provider context stamps.</p>
                <div class="birthday-analytics-table" id="birthday-analytics-provider-resolution"></div>
            </section>

            <section class="birthday-analytics-card">
                <h3>Provider Readiness Context</h3>
                <p>Readiness status captured at attempt time for each birthday delivery row.</p>
                <div class="birthday-analytics-table" id="birthday-analytics-provider-readiness"></div>
            </section>
        </div>

        <section class="birthday-analytics-card">
            <h3>Failure Reasons by Resolution Path</h3>
            <p>Segmented failure reasons so fallback and unsupported paths stay visible without raw metadata inspection.</p>
            <div class="birthday-analytics-table" id="birthday-analytics-failures-by-resolution"></div>
        </section>

        <section class="birthday-analytics-card">
            <h3>Campaign Comparison</h3>
            <p>Compare birthday performance by template or provider using canonical sends, attribution links, and redemption outcomes.</p>
            <div class="birthday-analytics-notes" id="birthday-analytics-comparison-recommendation"></div>
            <div class="birthday-analytics-table" id="birthday-analytics-comparison"></div>
        </section>

        <section class="birthday-analytics-card">
            <h3>Attribution Notes</h3>
            <p>How delivery rows are linked to reward issuances and redemption outcomes.</p>
            <div class="birthday-analytics-notes" id="birthday-analytics-notes"></div>
        </section>
    </section>

    <script>
        (() => {
            const root = document.getElementById("shopify-birthday-analytics");
            if (!root) {
                return;
            }

            const bootstrapNode = document.getElementById("birthday-analytics-bootstrap");
            let bootstrap = {};

            try {
                bootstrap = JSON.parse(bootstrapNode?.textContent || "{}");
            } catch (error) {
                bootstrap = {};
            }

            const filtersForm = document.getElementById("birthday-analytics-filters");
            const alertNode = document.getElementById("birthday-analytics-alert");
            const kpiNode = document.getElementById("birthday-analytics-kpis");
            const funnelNode = document.getElementById("birthday-analytics-funnel");
            const statusesNode = document.getElementById("birthday-analytics-statuses");
            const providersNode = document.getElementById("birthday-analytics-providers");
            const failuresNode = document.getElementById("birthday-analytics-failures");
            const providerResolutionNode = document.getElementById("birthday-analytics-provider-resolution");
            const providerReadinessNode = document.getElementById("birthday-analytics-provider-readiness");
            const failureByResolutionNode = document.getElementById("birthday-analytics-failures-by-resolution");
            const notesNode = document.getElementById("birthday-analytics-notes");
            const comparisonNode = document.getElementById("birthday-analytics-comparison");
            const comparisonRecommendationNode = document.getElementById("birthday-analytics-comparison-recommendation");
            const sendsTrendNode = document.getElementById("birthday-analytics-trend-sends");
            const engagementTrendNode = document.getElementById("birthday-analytics-trend-engagement");
            const redemptionTrendNode = document.getElementById("birthday-analytics-trend-redemption");
            const applyButton = document.getElementById("birthday-apply-filters");
            const resetButton = document.getElementById("birthday-reset-filters");
            const exportButton = document.getElementById("birthday-export-filters");

            const dateFromInput = document.getElementById("birthday-date-from");
            const dateToInput = document.getElementById("birthday-date-to");
            const providerSelect = document.getElementById("birthday-provider");
            const providerResolutionSourceSelect = document.getElementById("birthday-provider-resolution-source");
            const providerReadinessStatusSelect = document.getElementById("birthday-provider-readiness-status");
            const templateSelect = document.getElementById("birthday-template");
            const statusSelect = document.getElementById("birthday-status");
            const comparisonModeSelect = document.getElementById("birthday-comparison-mode");
            const periodViewSelect = document.getElementById("birthday-period-view");
            const compareFromInput = document.getElementById("birthday-compare-from");
            const compareToInput = document.getElementById("birthday-compare-to");
            const compareRangeFields = Array.from(root.querySelectorAll("[data-compare-range]"));
            const periodViewFields = Array.from(root.querySelectorAll("[data-period-view]"));

            const state = {
                loading: false,
                exporting: false,
                data: null,
            };

            function escapeHtml(value) {
                return String(value ?? "")
                    .replaceAll("&", "&amp;")
                    .replaceAll("<", "&lt;")
                    .replaceAll(">", "&gt;")
                    .replaceAll('"', "&quot;")
                    .replaceAll("'", "&#039;");
            }

            function formatNumber(value) {
                return new Intl.NumberFormat("en-US").format(Number(value || 0));
            }

            function formatMoney(value) {
                return new Intl.NumberFormat("en-US", {
                    style: "currency",
                    currency: "USD",
                    maximumFractionDigits: 2,
                }).format(Number(value || 0));
            }

            function formatPercent(value) {
                return `${Number(value || 0).toFixed(2)}%`;
            }

            function formatDecimal(value) {
                return new Intl.NumberFormat("en-US", {
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 2,
                }).format(Number(value || 0));
            }

            function formatDateLabel(value) {
                const raw = String(value || "").trim();
                if (raw === "") {
                    return "";
                }

                const parsed = new Date(`${raw}T00:00:00`);
                if (Number.isNaN(parsed.getTime())) {
                    return raw;
                }

                return parsed.toLocaleDateString("en-US", {
                    month: "short",
                    day: "numeric",
                });
            }

            function setAlert(message, tone = "neutral") {
                if (!message) {
                    alertNode.hidden = true;
                    alertNode.textContent = "";
                    alertNode.removeAttribute("data-tone");
                    return;
                }

                alertNode.hidden = false;
                alertNode.dataset.tone = tone;
                alertNode.textContent = String(message);
            }

            function authErrorMessage(status, fallback) {
                const messages = {
                    missing_api_auth: "Shopify Admin verification is unavailable. Reload this page from Shopify Admin and try again.",
                    invalid_session_token: "Shopify Admin verification failed. Reload this page from Shopify Admin and try again.",
                    expired_session_token: "Your Shopify Admin session expired. Reload this page from Shopify Admin and try again.",
                };

                return messages[status] || fallback || "Request failed.";
            }

            async function resolveEmbeddedAuthHeaders() {
                if (!window.shopify || typeof window.shopify.idToken !== "function") {
                    throw new Error(authErrorMessage("missing_api_auth"));
                }

                let token = null;

                try {
                    token = await Promise.race([
                        Promise.resolve(window.shopify.idToken()),
                        new Promise((resolve) => window.setTimeout(() => resolve(null), 1500)),
                    ]);
                } catch (error) {
                    throw new Error(authErrorMessage("invalid_session_token"));
                }

                if (typeof token !== "string" || token.trim() === "") {
                    throw new Error(authErrorMessage("missing_api_auth"));
                }

                return {
                    "Accept": "application/json",
                    "Authorization": `Bearer ${token.trim()}`,
                };
            }

            async function fetchJson(url) {
                const headers = await resolveEmbeddedAuthHeaders();
                const response = await fetch(url, {
                    method: "GET",
                    headers,
                    credentials: "same-origin",
                });

                const payload = await response.json().catch(() => ({
                    ok: false,
                    message: "Unexpected response from Backstage.",
                }));

                if (!response.ok || payload?.ok === false) {
                    const error = new Error(authErrorMessage(payload?.status, payload?.message || "Birthday analytics request failed."));
                    error.payload = payload;
                    throw error;
                }

                return payload;
            }

            async function fetchCsv(url) {
                const headers = await resolveEmbeddedAuthHeaders();
                const response = await fetch(url, {
                    method: "GET",
                    headers,
                    credentials: "same-origin",
                });

                if (!response.ok) {
                    const payload = await response.json().catch(() => ({
                        ok: false,
                        message: "Birthday analytics export failed.",
                    }));
                    const message = authErrorMessage(payload?.status, payload?.message || "Birthday analytics export failed.");
                    const error = new Error(message);
                    error.payload = payload;
                    throw error;
                }

                const blob = await response.blob();
                const disposition = String(response.headers.get("Content-Disposition") || "");
                const match = disposition.match(/filename\\*?=(?:UTF-8''|\"?)([^\";]+)/i);
                const filename = match && match[1]
                    ? decodeURIComponent(match[1].replaceAll('"', '').trim())
                    : "birthday-analytics.csv";

                return { blob, filename };
            }

            function renderLoadingState() {
                const skeleton = '<div class="birthday-analytics-skeleton"></div>';
                kpiNode.innerHTML = skeleton.repeat(6);
                sendsTrendNode.innerHTML = skeleton;
                engagementTrendNode.innerHTML = skeleton;
                redemptionTrendNode.innerHTML = skeleton;
                funnelNode.innerHTML = `<div class="birthday-analytics-empty">Loading funnel metrics...</div>`;
                statusesNode.innerHTML = `<div class="birthday-analytics-empty">Loading status breakdown...</div>`;
                providersNode.innerHTML = `<div class="birthday-analytics-empty">Loading provider breakdown...</div>`;
                failuresNode.innerHTML = `<div class="birthday-analytics-empty">Loading failure reasons...</div>`;
                providerResolutionNode.innerHTML = `<div class="birthday-analytics-empty">Loading provider resolution context...</div>`;
                providerReadinessNode.innerHTML = `<div class="birthday-analytics-empty">Loading provider readiness context...</div>`;
                failureByResolutionNode.innerHTML = `<div class="birthday-analytics-empty">Loading segmented failure reasons...</div>`;
                comparisonRecommendationNode.innerHTML = `<div class="birthday-analytics-empty">Loading comparison guidance...</div>`;
                comparisonNode.innerHTML = `<div class="birthday-analytics-empty">Loading campaign comparison...</div>`;
                notesNode.innerHTML = `<div class="birthday-analytics-empty">Loading attribution notes...</div>`;
            }

            function renderUnavailable(message) {
                const markup = `<div class="birthday-analytics-empty">${escapeHtml(message)}</div>`;
                kpiNode.innerHTML = markup;
                sendsTrendNode.innerHTML = markup;
                engagementTrendNode.innerHTML = markup;
                redemptionTrendNode.innerHTML = markup;
                funnelNode.innerHTML = markup;
                statusesNode.innerHTML = markup;
                providersNode.innerHTML = markup;
                failuresNode.innerHTML = markup;
                providerResolutionNode.innerHTML = markup;
                providerReadinessNode.innerHTML = markup;
                failureByResolutionNode.innerHTML = markup;
                comparisonRecommendationNode.innerHTML = markup;
                comparisonNode.innerHTML = markup;
                notesNode.innerHTML = markup;
            }

            function renderLineChart(container, labels, series, valueFormatter, note = "") {
                if (!container) {
                    return;
                }

                const safeLabels = Array.isArray(labels) ? labels.map((label) => String(label || "")) : [];
                const safeSeries = Array.isArray(series)
                    ? series.map((item) => ({
                        label: String(item?.label || "Series"),
                        color: String(item?.color || "#0f172a"),
                        values: Array.isArray(item?.values) ? item.values.map((value) => Number(value || 0)) : [],
                    }))
                    : [];

                if (safeLabels.length === 0 || safeSeries.length === 0) {
                    container.innerHTML = `<div class="birthday-analytics-empty">No daily trend data is available for these filters.</div>`;
                    return;
                }

                const normalizedSeries = safeSeries.map((entry) => ({
                    ...entry,
                    values: safeLabels.map((_, index) => Number(entry.values[index] || 0)),
                }));

                const allValues = normalizedSeries.flatMap((entry) => entry.values);
                const maxValue = Math.max(1, ...allValues);
                const width = 800;
                const height = 200;
                const paddingTop = 16;
                const paddingRight = 12;
                const paddingBottom = 26;
                const paddingLeft = 42;
                const plotWidth = width - paddingLeft - paddingRight;
                const plotHeight = height - paddingTop - paddingBottom;

                const xPoint = (index) => {
                    if (safeLabels.length <= 1) {
                        return paddingLeft + (plotWidth / 2);
                    }

                    return paddingLeft + (plotWidth / (safeLabels.length - 1)) * index;
                };

                const yPoint = (value) => paddingTop + (plotHeight - ((Number(value || 0) / maxValue) * plotHeight));

                const yTicks = [0, Math.round(maxValue / 2), maxValue];
                const tickLines = yTicks.map((tick) => {
                    const y = yPoint(tick);
                    return `
                        <line x1="${paddingLeft}" y1="${y}" x2="${paddingLeft + plotWidth}" y2="${y}" stroke="rgba(15,23,42,0.12)" stroke-width="1" />
                        <text x="${paddingLeft - 6}" y="${y + 4}" text-anchor="end" font-size="10" fill="rgba(15,23,42,0.55)">${escapeHtml(valueFormatter(tick))}</text>
                    `;
                }).join("");

                const xTicks = [0, Math.floor((safeLabels.length - 1) / 2), safeLabels.length - 1]
                    .filter((value, index, array) => array.indexOf(value) === index)
                    .map((index) => {
                        const x = xPoint(index);
                        return `
                            <text x="${x}" y="${height - 6}" text-anchor="middle" font-size="10" fill="rgba(15,23,42,0.55)">
                                ${escapeHtml(formatDateLabel(safeLabels[index]))}
                            </text>
                        `;
                    })
                    .join("");

                const lines = normalizedSeries.map((entry) => {
                    const path = entry.values.map((value, index) => {
                        const x = xPoint(index);
                        const y = yPoint(value);
                        return `${index === 0 ? "M" : "L"} ${x.toFixed(2)} ${y.toFixed(2)}`;
                    }).join(" ");

                    return `<path d="${path}" fill="none" stroke="${escapeHtml(entry.color)}" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" />`;
                }).join("");

                const latestValues = normalizedSeries.map((entry) => {
                    const latest = Number(entry.values[entry.values.length - 1] || 0);
                    return `
                        <span>
                            <i style="background:${escapeHtml(entry.color)}"></i>
                            ${escapeHtml(entry.label)}: ${escapeHtml(valueFormatter(latest))}
                        </span>
                    `;
                }).join("");

                container.innerHTML = `
                    <svg viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" role="img" aria-label="Birthday analytics trend chart">
                        ${tickLines}
                        ${lines}
                        ${xTicks}
                    </svg>
                    <div class="birthday-analytics-chart-legend">${latestValues}</div>
                    ${note !== "" ? `<div class="birthday-analytics-chart-note">${escapeHtml(note)}</div>` : ""}
                `;
            }

            function renderTrendCharts(trend) {
                const labels = Array.isArray(trend?.labels) ? trend.labels : [];
                const sends = trend?.series?.sends || {};
                const engagement = trend?.series?.engagement || {};
                const redemption = trend?.series?.redemption || {};
                const availabilityNotes = Array.isArray(trend?.availability?.notes) ? trend.availability.notes : [];

                renderLineChart(
                    sendsTrendNode,
                    labels,
                    [
                        { label: "Attempted", color: "#0ea5e9", values: sends.attempted || [] },
                        { label: "Sent", color: "#22c55e", values: sends.sent || [] },
                        { label: "Failed", color: "#ef4444", values: sends.failed || [] },
                    ],
                    (value) => formatNumber(value)
                );

                renderLineChart(
                    engagementTrendNode,
                    labels,
                    [
                        { label: "Delivered", color: "#0284c7", values: engagement.delivered || [] },
                        { label: "Opened", color: "#16a34a", values: engagement.opened || [] },
                        { label: "Clicked", color: "#f97316", values: engagement.clicked || [] },
                    ],
                    (value) => formatNumber(value),
                    availabilityNotes.length > 0 ? availabilityNotes.join(" ") : ""
                );

                renderLineChart(
                    redemptionTrendNode,
                    labels,
                    [
                        { label: "Redeemed", color: "#6366f1", values: redemption.coupons_redeemed || [] },
                        { label: "Revenue", color: "#f59e0b", values: redemption.attributed_revenue || [] },
                    ],
                    (value) => formatMoney(value)
                );
            }

            function renderComparison(data) {
                const comparison = data?.comparison || {};
                const rows = Array.isArray(comparison?.rows) ? comparison.rows : [];
                const summaryRowsRaw = Array.isArray(comparison?.summary_rows) ? comparison.summary_rows : [];
                const summaryRowsNormalized = Array.isArray(comparison?.summary_rows_normalized) ? comparison.summary_rows_normalized : [];
                const recommendation = comparison?.recommendation || {};
                const comparisonNotes = Array.isArray(comparison?.notes) ? comparison.notes : [];
                const normalizationNotes = Array.isArray(comparison?.normalization_notes) ? comparison.normalization_notes : [];
                const guardrails = comparison?.guardrails || {};
                const modeLabel = String(comparison?.mode_label || "Template");
                const mode = String(comparison?.mode || "template");
                const viewMode = String(comparison?.view_mode || "raw");
                const currentPeriod = comparison?.current_period || {};
                const priorPeriod = comparison?.prior_period || {};
                const comparisonPeriod = comparison?.comparison_period || priorPeriod;
                const periodResolutionMode = String(comparison?.period_resolution_mode || "auto_prior_period");
                const customRangeOverride = Boolean(comparison?.custom_range_override);
                const rangeDiagnostics = comparison?.range_diagnostics || {};

                const recommendationLines = [];
                if (recommendation?.message) {
                    recommendationLines.push(String(recommendation.message));
                }
                if (mode === "period" && recommendation?.status === "insufficient_data") {
                    recommendationLines.push(
                        `Insufficient signal for directional period call. Minimum guardrail: ${formatNumber(guardrails.minimum_attempted_for_comparison || 0)} attempted and ${formatNumber(guardrails.minimum_issued_for_comparison || 0)} issued in each period.`
                    );
                } else if (mode !== "period" && recommendation?.status === "insufficient_data") {
                    recommendationLines.push(
                        `No winner declared. Minimum sample guardrail: ${formatNumber(guardrails.minimum_attempted_for_ranking || recommendation.minimum_attempted || 0)} attempts and ${formatNumber(guardrails.minimum_issued_for_ranking || recommendation.minimum_rewards_issued || 0)} linked issuances.`
                    );
                }
                comparisonNotes.forEach((note) => recommendationLines.push(String(note)));

                comparisonRecommendationNode.innerHTML = recommendationLines.length > 0
                    ? recommendationLines.map((note) => `<div class="birthday-analytics-note">${escapeHtml(note)}</div>`).join("")
                    : `<div class="birthday-analytics-empty">No comparison recommendations are available for this filter set.</div>`;

                if (mode === "period") {
                    const summaryRows = viewMode === "per_day" && summaryRowsNormalized.length > 0
                        ? summaryRowsNormalized
                        : summaryRowsRaw;
                    if (summaryRows.length === 0) {
                        comparisonNode.innerHTML = `<div class="birthday-analytics-empty">No period comparison rows are available for the selected filters.</div>`;
                        return;
                    }

                    const currentLabel = String(currentPeriod?.label || "Current period");
                    const priorLabel = String(comparisonPeriod?.label || priorPeriod?.label || "Comparison period");
                    const deltaLabel = (row) => {
                        if (row?.direction === "insufficient_data") {
                            return "insufficient data";
                        }
                        if (row?.direction === "flat") {
                            return "flat";
                        }

                        return String(row?.direction || "");
                    };

                    const formatByType = (format, value, row = {}) => {
                        if (value === null || value === undefined || value === "") {
                            return "n/a";
                        }
                        if (format === "currency") {
                            return formatMoney(value);
                        }
                        if (format === "percent") {
                            return formatPercent(value);
                        }
                        if (viewMode === "per_day" && Boolean(row?.normalized_per_day)) {
                            return formatDecimal(value);
                        }

                        return formatNumber(value);
                    };
                    const viewLabel = viewMode === "per_day" ? "per-day normalized view" : "raw totals view";
                    const diagnosticsLine = [
                        `Resolution mode: ${periodResolutionMode.replaceAll("_", " ")}`,
                        `View mode: ${viewLabel}`,
                        `Custom override: ${customRangeOverride ? "yes" : "no"}`,
                        `Current days: ${formatNumber(rangeDiagnostics?.current_period_days || 0)}`,
                        `Comparison days: ${formatNumber(rangeDiagnostics?.comparison_period_days || 0)}`,
                    ].join(" | ");
                    const mismatchWarning = Boolean(rangeDiagnostics?.range_length_mismatch)
                        ? `<div class="birthday-analytics-note">Comparison ranges differ in length. Interpret period deltas cautiously.</div>`
                        : "";
                    const normalizationMarkup = normalizationNotes.map((note) => `
                        <div class="birthday-analytics-note">${escapeHtml(note)}</div>
                    `).join("");

                    comparisonNode.innerHTML = `
                        <div class="birthday-analytics-note">${escapeHtml(diagnosticsLine)}</div>
                        ${mismatchWarning}
                        ${normalizationMarkup}
                        <table>
                            <thead>
                                <tr>
                                    <th>Metric</th>
                                    <th>${escapeHtml(currentLabel)}</th>
                                    <th>${escapeHtml(priorLabel)}</th>
                                    <th>Absolute Delta</th>
                                    <th>Percent Delta</th>
                                    <th>Direction</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${summaryRows.map((row) => `
                                    <tr>
                                        <td>${escapeHtml(row.label || row.key || "metric")}</td>
                                        <td>${escapeHtml(formatByType(row.format, row.current_value, row))}</td>
                                        <td>${escapeHtml(formatByType(row.format, row.prior_value, row))}</td>
                                        <td>${escapeHtml(formatByType(row.format, row.absolute_delta, row))}</td>
                                        <td>${row.percent_delta === null || row.percent_delta === "" ? "n/a" : escapeHtml(formatPercent(row.percent_delta))}</td>
                                        <td>${escapeHtml(deltaLabel(row))}</td>
                                    </tr>
                                `).join("")}
                            </tbody>
                        </table>
                    `;

                    return;
                }

                if (rows.length === 0) {
                    comparisonNode.innerHTML = `<div class="birthday-analytics-empty">No ${escapeHtml(modeLabel.toLowerCase())} comparison rows are available for the selected filters.</div>`;
                    return;
                }

                comparisonNode.innerHTML = `
                    <table>
                        <thead>
                            <tr>
                                <th>${escapeHtml(modeLabel)}</th>
                                <th>Attempted</th>
                                <th>Sent</th>
                                <th>Delivered</th>
                                <th>Opened</th>
                                <th>Clicked</th>
                                <th>Failed</th>
                                <th>Redeemed</th>
                                <th>Redemption Rate</th>
                                <th>Revenue</th>
                                <th>Revenue / Sent</th>
                                <th>Attempt Share</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows.map((row) => {
                                const rowNotes = Array.isArray(row.notes) ? row.notes.join(" ") : "";
                                return `
                                    <tr>
                                        <td>${escapeHtml(row.group_label || row.group_key || "unknown")}</td>
                                        <td>${escapeHtml(formatNumber(row.birthday_emails_attempted))}</td>
                                        <td>${escapeHtml(formatNumber(row.birthday_emails_sent_successfully))}</td>
                                        <td>${escapeHtml(formatNumber(row.delivered_count))}</td>
                                        <td>${escapeHtml(formatNumber(row.opened_count))}</td>
                                        <td>${escapeHtml(formatNumber(row.clicked_count))}</td>
                                        <td>${escapeHtml(formatNumber(row.birthday_emails_failed))}</td>
                                        <td>${escapeHtml(formatNumber(row.coupons_redeemed))}</td>
                                        <td>${escapeHtml(formatPercent(row.redemption_rate))}</td>
                                        <td>${escapeHtml(formatMoney(row.attributed_revenue))}</td>
                                        <td>${escapeHtml(formatMoney(row.revenue_per_successfully_sent_birthday_email))}</td>
                                        <td>${escapeHtml(formatPercent(row.attempt_share_pct))}</td>
                                        <td>${escapeHtml(rowNotes)}</td>
                                    </tr>
                                `;
                            }).join("")}
                        </tbody>
                    </table>
                `;
            }

            function renderData(data) {
                const metrics = data?.metrics || {};
                const funnel = Array.isArray(data?.funnel) ? data.funnel : [];
                const statusBreakdown = Array.isArray(data?.status_breakdown) ? data.status_breakdown : [];
                const providerBreakdown = Array.isArray(data?.provider_breakdown) ? data.provider_breakdown : [];
                const failures = Array.isArray(data?.top_failure_reasons) ? data.top_failure_reasons : [];
                const providerResolutionBreakdown = Array.isArray(data?.provider_resolution_breakdown) ? data.provider_resolution_breakdown : [];
                const providerReadinessBreakdown = Array.isArray(data?.provider_readiness_breakdown) ? data.provider_readiness_breakdown : [];
                const failureReasonsByResolution = Array.isArray(data?.top_failure_reasons_by_resolution_source) ? data.top_failure_reasons_by_resolution_source : [];
                const notes = Array.isArray(data?.notes) ? data.notes : [];
                const attribution = data?.attribution || {};
                const trend = data?.trend || {};
                const comparison = data?.comparison || {};

                kpiNode.innerHTML = [
                    {
                        label: "Rewards Issued",
                        value: formatNumber(metrics.rewards_issued),
                        detail: "Birthday reward issuances in cohort.",
                    },
                    {
                        label: "Attempted",
                        value: formatNumber(metrics.birthday_emails_attempted),
                        detail: "Canonical birthday send attempts.",
                    },
                    {
                        label: "Sent",
                        value: formatNumber(metrics.birthday_emails_sent_successfully),
                        detail: "Successful provider submits.",
                    },
                    {
                        label: "Failed",
                        value: formatNumber(metrics.birthday_emails_failed),
                        detail: "Includes unsupported providers.",
                    },
                    {
                        label: "Redeemed",
                        value: formatNumber(metrics.coupons_redeemed),
                        detail: `Redemption rate ${formatPercent(metrics.redemption_rate)}.`,
                    },
                    {
                        label: "Revenue",
                        value: formatMoney(metrics.attributed_revenue),
                        detail: `${formatMoney(metrics.revenue_per_successfully_sent_birthday_email)} per successful send.`,
                    },
                ].map((item) => `
                    <article class="birthday-analytics-kpi">
                        <span>${escapeHtml(item.label)}</span>
                        <strong>${escapeHtml(item.value)}</strong>
                        <small>${escapeHtml(item.detail)}</small>
                    </article>
                `).join("");

                funnelNode.innerHTML = funnel.length > 0
                    ? funnel.map((row) => `
                        <div class="birthday-analytics-funnel-row">
                            <span>${escapeHtml(row.label || row.key || "Metric")}</span>
                            <strong>${escapeHtml(formatNumber(row.value))}</strong>
                        </div>
                    `).join("")
                    : `<div class="birthday-analytics-empty">No funnel data is available for the selected filters.</div>`;

                statusesNode.innerHTML = statusBreakdown.length > 0
                    ? statusBreakdown.map((row) => `
                        <div class="birthday-analytics-list-row">
                            <span>${escapeHtml(String(row.status || "unknown").replaceAll("_", " "))}</span>
                            <strong>${escapeHtml(formatNumber(row.count))}</strong>
                        </div>
                    `).join("")
                    : `<div class="birthday-analytics-empty">No normalized delivery statuses are available yet.</div>`;

                providersNode.innerHTML = providerBreakdown.length > 0
                    ? `
                        <table>
                            <thead>
                                <tr>
                                    <th>Provider</th>
                                    <th>Attempted</th>
                                    <th>Sent</th>
                                    <th>Delivered</th>
                                    <th>Opened</th>
                                    <th>Clicked</th>
                                    <th>Failed</th>
                                    <th>Unsupported</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${providerBreakdown.map((row) => `
                                    <tr>
                                        <td>${escapeHtml(row.provider || "unknown")}</td>
                                        <td>${escapeHtml(formatNumber(row.attempted))}</td>
                                        <td>${escapeHtml(formatNumber(row.sent))}</td>
                                        <td>${escapeHtml(formatNumber(row.delivered))}</td>
                                        <td>${escapeHtml(formatNumber(row.opened))}</td>
                                        <td>${escapeHtml(formatNumber(row.clicked))}</td>
                                        <td>${escapeHtml(formatNumber(row.failed))}</td>
                                        <td>${escapeHtml(formatNumber(row.unsupported))}</td>
                                    </tr>
                                `).join("")}
                            </tbody>
                        </table>
                    `
                    : `<div class="birthday-analytics-empty">No provider rows are available for these filters.</div>`;

                failuresNode.innerHTML = failures.length > 0
                    ? failures.map((row) => `
                        <div class="birthday-analytics-list-row">
                            <span>${escapeHtml(row.reason || "unknown_failure")}</span>
                            <strong>${escapeHtml(formatNumber(row.count))}</strong>
                        </div>
                    `).join("")
                    : `<div class="birthday-analytics-empty">No failure reasons recorded for this filter set.</div>`;

                providerResolutionNode.innerHTML = providerResolutionBreakdown.length > 0
                    ? `
                        <table>
                            <thead>
                                <tr>
                                    <th>Resolution Source</th>
                                    <th>Attempted</th>
                                    <th>Sent</th>
                                    <th>Failed</th>
                                    <th>Unsupported</th>
                                    <th>Providers</th>
                                    <th>Legacy Rows</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${providerResolutionBreakdown.map((row) => `
                                    <tr>
                                        <td>${escapeHtml(row.provider_resolution_source_label || row.provider_resolution_source || "unknown")}</td>
                                        <td>${escapeHtml(formatNumber(row.attempted))}</td>
                                        <td>${escapeHtml(formatNumber(row.sent))}</td>
                                        <td>${escapeHtml(formatNumber(row.failed))}</td>
                                        <td>${escapeHtml(formatNumber(row.unsupported))}</td>
                                        <td>${escapeHtml((Array.isArray(row.providers) ? row.providers : []).join(", ") || "—")}</td>
                                        <td>${escapeHtml(formatNumber(row.legacy_context_missing_count || 0))}</td>
                                    </tr>
                                `).join("")}
                            </tbody>
                        </table>
                    `
                    : `<div class="birthday-analytics-empty">No provider resolution context rows are available for this filter set.</div>`;

                providerReadinessNode.innerHTML = providerReadinessBreakdown.length > 0
                    ? `
                        <table>
                            <thead>
                                <tr>
                                    <th>Readiness Status</th>
                                    <th>Attempted</th>
                                    <th>Sent</th>
                                    <th>Failed</th>
                                    <th>Unsupported</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${providerReadinessBreakdown.map((row) => `
                                    <tr>
                                        <td>${escapeHtml(row.provider_readiness_status_label || row.provider_readiness_status || "unknown")}</td>
                                        <td>${escapeHtml(formatNumber(row.attempted))}</td>
                                        <td>${escapeHtml(formatNumber(row.sent))}</td>
                                        <td>${escapeHtml(formatNumber(row.failed))}</td>
                                        <td>${escapeHtml(formatNumber(row.unsupported))}</td>
                                    </tr>
                                `).join("")}
                            </tbody>
                        </table>
                    `
                    : `<div class="birthday-analytics-empty">No provider readiness context rows are available for this filter set.</div>`;

                failureByResolutionNode.innerHTML = failureReasonsByResolution.length > 0
                    ? `
                        <table>
                            <thead>
                                <tr>
                                    <th>Resolution Source</th>
                                    <th>Failure Reason</th>
                                    <th>Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${failureReasonsByResolution.map((row) => `
                                    <tr>
                                        <td>${escapeHtml(row.provider_resolution_source_label || row.provider_resolution_source || "unknown")}</td>
                                        <td>${escapeHtml(row.reason || "unknown_failure")}</td>
                                        <td>${escapeHtml(formatNumber(row.count))}</td>
                                    </tr>
                                `).join("")}
                            </tbody>
                        </table>
                    `
                    : `<div class="birthday-analytics-empty">No segmented failure reasons recorded for this filter set.</div>`;

                const linkStats = attribution.delivery_links || {};
                const attributionNotes = [
                    `Linked delivery rows: ${formatNumber(linkStats.linked_count || 0)}`,
                    `Linked issuances: ${formatNumber(linkStats.linked_issuance_count || 0)}`,
                    `Unlinked delivery rows: ${formatNumber(linkStats.unlinked_count || 0)}`,
                    `Joined redeemed count: ${formatNumber(attribution.joined_redeemed_count || 0)}`,
                    `Joined attributed revenue: ${formatMoney(attribution.joined_attributed_revenue || 0)}`,
                    ...(notes || []),
                ];

                notesNode.innerHTML = attributionNotes.map((note) => `
                    <div class="birthday-analytics-note">${escapeHtml(note)}</div>
                `).join("");

                renderTrendCharts(trend);
                renderComparison({ comparison });

                if (data?.empty) {
                    setAlert("No birthday issuance or delivery activity was found for the selected filters.", "neutral");
                } else {
                    setAlert("Birthday analytics loaded from canonical delivery and issuance records.", "success");
                }
            }

            function setFilterOptions(data) {
                const options = data?.options || {};
                const providers = Array.isArray(options.providers) ? options.providers : [];
                const providerResolutionSources = Array.isArray(options.provider_resolution_sources) ? options.provider_resolution_sources : [];
                const providerReadinessStatuses = Array.isArray(options.provider_readiness_statuses) ? options.provider_readiness_statuses : [];
                const templates = Array.isArray(options.template_keys) ? options.template_keys : [];
                const statuses = Array.isArray(options.statuses) ? options.statuses : ["all"];
                const comparisonModes = Array.isArray(options.comparison_modes) ? options.comparison_modes : ["template", "provider", "period"];
                const periodViews = Array.isArray(options.period_views) ? options.period_views : ["raw", "per_day"];

                const currentProvider = providerSelect.value;
                const currentProviderResolutionSource = providerResolutionSourceSelect.value;
                const currentProviderReadinessStatus = providerReadinessStatusSelect.value;
                const currentTemplate = templateSelect.value;
                const currentStatus = statusSelect.value || "all";
                const currentComparisonMode = comparisonModeSelect.value || "template";
                const currentPeriodView = periodViewSelect?.value || "raw";

                providerSelect.innerHTML = [
                    '<option value="">All providers</option>',
                    ...providers.map((provider) => `<option value="${escapeHtml(provider)}">${escapeHtml(provider)}</option>`),
                ].join("");
                providerSelect.value = providers.includes(currentProvider) ? currentProvider : "";

                providerResolutionSourceSelect.innerHTML = [
                    '<option value="">All resolution paths</option>',
                    ...providerResolutionSources.map((value) => `<option value="${escapeHtml(value)}">${escapeHtml(value.replaceAll("_", " "))}</option>`),
                ].join("");
                providerResolutionSourceSelect.value = providerResolutionSources.includes(currentProviderResolutionSource)
                    ? currentProviderResolutionSource
                    : "";

                providerReadinessStatusSelect.innerHTML = [
                    '<option value="">All readiness states</option>',
                    ...providerReadinessStatuses.map((value) => `<option value="${escapeHtml(value)}">${escapeHtml(value.replaceAll("_", " "))}</option>`),
                ].join("");
                providerReadinessStatusSelect.value = providerReadinessStatuses.includes(currentProviderReadinessStatus)
                    ? currentProviderReadinessStatus
                    : "";

                templateSelect.innerHTML = [
                    '<option value="">All templates</option>',
                    ...templates.map((template) => `<option value="${escapeHtml(template)}">${escapeHtml(template)}</option>`),
                ].join("");
                templateSelect.value = templates.includes(currentTemplate) ? currentTemplate : "";

                statusSelect.innerHTML = statuses.map((status) => `
                    <option value="${escapeHtml(status)}">${escapeHtml(status.replaceAll("_", " "))}</option>
                `).join("");
                statusSelect.value = statuses.includes(currentStatus) ? currentStatus : "all";

                comparisonModeSelect.innerHTML = comparisonModes.map((mode) => `
                    <option value="${escapeHtml(mode)}">${escapeHtml(mode.replaceAll("_", " "))}</option>
                `).join("");
                comparisonModeSelect.value = comparisonModes.includes(currentComparisonMode) ? currentComparisonMode : "template";

                if (periodViewSelect) {
                    periodViewSelect.innerHTML = periodViews.map((mode) => `
                        <option value="${escapeHtml(mode)}">${escapeHtml(mode === "per_day" ? "per day" : mode.replaceAll("_", " "))}</option>
                    `).join("");
                    periodViewSelect.value = periodViews.includes(currentPeriodView) ? currentPeriodView : "raw";
                }
                toggleCompareRangeFields();
            }

            function setBusy(isBusy) {
                applyButton.disabled = isBusy;
                resetButton.disabled = isBusy;
                Array.from(filtersForm.elements).forEach((element) => {
                    element.disabled = isBusy;
                });

                if (exportButton) {
                    exportButton.disabled = isBusy || state.exporting;
                }
            }

            function setExporting(isExporting) {
                state.exporting = isExporting;
                if (!exportButton) {
                    return;
                }

                exportButton.textContent = isExporting ? "Exporting..." : "Export CSV";
                exportButton.disabled = isExporting || state.loading;
            }

            function toggleCompareRangeFields() {
                const isPeriod = String(comparisonModeSelect.value || "template").toLowerCase() === "period";

                compareRangeFields.forEach((field) => {
                    field.hidden = !isPeriod;
                });
                periodViewFields.forEach((field) => {
                    field.hidden = !isPeriod;
                });

                if (!isPeriod) {
                    compareFromInput.value = "";
                    compareToInput.value = "";
                    if (periodViewSelect) {
                        periodViewSelect.value = "raw";
                    }
                }
            }

            function queryFromFilters() {
                const query = new URLSearchParams();

                const dateFrom = String(dateFromInput.value || "").trim();
                const dateTo = String(dateToInput.value || "").trim();
                const provider = String(providerSelect.value || "").trim();
                const providerResolutionSource = String(providerResolutionSourceSelect.value || "").trim();
                const providerReadinessStatus = String(providerReadinessStatusSelect.value || "").trim();
                const template = String(templateSelect.value || "").trim();
                const status = String(statusSelect.value || "all").trim().toLowerCase();
                const comparisonMode = String(comparisonModeSelect.value || "template").trim().toLowerCase();
                const periodView = String(periodViewSelect?.value || "raw").trim().toLowerCase();
                const compareFrom = String(compareFromInput.value || "").trim();
                const compareTo = String(compareToInput.value || "").trim();

                if (dateFrom !== "") {
                    query.set("date_from", dateFrom);
                }
                if (dateTo !== "") {
                    query.set("date_to", dateTo);
                }
                if (provider !== "") {
                    query.set("provider", provider);
                }
                if (providerResolutionSource !== "") {
                    query.set("provider_resolution_source", providerResolutionSource);
                }
                if (providerReadinessStatus !== "") {
                    query.set("provider_readiness_status", providerReadinessStatus);
                }
                if (template !== "") {
                    query.set("template_key", template);
                }
                if (status !== "" && status !== "all") {
                    query.set("status", status);
                }
                if (comparisonMode !== "") {
                    query.set("comparison_mode", comparisonMode);
                }
                if (comparisonMode === "period") {
                    query.set("period_view", periodView === "per_day" ? "per_day" : "raw");
                    if (compareFrom !== "") {
                        query.set("compare_from", compareFrom);
                    }
                    if (compareTo !== "") {
                        query.set("compare_to", compareTo);
                    }
                }

                return query;
            }

            function applyBootstrapFilters() {
                const filters = bootstrap?.filters || {};
                dateFromInput.value = String(filters.date_from || "");
                dateToInput.value = String(filters.date_to || "");
                providerSelect.value = String(filters.provider || "");
                providerResolutionSourceSelect.value = String(filters.provider_resolution_source || "");
                providerReadinessStatusSelect.value = String(filters.provider_readiness_status || "");
                templateSelect.value = String(filters.template_key || "");
                statusSelect.value = String(filters.status || "all");
                comparisonModeSelect.value = String(filters.comparison_mode || "template");
                if (periodViewSelect) {
                    periodViewSelect.value = String(filters.period_view || "raw");
                }
                compareFromInput.value = String(filters.compare_from || "");
                compareToInput.value = String(filters.compare_to || "");
                toggleCompareRangeFields();
            }

            async function loadAnalytics() {
                const endpoint = String(bootstrap?.endpoints?.analytics || "").trim();

                if (endpoint === "") {
                    renderUnavailable("Birthday analytics endpoint is missing from the embedded bootstrap payload.");
                    setAlert("Birthday analytics endpoint is unavailable.", "error");
                    return;
                }

                state.loading = true;
                setBusy(true);
                renderLoadingState();
                setAlert("Loading birthday analytics...");

                try {
                    const query = queryFromFilters();
                    const url = query.toString() === "" ? endpoint : `${endpoint}?${query.toString()}`;
                    const response = await fetchJson(url);
                    state.data = response?.data || null;
                    setFilterOptions(state.data);
                    renderData(state.data);
                } catch (error) {
                    const message = error?.payload?.message || error?.message || "Birthday analytics request failed.";
                    renderUnavailable(message);
                    setAlert(message, "error");
                } finally {
                    state.loading = false;
                    setBusy(false);
                }
            }

            async function exportAnalytics() {
                const endpoint = String(bootstrap?.endpoints?.analytics_export || "").trim();
                if (endpoint === "") {
                    setAlert("Birthday analytics export endpoint is unavailable.", "error");
                    return;
                }

                setExporting(true);
                setAlert("Preparing CSV export...");

                try {
                    const query = queryFromFilters();
                    const url = query.toString() === "" ? endpoint : `${endpoint}?${query.toString()}`;
                    const file = await fetchCsv(url);
                    const href = URL.createObjectURL(file.blob);
                    const anchor = document.createElement("a");
                    anchor.href = href;
                    anchor.download = file.filename || "birthday-analytics.csv";
                    document.body.appendChild(anchor);
                    anchor.click();
                    anchor.remove();
                    URL.revokeObjectURL(href);
                    setAlert("Birthday analytics CSV export is ready.", "success");
                } catch (error) {
                    const message = error?.payload?.message || error?.message || "Birthday analytics export failed.";
                    setAlert(message, "error");
                } finally {
                    setExporting(false);
                }
            }

            filtersForm.addEventListener("submit", async (event) => {
                event.preventDefault();
                await loadAnalytics();
            });

            comparisonModeSelect.addEventListener("change", () => {
                toggleCompareRangeFields();
            });

            resetButton.addEventListener("click", async () => {
                applyBootstrapFilters();
                await loadAnalytics();
            });

            exportButton?.addEventListener("click", async () => {
                await exportAnalytics();
            });

            if (!bootstrap?.authorized) {
                renderUnavailable("Open this app from Shopify Admin so tenant-scoped analytics can be verified.");
                setAlert("Shopify context is required for birthday analytics.", "error");
                setBusy(true);
                return;
            }

            if (!bootstrap?.tenant_id) {
                renderUnavailable("This Shopify store is not mapped to a tenant yet. Birthday analytics are unavailable until tenant mapping is complete.");
                setAlert("Tenant mapping is required before birthday analytics can load.", "error");
                setBusy(true);
                return;
            }

            applyBootstrapFilters();
            loadAnalytics();
        })();
    </script>
@endsection
