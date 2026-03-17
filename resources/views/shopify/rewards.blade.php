@extends('shopify.rewards-layout')

@section('rewards-content')
    <style>
        .rewards-note {
            margin-bottom: 18px;
            border-radius: 18px;
            border: 1px solid rgba(15, 107, 146, 0.12);
            background: rgba(15, 107, 146, 0.08);
            color: #0f6b92;
            padding: 14px 16px;
            font-size: 14px;
            line-height: 1.6;
        }

        .rewards-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }

        .rewards-links a {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 143, 97, 0.14);
            background: rgba(15, 143, 97, 0.08);
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            color: #0d6b4a;
        }

        .rewards-root {
            display: grid;
            gap: 18px;
        }

        .rewards-summary-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .rewards-summary-card {
            border-radius: 20px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.72);
            padding: 18px;
            box-shadow: 0 16px 34px rgba(41, 60, 44, 0.06);
        }

        .rewards-summary-card span {
            display: block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(22, 34, 29, 0.48);
        }

        .rewards-summary-card strong {
            display: block;
            margin-top: 12px;
            font-family: "Fraunces", ui-serif, Georgia, serif;
            font-size: 2rem;
            line-height: 1;
            color: #12241d;
        }

        .rewards-summary-card p {
            margin: 10px 0 0;
            font-size: 13px;
            line-height: 1.55;
            color: rgba(22, 34, 29, 0.68);
        }

        .rewards-sections {
            display: grid;
            gap: 18px;
        }

        .rewards-panel {
            border-radius: 24px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.74);
            padding: 20px;
            box-shadow: 0 16px 34px rgba(41, 60, 44, 0.06);
        }

        .rewards-panel-head {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        .rewards-panel-head h2 {
            margin: 0;
            font-family: "Fraunces", ui-serif, Georgia, serif;
            font-size: 1.7rem;
            line-height: 1.1;
        }

        .rewards-panel-head p {
            margin: 8px 0 0;
            max-width: 720px;
            font-size: 14px;
            line-height: 1.65;
            color: rgba(22, 34, 29, 0.68);
        }

        .rewards-panel-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .rewards-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.68);
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 700;
            color: rgba(22, 34, 29, 0.72);
        }

        .rewards-state,
        .rewards-empty {
            border-radius: 18px;
            border: 1px dashed rgba(15, 23, 42, 0.14);
            background: rgba(247, 249, 246, 0.9);
            padding: 18px;
            font-size: 14px;
            line-height: 1.6;
            color: rgba(22, 34, 29, 0.7);
        }

        .rewards-state button {
            margin-top: 12px;
        }

        .rewards-list {
            display: grid;
            gap: 14px;
        }

        .rewards-row {
            border-radius: 20px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.9);
            padding: 18px;
        }

        .rewards-row-head {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
        }

        .rewards-row-title {
            margin: 0;
            font-size: 1.02rem;
            font-weight: 800;
            color: #16221d;
        }

        .rewards-row-code {
            margin-top: 6px;
            font-size: 12px;
            color: rgba(22, 34, 29, 0.48);
        }

        .rewards-row-actions button,
        .rewards-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 143, 97, 0.16);
            background: rgba(15, 143, 97, 0.1);
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 700;
            color: #0d6b4a;
            cursor: pointer;
        }

        .rewards-button--secondary {
            border-color: rgba(15, 23, 42, 0.1);
            background: rgba(255, 255, 255, 0.72);
            color: rgba(22, 34, 29, 0.76);
        }

        .rewards-button--danger {
            border-color: rgba(190, 24, 93, 0.16);
            background: rgba(190, 24, 93, 0.08);
            color: #9f1239;
        }

        .rewards-meta {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-top: 16px;
        }

        .rewards-meta div {
            border-radius: 16px;
            background: rgba(245, 247, 242, 0.95);
            border: 1px solid rgba(15, 23, 42, 0.06);
            padding: 12px;
        }

        .rewards-meta span {
            display: block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: rgba(22, 34, 29, 0.42);
        }

        .rewards-meta strong {
            display: block;
            margin-top: 8px;
            font-size: 14px;
            line-height: 1.45;
            color: #16221d;
        }

        .rewards-meta small {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            color: rgba(22, 34, 29, 0.56);
        }

        .rewards-description {
            margin-top: 14px;
            font-size: 14px;
            line-height: 1.65;
            color: rgba(22, 34, 29, 0.68);
        }

        .rewards-status {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .rewards-status--enabled {
            background: rgba(15, 143, 97, 0.12);
            color: #0f8f61;
        }

        .rewards-status--disabled {
            background: rgba(148, 163, 184, 0.14);
            color: #475569;
        }

        .rewards-skeleton {
            border-radius: 20px;
            height: 148px;
            background:
                linear-gradient(90deg, rgba(255, 255, 255, 0.35), rgba(248, 250, 247, 0.98), rgba(255, 255, 255, 0.35));
            background-size: 220% 100%;
            animation: rewardsShimmer 1.25s linear infinite;
        }

        .rewards-dialog {
            width: min(92vw, 760px);
            border: none;
            border-radius: 24px;
            padding: 0;
            overflow: hidden;
            box-shadow: 0 36px 90px rgba(18, 32, 25, 0.22);
        }

        .rewards-dialog::backdrop {
            background: rgba(15, 23, 42, 0.36);
            backdrop-filter: blur(6px);
        }

        .rewards-dialog-shell {
            background: #f9fbf7;
        }

        .rewards-dialog-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 22px 22px 18px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        }

        .rewards-dialog-head h3 {
            margin: 6px 0 0;
            font-family: "Fraunces", ui-serif, Georgia, serif;
            font-size: 1.8rem;
            line-height: 1.05;
        }

        .rewards-dialog-head p {
            margin: 0;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(22, 34, 29, 0.48);
        }

        .rewards-dialog-body {
            padding: 20px 22px 22px;
        }

        .rewards-dialog-grid {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .rewards-field {
            display: grid;
            gap: 8px;
        }

        .rewards-field--full {
            grid-column: 1 / -1;
        }

        .rewards-field label {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: rgba(22, 34, 29, 0.5);
        }

        .rewards-field input,
        .rewards-field textarea,
        .rewards-field select {
            width: 100%;
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(255, 255, 255, 0.96);
            padding: 12px 14px;
            font: inherit;
            color: #16221d;
        }

        .rewards-field textarea {
            min-height: 110px;
            resize: vertical;
        }

        .rewards-field input[readonly] {
            background: rgba(245, 247, 242, 0.98);
            color: rgba(22, 34, 29, 0.64);
        }

        .rewards-field-note {
            font-size: 12px;
            line-height: 1.55;
            color: rgba(22, 34, 29, 0.56);
        }

        .rewards-field-error {
            min-height: 16px;
            font-size: 12px;
            color: #be185d;
        }

        .rewards-dialog-alert {
            display: none;
            margin-bottom: 14px;
            border-radius: 16px;
            border: 1px solid rgba(190, 24, 93, 0.14);
            background: rgba(255, 244, 248, 0.96);
            padding: 12px 14px;
            font-size: 13px;
            line-height: 1.55;
            color: #9f1239;
        }

        .rewards-dialog-alert.is-visible {
            display: block;
        }

        .rewards-dialog-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
        }

        .rewards-hidden {
            display: none !important;
        }

        @keyframes rewardsShimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -20% 0; }
        }

        @media (max-width: 980px) {
            .rewards-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .rewards-meta {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 720px) {
            .rewards-summary-grid,
            .rewards-meta,
            .rewards-dialog-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @if(filled($setupNote))
        <div class="rewards-note">{{ $setupNote }}</div>
    @endif

    @if(! empty($referenceLinks))
        <div class="rewards-links">
            @foreach($referenceLinks as $link)
                <a href="{{ $link['href'] }}" target="_blank" rel="noopener">{{ $link['label'] }}</a>
            @endforeach
        </div>
    @endif

    @if(! $authorized)
        <div class="rewards-empty">
            Open the app from Shopify Admin to verify the store context before managing Candle Cash rewards.
        </div>
    @else
        <div
            id="shopify-rewards-admin"
            class="rewards-root"
            data-context-token="{{ $contextToken }}"
            data-endpoint="{{ $dataEndpoint }}"
            data-earn-update-endpoint-template="{{ $earnUpdateEndpointTemplate }}"
            data-redeem-update-endpoint-template="{{ $redeemUpdateEndpointTemplate }}"
        >
            <section id="rewards-summary" class="rewards-summary-grid">
                @for($index = 0; $index < 4; $index++)
                    <div class="rewards-summary-card">
                        <div class="rewards-skeleton"></div>
                    </div>
                @endfor
            </section>

            <div class="rewards-sections">
                <section class="rewards-panel">
                    <div class="rewards-panel-head">
                        <div>
                            <h2>Ways to Earn</h2>
                            <p>Live Candle Cash earn rules from Backstage. Edit titles, points, descriptions, status, and order without recreating rows.</p>
                        </div>
                        <div id="earn-summary" class="rewards-panel-summary"></div>
                    </div>
                    <div id="earn-section-body" class="rewards-list"></div>
                </section>

                <section class="rewards-panel">
                    <div class="rewards-panel-head">
                        <div>
                            <h2>Ways to Redeem</h2>
                            <p>Current Candle Cash reward rows already used by the live system. Storefront redemption rules stay aligned with the existing Backstage logic.</p>
                        </div>
                        <div id="redeem-summary" class="rewards-panel-summary"></div>
                    </div>
                    <div id="redeem-section-body" class="rewards-list"></div>
                </section>
            </div>
        </div>

        <dialog id="rewards-rule-dialog" class="rewards-dialog">
            <form id="rewards-rule-form" method="dialog" class="rewards-dialog-shell">
                <div class="rewards-dialog-head">
                    <div>
                        <p id="dialog-kicker">Rule editor</p>
                        <h3 id="dialog-title">Edit rule</h3>
                    </div>
                    <button type="button" class="rewards-button rewards-button--secondary" data-close-dialog>Close</button>
                </div>

                <div class="rewards-dialog-body">
                    <div id="dialog-alert" class="rewards-dialog-alert"></div>

                    <input type="hidden" name="kind" />
                    <input type="hidden" name="rule_id" />
                    <input type="hidden" name="is_storefront_reward" />

                    <div class="rewards-dialog-grid">
                        <div class="rewards-field">
                            <label for="rule-code">Rule Code</label>
                            <input id="rule-code" name="code" type="text" readonly />
                            <p class="rewards-field-note">Existing rows are always updated by ID.</p>
                        </div>

                        <div class="rewards-field">
                            <label for="rule-type">Type</label>
                            <input id="rule-type" name="type_label" type="text" readonly />
                            <p class="rewards-field-note" id="rule-type-note">Type is fixed to preserve current reward behavior.</p>
                        </div>

                        <div class="rewards-field rewards-field--full">
                            <label for="rule-title">Title</label>
                            <input id="rule-title" name="title" type="text" maxlength="160" required />
                            <p class="rewards-field-error" data-error-for="title"></p>
                        </div>

                        <div class="rewards-field rewards-field--full">
                            <label for="rule-description">Description</label>
                            <textarea id="rule-description" name="description" maxlength="500"></textarea>
                            <p class="rewards-field-error" data-error-for="description"></p>
                        </div>

                        <div class="rewards-field" data-earn-only>
                            <label for="rule-points-value">Points Value</label>
                            <input id="rule-points-value" name="points_value" type="number" min="0" max="50000" step="1" />
                            <p class="rewards-field-note">Stored internally as reward amount and converted through the live point rate.</p>
                            <p class="rewards-field-error" data-error-for="points_value"></p>
                        </div>

                        <div class="rewards-field" data-earn-only>
                            <label for="rule-sort-order">Sort Order</label>
                            <input id="rule-sort-order" name="sort_order" type="number" min="0" max="9999" step="1" />
                            <p class="rewards-field-error" data-error-for="sort_order"></p>
                        </div>

                        <div class="rewards-field" data-redeem-only>
                            <label for="rule-points-cost">Points Cost</label>
                            <input id="rule-points-cost" name="points_cost" type="number" min="0" max="50000" step="1" />
                            <p class="rewards-field-note" id="rule-points-cost-note">Points cost is stored directly on the reward row.</p>
                            <p class="rewards-field-error" data-error-for="points_cost"></p>
                        </div>

                        <div class="rewards-field" data-redeem-only>
                            <label for="rule-reward-value">Reward Value</label>
                            <input id="rule-reward-value" name="reward_value" type="text" maxlength="120" />
                            <p class="rewards-field-note" id="rule-reward-value-note">Use the current value format already stored on the reward.</p>
                            <p class="rewards-field-error" data-error-for="reward_value"></p>
                        </div>

                        <div class="rewards-field rewards-field--full rewards-hidden" data-redeem-only id="minimum-order-field">
                            <label for="rule-minimum-order">Minimum Order Requirement</label>
                            <input id="rule-minimum-order" name="minimum_order_amount" type="text" readonly value="Unavailable in current Candle Cash reward schema" />
                            <p class="rewards-field-note">This field is not currently stored on `candle_cash_rewards`, so the embedded page leaves it read-only.</p>
                        </div>

                        <div class="rewards-field">
                            <label for="rule-enabled">Status</label>
                            <select id="rule-enabled" name="enabled">
                                <option value="true">Enabled</option>
                                <option value="false">Disabled</option>
                            </select>
                            <p class="rewards-field-error" data-error-for="enabled"></p>
                        </div>
                    </div>

                    <div class="rewards-dialog-actions">
                        <button type="button" class="rewards-button rewards-button--secondary" data-close-dialog>Cancel</button>
                        <button type="submit" id="dialog-save-button" class="rewards-button">Save changes</button>
                    </div>
                </div>
            </form>
        </dialog>

        <script>
            (() => {
                const root = document.getElementById("shopify-rewards-admin");
                if (!root) {
                    return;
                }

                const dialog = document.getElementById("rewards-rule-dialog");
                const form = document.getElementById("rewards-rule-form");
                const dialogAlert = document.getElementById("dialog-alert");
                const saveButton = document.getElementById("dialog-save-button");
                const summaryEl = document.getElementById("rewards-summary");
                const earnSummaryEl = document.getElementById("earn-summary");
                const redeemSummaryEl = document.getElementById("redeem-summary");
                const earnSectionBody = document.getElementById("earn-section-body");
                const redeemSectionBody = document.getElementById("redeem-section-body");
                const closeButtons = document.querySelectorAll("[data-close-dialog]");

                const state = {
                    payload: null,
                    loading: true,
                    saving: false,
                    activeRule: null,
                };

                const endpoints = {
                    data: root.dataset.endpoint,
                    earnTemplate: root.dataset.earnUpdateEndpointTemplate,
                    redeemTemplate: root.dataset.redeemUpdateEndpointTemplate,
                };

                const contextToken = root.dataset.contextToken || "";

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

                function boolString(value) {
                    return value ? "true" : "false";
                }

                function renderLoadingCards(count = 3) {
                    return Array.from({ length: count }, () => '<div class="rewards-skeleton"></div>').join("");
                }

                function firstErrorMessage(errors) {
                    if (!errors || typeof errors !== "object") {
                        return null;
                    }

                    for (const value of Object.values(errors)) {
                        if (Array.isArray(value) && value.length > 0) {
                            return value[0];
                        }
                    }

                    return null;
                }

                async function fetchJson(url, options = {}) {
                    const response = await fetch(url, {
                        headers: {
                            "Accept": "application/json",
                            "Content-Type": "application/json",
                            "X-Forestry-Embedded-Context": contextToken,
                            ...(options.headers || {}),
                        },
                        credentials: "same-origin",
                        ...options,
                    });

                    const payload = await response.json().catch(() => ({
                        ok: false,
                        message: "Unexpected response from Backstage.",
                    }));

                    if (!response.ok) {
                        const error = new Error(payload.message || "Request failed.");
                        error.payload = payload;
                        throw error;
                    }

                    return payload;
                }

                function setDialogAlert(message) {
                    if (!message) {
                        dialogAlert.textContent = "";
                        dialogAlert.classList.remove("is-visible");
                        return;
                    }

                    dialogAlert.textContent = message;
                    dialogAlert.classList.add("is-visible");
                }

                function clearFieldErrors() {
                    form.querySelectorAll("[data-error-for]").forEach((node) => {
                        node.textContent = "";
                    });
                }

                function setFieldErrors(errors = {}) {
                    clearFieldErrors();

                    Object.entries(errors).forEach(([field, messages]) => {
                        const target = form.querySelector(`[data-error-for="${field}"]`);
                        if (target) {
                            target.textContent = Array.isArray(messages) ? String(messages[0] || "") : String(messages || "");
                        }
                    });
                }

                function summaryCardMarkup(label, value, detail) {
                    return `
                        <article class="rewards-summary-card">
                            <span>${escapeHtml(label)}</span>
                            <strong>${escapeHtml(value)}</strong>
                            <p>${escapeHtml(detail)}</p>
                        </article>
                    `;
                }

                function renderSummary(payload) {
                    if (!payload) {
                        summaryEl.innerHTML = renderLoadingCards(4);
                        return;
                    }

                    const earnSummary = payload.earn?.summary || {};
                    const redeemSummary = payload.redeem?.summary || {};
                    const program = payload.meta?.program || {};

                    summaryEl.innerHTML = [
                        summaryCardMarkup(
                            "Earn Rules",
                            formatNumber(earnSummary.total || 0),
                            `${formatNumber(earnSummary.enabled || 0)} enabled and ${formatNumber(earnSummary.disabled || 0)} disabled.`
                        ),
                        summaryCardMarkup(
                            "Redeem Rules",
                            formatNumber(redeemSummary.total || 0),
                            `${formatNumber(redeemSummary.enabled || 0)} enabled and ${formatNumber(redeemSummary.disabled || 0)} disabled.`
                        ),
                        summaryCardMarkup(
                            "Point Rate",
                            `${formatNumber(program.points_per_dollar || 0)} pts`,
                            "Current live points-per-dollar conversion used by Candle Cash."
                        ),
                        summaryCardMarkup(
                            "Storefront Reward",
                            escapeHtml(program.redeem_increment_formatted || "$0"),
                            `Max ${escapeHtml(program.max_redeemable_per_order_formatted || "$0")} per order.`
                        ),
                    ].join("");
                }

                function renderPanelSummary(element, section) {
                    if (!section || section.status === "error") {
                        element.innerHTML = "";
                        return;
                    }

                    const summary = section.summary || {};
                    element.innerHTML = `
                        <span class="rewards-chip">${formatNumber(summary.total || 0)} total</span>
                        <span class="rewards-chip">${formatNumber(summary.enabled || 0)} enabled</span>
                        <span class="rewards-chip">${formatNumber(summary.disabled || 0)} disabled</span>
                    `;
                }

                function rewardRowMarkup(kind, item) {
                    const pointsLabel = kind === "earn" ? formatNumber(item.points_value) : formatNumber(item.points_cost);
                    const pointsCaption = kind === "earn"
                        ? `${escapeHtml(item.reward_amount_formatted || "$0")} stored reward amount`
                        : (item.is_storefront_reward ? "Storefront rule" : "Stored points cost");
                    const typeLabel = kind === "earn"
                        ? (item.action_type_label || item.task_type_label || "Earn rule")
                        : (item.reward_type_label || "Reward");
                    const typeNote = kind === "earn"
                        ? (item.task_type_label ? `Task type: ${item.task_type_label}` : "")
                        : (item.is_storefront_reward ? "Current storefront redemption reward." : "Backstage redemption row.");
                    const orderLabel = kind === "earn"
                        ? formatNumber(item.sort_order)
                        : (item.minimum_order_supported ? escapeHtml(item.minimum_order_amount) : "Unavailable");
                    const orderCaption = kind === "earn" ? "Sort order" : "Minimum order";

                    return `
                        <article class="rewards-row">
                            <div class="rewards-row-head">
                                <div>
                                    <h3 class="rewards-row-title">${escapeHtml(item.title)}</h3>
                                    <div class="rewards-row-code">${escapeHtml(item.code)}</div>
                                </div>
                                <div class="rewards-row-actions">
                                    <span class="rewards-status rewards-status--${item.enabled ? "enabled" : "disabled"}">${escapeHtml(item.status_label)}</span>
                                    <button type="button" data-edit-kind="${kind}" data-edit-id="${item.id}">Edit</button>
                                </div>
                            </div>
                            <div class="rewards-meta">
                                <div>
                                    <span>${kind === "earn" ? "Action Type" : "Reward Type"}</span>
                                    <strong>${escapeHtml(typeLabel)}</strong>
                                    <small>${escapeHtml(typeNote)}</small>
                                </div>
                                <div>
                                    <span>${kind === "earn" ? "Points Value" : "Points Cost"}</span>
                                    <strong>${escapeHtml(pointsLabel)}</strong>
                                    <small>${escapeHtml(pointsCaption)}</small>
                                </div>
                                <div>
                                    <span>${kind === "earn" ? "Customer Status" : "Value Display"}</span>
                                    <strong>${kind === "earn" ? (item.customer_visible ? "Visible to customers" : "Hidden from customers") : escapeHtml(item.value_display || "—")}</strong>
                                    <small>${kind === "earn" ? "Derived from rule metadata" : escapeHtml(item.reward_value || "No raw reward value stored")}</small>
                                </div>
                                <div>
                                    <span>${orderCaption}</span>
                                    <strong>${escapeHtml(orderLabel)}</strong>
                                    <small>${kind === "earn" ? "Lower numbers appear earlier." : "Current reward rows do not store a minimum order amount."}</small>
                                </div>
                            </div>
                            ${item.description ? `<p class="rewards-description">${escapeHtml(item.description)}</p>` : ""}
                        </article>
                    `;
                }

                function renderSection(element, kind, section) {
                    if (state.loading && !state.payload) {
                        element.innerHTML = renderLoadingCards();
                        return;
                    }

                    if (!section || section.status === "error") {
                        element.innerHTML = `
                            <div class="rewards-state">
                                ${escapeHtml(section?.message || "This section could not be loaded.")}
                                <br />
                                <button type="button" class="rewards-button rewards-button--secondary" data-reload-section="${kind}">Retry</button>
                            </div>
                        `;
                        return;
                    }

                    const items = Array.isArray(section.items) ? section.items : [];
                    if (items.length === 0) {
                        element.innerHTML = `<div class="rewards-empty">No ${kind === "earn" ? "earn" : "redeem"} rules are available for this store yet.</div>`;
                        return;
                    }

                    element.innerHTML = items.map((item) => rewardRowMarkup(kind, item)).join("");
                }

                function currentRule(kind, id) {
                    const section = state.payload?.[kind];
                    const items = Array.isArray(section?.items) ? section.items : [];

                    return items.find((item) => Number(item.id) === Number(id)) || null;
                }

                function setDialogMode(kind, rule) {
                    form.elements.kind.value = kind;
                    form.elements.rule_id.value = String(rule.id);
                    form.elements.is_storefront_reward.value = boolString(Boolean(rule.is_storefront_reward));
                    form.elements.code.value = rule.code || "";
                    form.elements.type_label.value = kind === "earn"
                        ? (rule.action_type_label || rule.task_type_label || "")
                        : (rule.reward_type_label || "");
                    form.elements.title.value = rule.title || "";
                    form.elements.description.value = rule.description || "";
                    form.elements.enabled.value = boolString(Boolean(rule.enabled));

                    document.getElementById("dialog-kicker").textContent = kind === "earn" ? "Earn rule" : "Redeem rule";
                    document.getElementById("dialog-title").textContent = rule.title || "Edit rule";

                    form.querySelectorAll("[data-earn-only]").forEach((node) => {
                        node.classList.toggle("rewards-hidden", kind !== "earn");
                    });

                    form.querySelectorAll("[data-redeem-only]").forEach((node) => {
                        node.classList.toggle("rewards-hidden", kind !== "redeem");
                    });

                    if (kind === "earn") {
                        form.elements.points_value.value = String(rule.points_value ?? 0);
                        form.elements.sort_order.value = String(rule.sort_order ?? 0);
                    } else {
                        form.elements.points_cost.value = String(rule.points_cost ?? 0);
                        form.elements.reward_value.value = rule.reward_value || "";

                        const rewardValueNote = document.getElementById("rule-reward-value-note");
                        const pointsCostNote = document.getElementById("rule-points-cost-note");

                        if (rule.is_storefront_reward) {
                            rewardValueNote.textContent = "This row is the live storefront Candle Cash reward. Keep the discount value numeric, like 10USD.";
                            pointsCostNote.textContent = "Storefront cost is derived from the discount value and the current point rate.";
                        } else {
                            rewardValueNote.textContent = "Use the current value format already stored on the reward.";
                            pointsCostNote.textContent = "Points cost is stored directly on the reward row.";
                        }
                    }
                }

                function openDialog(kind, id) {
                    const rule = currentRule(kind, id);
                    if (!rule) {
                        window.ForestryEmbeddedApp?.showToast("That rule could not be found.", "error");
                        return;
                    }

                    state.activeRule = {
                        kind,
                        rule,
                    };

                    setDialogAlert("");
                    clearFieldErrors();
                    setDialogMode(kind, rule);

                    if (typeof dialog.showModal === "function") {
                        dialog.showModal();
                    }
                }

                function closeDialog() {
                    state.activeRule = null;
                    setDialogAlert("");
                    clearFieldErrors();

                    if (dialog.open && typeof dialog.close === "function") {
                        dialog.close();
                    }
                }

                function normalizeFormPayload() {
                    const kind = form.elements.kind.value;
                    const enabled = form.elements.enabled.value === "true";

                    if (kind === "earn") {
                        return {
                            kind,
                            title: form.elements.title.value.trim(),
                            description: form.elements.description.value.trim(),
                            enabled,
                            points_value: Number(form.elements.points_value.value || 0),
                            sort_order: Number(form.elements.sort_order.value || 0),
                        };
                    }

                    return {
                        kind,
                        title: form.elements.title.value.trim(),
                        description: form.elements.description.value.trim(),
                        enabled,
                        points_cost: Number(form.elements.points_cost.value || 0),
                        reward_value: form.elements.reward_value.value.trim(),
                    };
                }

                function clientValidate(payload) {
                    const errors = {};

                    if (!payload.title) {
                        errors.title = ["Title is required."];
                    }

                    if (payload.kind === "earn") {
                        if (!Number.isInteger(payload.points_value) || payload.points_value < 0) {
                            errors.points_value = ["Points value must be a whole number of zero or more."];
                        }

                        if (!Number.isInteger(payload.sort_order) || payload.sort_order < 0) {
                            errors.sort_order = ["Sort order must be a whole number of zero or more."];
                        }
                    } else {
                        if (!Number.isInteger(payload.points_cost) || payload.points_cost < 0) {
                            errors.points_cost = ["Points cost must be a whole number of zero or more."];
                        }
                    }

                    return errors;
                }

                async function loadRewards() {
                    state.loading = true;
                    render();

                    try {
                        const response = await fetchJson(endpoints.data, {
                            method: "GET",
                        });

                        state.payload = response.data || null;
                    } catch (error) {
                        state.payload = {
                            earn: {
                                status: "error",
                                items: [],
                                summary: {},
                                message: error?.payload?.message || error.message || "Rewards could not be loaded.",
                            },
                            redeem: {
                                status: "error",
                                items: [],
                                summary: {},
                                message: error?.payload?.message || error.message || "Rewards could not be loaded.",
                            },
                            meta: {
                                program: {},
                            },
                        };

                        window.ForestryEmbeddedApp?.showToast(error?.payload?.message || "Rewards could not be loaded.", "error");
                    } finally {
                        state.loading = false;
                        render();
                    }
                }

                function render() {
                    renderSummary(state.payload);
                    renderPanelSummary(earnSummaryEl, state.payload?.earn);
                    renderPanelSummary(redeemSummaryEl, state.payload?.redeem);
                    renderSection(earnSectionBody, "earn", state.payload?.earn);
                    renderSection(redeemSectionBody, "redeem", state.payload?.redeem);
                }

                async function saveRule(event) {
                    event.preventDefault();

                    if (!state.activeRule) {
                        return;
                    }

                    const payload = normalizeFormPayload();
                    const clientErrors = clientValidate(payload);
                    if (Object.keys(clientErrors).length > 0) {
                        setFieldErrors(clientErrors);
                        setDialogAlert(firstErrorMessage(clientErrors));
                        return;
                    }

                    const wasEnabled = Boolean(state.activeRule.rule?.enabled);
                    if (wasEnabled && payload.enabled === false) {
                        const confirmed = window.confirm("Disable this reward rule? The existing row will stay in place, but it will stop being active.");
                        if (!confirmed) {
                            return;
                        }
                    }

                    setDialogAlert("");
                    clearFieldErrors();
                    state.saving = true;
                    saveButton.disabled = true;
                    saveButton.textContent = "Saving...";

                    const template = payload.kind === "earn" ? endpoints.earnTemplate : endpoints.redeemTemplate;
                    const url = template.replace(payload.kind === "earn" ? "__TASK__" : "__REWARD__", encodeURIComponent(form.elements.rule_id.value));

                    try {
                        const response = await fetchJson(url, {
                            method: "PATCH",
                            body: JSON.stringify(payload),
                        });

                        state.payload = response.data || state.payload;
                        closeDialog();
                        render();
                        window.ForestryEmbeddedApp?.showToast(response.message || "Rule saved.", "success");
                    } catch (error) {
                        const errors = error?.payload?.errors || {};
                        setFieldErrors(errors);
                        setDialogAlert(firstErrorMessage(errors) || error?.payload?.message || error.message || "Save failed.");
                    } finally {
                        state.saving = false;
                        saveButton.disabled = false;
                        saveButton.textContent = "Save changes";
                    }
                }

                root.addEventListener("click", (event) => {
                    const editButton = event.target.closest("[data-edit-kind][data-edit-id]");
                    if (editButton) {
                        openDialog(editButton.dataset.editKind, editButton.dataset.editId);
                        return;
                    }

                    const reloadButton = event.target.closest("[data-reload-section]");
                    if (reloadButton) {
                        loadRewards();
                    }
                });

                closeButtons.forEach((button) => {
                    button.addEventListener("click", closeDialog);
                });

                dialog.addEventListener("click", (event) => {
                    const bounds = dialog.getBoundingClientRect();
                    const clickedInDialog = (
                        event.clientX >= bounds.left &&
                        event.clientX <= bounds.right &&
                        event.clientY >= bounds.top &&
                        event.clientY <= bounds.bottom
                    );

                    if (!clickedInDialog) {
                        closeDialog();
                    }
                });

                form.addEventListener("submit", saveRule);

                render();
                loadRewards();
            })();
        </script>
    @endif
@endsection
