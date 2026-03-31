@extends('shopify.rewards-layout')

@section('rewards-content')
    <style>
        .policy-shell {
            display: grid;
            gap: 16px;
        }

        .policy-note,
        .policy-card {
            border-radius: 18px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.05);
            padding: 18px;
        }

        .policy-note {
            background: rgba(15, 107, 146, 0.08);
            border-color: rgba(15, 107, 146, 0.16);
            color: #0f6b92;
            font-size: 14px;
            line-height: 1.6;
        }

        .policy-heading {
            margin: 0;
            font-size: 1.4rem;
            line-height: 1.2;
        }

        .policy-copy {
            margin: 8px 0 0;
            font-size: 14px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.68);
        }

        .policy-grid {
            margin-top: 14px;
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .policy-field {
            display: grid;
            gap: 8px;
        }

        .policy-field--full {
            grid-column: 1 / -1;
        }

        .policy-field label {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.56);
        }

        .policy-field input,
        .policy-field textarea,
        .policy-field select {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: #fff;
            padding: 10px 12px;
            font: inherit;
            color: #0f172a;
        }

        .policy-field textarea {
            resize: vertical;
            min-height: 88px;
        }

        .policy-field-help {
            margin: 0;
            font-size: 12px;
            line-height: 1.5;
            color: rgba(15, 23, 42, 0.6);
        }

        .policy-check {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 6px;
            font-size: 14px;
            color: rgba(15, 23, 42, 0.82);
        }

        .policy-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }

        .policy-button {
            border-radius: 999px;
            border: 1px solid rgba(15, 143, 97, 0.2);
            background: rgba(15, 143, 97, 0.1);
            color: #0d6b4a;
            font-size: 13px;
            font-weight: 700;
            padding: 10px 14px;
            cursor: pointer;
        }

        .policy-button[disabled] {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .policy-alert {
            display: none;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 13px;
            line-height: 1.5;
        }

        .policy-alert.is-visible {
            display: block;
        }

        .policy-alert--success {
            border: 1px solid rgba(15, 143, 97, 0.22);
            background: rgba(15, 143, 97, 0.1);
            color: #0d6b4a;
        }

        .policy-alert--error {
            border: 1px solid rgba(190, 24, 93, 0.24);
            background: rgba(190, 24, 93, 0.1);
            color: #9f1239;
        }

        .policy-list {
            margin: 10px 0 0;
            padding-left: 18px;
            color: rgba(15, 23, 42, 0.75);
            font-size: 13px;
            line-height: 1.6;
        }

        @media (max-width: 760px) {
            .policy-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div
        id="tenant-rewards-policy"
        class="policy-shell"
        data-endpoint="{{ $rewardsPolicyEndpoint ?? ($policyEndpoint ?? '') }}"
        data-update-endpoint="{{ $rewardsPolicyUpdateEndpoint ?? ($policyUpdateEndpoint ?? '') }}"
        data-editable="{{ ($rewardsPolicyEditable ?? $rewardsEditorEditable ?? false) ? 'true' : 'false' }}"
    >
        <div class="policy-note">
            <strong>Program setup wizard</strong>
            <div>Set how rewards work for this tenant using business-language controls. Existing Candle Cash issuance, wallet, redemption, and reconciliation flows remain the execution engine.</div>
        </div>

        <div id="policy-error" class="policy-alert policy-alert--error"></div>
        <div id="policy-success" class="policy-alert policy-alert--success"></div>

        <section class="policy-card">
            <h2 class="policy-heading">Program Summary</h2>
            <p id="policy-summary" class="policy-copy">Loading current policy...</p>
            <ul id="policy-warnings" class="policy-list"></ul>
        </section>

        <form id="policy-form" class="policy-shell">
            <section class="policy-card">
                <h3 class="policy-heading">Program Setup</h3>
                <p class="policy-copy">How this rewards program is named and explained to customers.</p>
                <div class="policy-grid">
                    <div class="policy-field">
                        <label for="program_name">Program name</label>
                        <input id="program_name" name="program_name" type="text" maxlength="120" />
                    </div>
                    <div class="policy-field">
                        <label for="short_label">Short label</label>
                        <input id="short_label" name="short_label" type="text" maxlength="80" />
                    </div>
                    <div class="policy-field">
                        <label for="terminology_mode">Rewards language</label>
                        <select id="terminology_mode" name="terminology_mode">
                            <option value="cash">Cash</option>
                            <option value="points">Points</option>
                        </select>
                    </div>
                    <div class="policy-field policy-field--full">
                        <label for="program_description">Program description</label>
                        <textarea id="program_description" name="program_description"></textarea>
                    </div>
                </div>
            </section>

            <section class="policy-card">
                <h3 class="policy-heading">How Rewards Turn Into Savings</h3>
                <p class="policy-copy">Define conversion, redemption increments, and order economics guardrails.</p>
                <div class="policy-grid">
                    <div class="policy-field">
                        <label for="currency_mode">Value mode</label>
                        <select id="currency_mode" name="currency_mode">
                            <option value="fixed_cash">Fixed cash</option>
                            <option value="points_to_cash">Points to cash</option>
                        </select>
                    </div>
                    <div class="policy-field">
                        <label for="points_per_dollar">Points per $1</label>
                        <input id="points_per_dollar" name="points_per_dollar" type="number" min="1" step="1" />
                    </div>
                    <div class="policy-field">
                        <label for="redeem_increment_dollars">Smallest reward a customer can use</label>
                        <input id="redeem_increment_dollars" name="redeem_increment_dollars" type="number" min="0.01" step="0.01" />
                    </div>
                    <div class="policy-field">
                        <label for="max_redeemable_per_order_dollars">Largest reward per order</label>
                        <input id="max_redeemable_per_order_dollars" name="max_redeemable_per_order_dollars" type="number" min="0.01" step="0.01" />
                    </div>
                    <div class="policy-field">
                        <label for="minimum_purchase_dollars">Minimum order required to redeem</label>
                        <input id="minimum_purchase_dollars" name="minimum_purchase_dollars" type="number" min="0" step="0.01" />
                    </div>
                    <div class="policy-field">
                        <label for="max_open_codes">Max outstanding open codes</label>
                        <input id="max_open_codes" name="max_open_codes" type="number" min="1" step="1" />
                    </div>
                </div>
            </section>

            <section class="policy-card">
                <h3 class="policy-heading">How Customers Use Rewards</h3>
                <p class="policy-copy">Configure code strategy, stacking, and product exclusions.</p>
                <div class="policy-grid">
                    <div class="policy-field">
                        <label for="code_strategy">How rewards are delivered</label>
                        <select id="code_strategy" name="code_strategy">
                            <option value="unique_per_customer">Unique per customer</option>
                            <option value="shared">Shared code</option>
                        </select>
                    </div>
                    <div class="policy-field">
                        <label for="stacking_mode">Stacking behavior</label>
                        <select id="stacking_mode" name="stacking_mode">
                            <option value="no_stacking">No stacking</option>
                            <option value="shipping_only">Stack with shipping only</option>
                            <option value="selected_promo_types">Selected promo types only</option>
                        </select>
                    </div>
                    <div class="policy-field">
                        <label for="max_codes_per_order">Codes allowed per order</label>
                        <input id="max_codes_per_order" name="max_codes_per_order" type="number" min="1" max="5" step="1" />
                    </div>
                    <div class="policy-field">
                        <label for="platform_supports_multi_code">Platform supports multiple codes?</label>
                        <select id="platform_supports_multi_code" name="platform_supports_multi_code">
                            <option value="false">No</option>
                            <option value="true">Yes</option>
                        </select>
                    </div>
                    <div class="policy-field policy-field--full">
                        <label>Product exclusions</label>
                        <div class="policy-grid">
                            <label class="policy-check"><input id="exclude_wholesale" type="checkbox" /> Wholesale</label>
                            <label class="policy-check"><input id="exclude_sale_items" type="checkbox" /> Sale items</label>
                            <label class="policy-check"><input id="exclude_bundles" type="checkbox" /> Bundles / gift sets</label>
                            <label class="policy-check"><input id="exclude_subscriptions" type="checkbox" /> Subscriptions</label>
                        </div>
                    </div>
                </div>
            </section>

            <section class="policy-card">
                <h3 class="policy-heading">Expiration and Reminder Settings</h3>
                <p class="policy-copy">Choose expiration behavior and reminder cadence.</p>
                <div class="policy-grid">
                    <div class="policy-field">
                        <label for="expiration_mode">When does reward expire?</label>
                        <select id="expiration_mode" name="expiration_mode">
                            <option value="days_from_issue">Days from issue</option>
                            <option value="end_of_season">End of season</option>
                            <option value="none">No expiration</option>
                        </select>
                    </div>
                    <div class="policy-field">
                        <label for="expiration_days">Expiration window (days)</label>
                        <input id="expiration_days" name="expiration_days" type="number" min="1" step="1" />
                    </div>
                    <div class="policy-field">
                        <label for="reminder_offsets_days">Reminder schedule (days before expiry)</label>
                        <input id="reminder_offsets_days" name="reminder_offsets_days" type="text" placeholder="30,14,7,3,1" />
                        <p class="policy-field-help">Comma-separated day offsets.</p>
                    </div>
                    <div class="policy-field">
                        <label for="sms_max_per_reward">Max SMS reminders per reward</label>
                        <input id="sms_max_per_reward" name="sms_max_per_reward" type="number" min="0" step="1" />
                    </div>
                    <div class="policy-field">
                        <label for="sms_quiet_days">SMS quiet period (days)</label>
                        <input id="sms_quiet_days" name="sms_quiet_days" type="number" min="0" step="1" />
                    </div>
                    <div class="policy-field policy-field--full">
                        <label class="policy-check"><input id="email_enabled" type="checkbox" /> Email reminders enabled</label>
                        <label class="policy-check"><input id="sms_enabled" type="checkbox" /> Text reminders enabled</label>
                    </div>
                </div>
            </section>

            <section class="policy-card">
                <h3 class="policy-heading">Finance and Launch Controls</h3>
                <p class="policy-copy">Set risk and launch controls with safe defaults.</p>
                <div class="policy-grid">
                    <div class="policy-field">
                        <label for="liability_alert_threshold_dollars">Liability alert threshold</label>
                        <input id="liability_alert_threshold_dollars" name="liability_alert_threshold_dollars" type="number" min="0" step="0.01" />
                    </div>
                    <div class="policy-field">
                        <label for="fraud_sensitivity_mode">Fraud sensitivity</label>
                        <select id="fraud_sensitivity_mode" name="fraud_sensitivity_mode">
                            <option value="low">Low</option>
                            <option value="balanced">Balanced</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="policy-field">
                        <label for="manual_grant_approval_threshold_dollars">Manual grant approval threshold</label>
                        <input id="manual_grant_approval_threshold_dollars" name="manual_grant_approval_threshold_dollars" type="number" min="0" step="0.01" />
                    </div>
                    <div class="policy-field">
                        <label for="launch_state">Program state</label>
                        <select id="launch_state" name="launch_state">
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                            <option value="scheduled">Scheduled</option>
                        </select>
                    </div>
                    <div class="policy-field">
                        <label for="scheduled_launch_at">Scheduled launch timestamp</label>
                        <input id="scheduled_launch_at" name="scheduled_launch_at" type="text" placeholder="2026-04-15T09:00:00-04:00" />
                    </div>
                    <div class="policy-field policy-field--full">
                        <label class="policy-check"><input id="test_mode" type="checkbox" /> Test mode enabled</label>
                    </div>
                </div>
            </section>

            <div class="policy-actions">
                <button id="policy-save" type="submit" class="policy-button">Save program settings</button>
            </div>
        </form>
    </div>

    <script>
        (() => {
            const root = document.getElementById("tenant-rewards-policy");
            if (!root) {
                return;
            }

            const endpoint = root.dataset.endpoint;
            const updateEndpoint = root.dataset.updateEndpoint;
            const editable = root.dataset.editable === "true";

            const form = document.getElementById("policy-form");
            const saveButton = document.getElementById("policy-save");
            const errorAlert = document.getElementById("policy-error");
            const successAlert = document.getElementById("policy-success");
            const summaryEl = document.getElementById("policy-summary");
            const warningsEl = document.getElementById("policy-warnings");

            function showAlert(target, message) {
                if (!target) return;
                if (!message) {
                    target.classList.remove("is-visible");
                    target.textContent = "";
                    return;
                }

                target.textContent = message;
                target.classList.add("is-visible");
            }

            function toBool(value) {
                return value === true || value === "true" || value === 1 || value === "1";
            }

            function parseOffsets(raw) {
                return String(raw || "")
                    .split(",")
                    .map((value) => Number.parseInt(value.trim(), 10))
                    .filter((value) => Number.isFinite(value) && value >= 0);
            }

            async function authHeaders() {
                if (!window.shopify || typeof window.shopify.idToken !== "function") {
                    throw new Error("Shopify Admin verification is unavailable. Reload from Shopify Admin and try again.");
                }

                const token = await window.shopify.idToken();
                if (typeof token !== "string" || token.trim() === "") {
                    throw new Error("Shopify Admin verification is unavailable. Reload from Shopify Admin and try again.");
                }

                return {
                    "Accept": "application/json",
                    "Content-Type": "application/json",
                    "Authorization": `Bearer ${token.trim()}`,
                };
            }

            async function requestJson(url, options = {}) {
                const headers = await authHeaders();
                const response = await fetch(url, {
                    ...options,
                    headers: {
                        ...headers,
                        ...(options.headers || {}),
                    },
                    credentials: "same-origin",
                });

                const payload = await response.json().catch(() => ({ ok: false, message: "Unexpected response." }));
                if (!response.ok) {
                    const message = payload?.message || "Request failed.";
                    const error = new Error(message);
                    error.payload = payload;
                    throw error;
                }

                return payload;
            }

            function setField(id, value) {
                const node = document.getElementById(id);
                if (!node) return;

                if (node.type === "checkbox") {
                    node.checked = toBool(value);
                    return;
                }

                node.value = value ?? "";
            }

            function fillForm(policy) {
                setField("program_name", policy?.program_identity?.program_name);
                setField("short_label", policy?.program_identity?.short_label);
                setField("terminology_mode", policy?.program_identity?.terminology_mode);
                setField("program_description", policy?.program_identity?.description);

                setField("currency_mode", policy?.value_model?.currency_mode);
                setField("points_per_dollar", policy?.value_model?.points_per_dollar);
                setField("redeem_increment_dollars", policy?.value_model?.redeem_increment_dollars);
                setField("max_redeemable_per_order_dollars", policy?.value_model?.max_redeemable_per_order_dollars);
                setField("minimum_purchase_dollars", policy?.value_model?.minimum_purchase_dollars);
                setField("max_open_codes", policy?.finance_and_safety?.max_open_codes);

                setField("code_strategy", policy?.redemption_rules?.code_strategy);
                setField("stacking_mode", policy?.redemption_rules?.stacking_mode);
                setField("max_codes_per_order", policy?.redemption_rules?.max_codes_per_order);
                setField("platform_supports_multi_code", policy?.redemption_rules?.platform_supports_multi_code ? "true" : "false");

                setField("exclude_wholesale", policy?.redemption_rules?.exclusions?.wholesale);
                setField("exclude_sale_items", policy?.redemption_rules?.exclusions?.sale_items);
                setField("exclude_bundles", policy?.redemption_rules?.exclusions?.bundles);
                setField("exclude_subscriptions", policy?.redemption_rules?.exclusions?.subscriptions);

                setField("expiration_mode", policy?.expiration_and_reminders?.expiration_mode);
                setField("expiration_days", policy?.expiration_and_reminders?.expiration_days);
                setField("reminder_offsets_days", (policy?.expiration_and_reminders?.reminder_offsets_days || []).join(","));
                setField("sms_max_per_reward", policy?.expiration_and_reminders?.sms_max_per_reward);
                setField("sms_quiet_days", policy?.expiration_and_reminders?.sms_quiet_days);
                setField("email_enabled", policy?.expiration_and_reminders?.email_enabled);
                setField("sms_enabled", policy?.expiration_and_reminders?.sms_enabled);

                setField("liability_alert_threshold_dollars", policy?.finance_and_safety?.liability_alert_threshold_dollars);
                setField("fraud_sensitivity_mode", policy?.finance_and_safety?.fraud_sensitivity_mode);
                setField("manual_grant_approval_threshold_dollars", policy?.finance_and_safety?.manual_grant_approval_threshold_dollars);

                setField("launch_state", policy?.access_state?.launch_state);
                setField("scheduled_launch_at", policy?.access_state?.scheduled_launch_at);
                setField("test_mode", policy?.access_state?.test_mode);
            }

            function renderSummary(policy) {
                summaryEl.textContent = policy?.summary || "No summary available.";
                warningsEl.innerHTML = "";
                const warnings = Array.isArray(policy?.warnings) ? policy.warnings : [];
                if (warnings.length === 0) {
                    const item = document.createElement("li");
                    item.textContent = "No risk warnings for current settings.";
                    warningsEl.appendChild(item);
                    return;
                }

                warnings.forEach((warning) => {
                    const item = document.createElement("li");
                    item.textContent = warning?.message || "Policy warning";
                    warningsEl.appendChild(item);
                });
            }

            function collectPayload() {
                return {
                    program_identity: {
                        program_name: document.getElementById("program_name")?.value || "",
                        short_label: document.getElementById("short_label")?.value || "",
                        terminology_mode: document.getElementById("terminology_mode")?.value || "cash",
                        description: document.getElementById("program_description")?.value || null,
                    },
                    value_model: {
                        currency_mode: document.getElementById("currency_mode")?.value || "fixed_cash",
                        points_per_dollar: Number.parseInt(document.getElementById("points_per_dollar")?.value || "0", 10),
                        redeem_increment_dollars: Number.parseFloat(document.getElementById("redeem_increment_dollars")?.value || "0"),
                        max_redeemable_per_order_dollars: Number.parseFloat(document.getElementById("max_redeemable_per_order_dollars")?.value || "0"),
                        minimum_purchase_dollars: Number.parseFloat(document.getElementById("minimum_purchase_dollars")?.value || "0"),
                    },
                    redemption_rules: {
                        code_strategy: document.getElementById("code_strategy")?.value || "unique_per_customer",
                        stacking_mode: document.getElementById("stacking_mode")?.value || "no_stacking",
                        max_codes_per_order: Number.parseInt(document.getElementById("max_codes_per_order")?.value || "1", 10),
                        platform_supports_multi_code: (document.getElementById("platform_supports_multi_code")?.value || "false") === "true",
                        exclusions: {
                            wholesale: !!document.getElementById("exclude_wholesale")?.checked,
                            sale_items: !!document.getElementById("exclude_sale_items")?.checked,
                            bundles: !!document.getElementById("exclude_bundles")?.checked,
                            subscriptions: !!document.getElementById("exclude_subscriptions")?.checked,
                        },
                    },
                    expiration_and_reminders: {
                        expiration_mode: document.getElementById("expiration_mode")?.value || "days_from_issue",
                        expiration_days: Number.parseInt(document.getElementById("expiration_days")?.value || "30", 10),
                        reminder_offsets_days: parseOffsets(document.getElementById("reminder_offsets_days")?.value || ""),
                        email_enabled: !!document.getElementById("email_enabled")?.checked,
                        sms_enabled: !!document.getElementById("sms_enabled")?.checked,
                        sms_max_per_reward: Number.parseInt(document.getElementById("sms_max_per_reward")?.value || "0", 10),
                        sms_quiet_days: Number.parseInt(document.getElementById("sms_quiet_days")?.value || "0", 10),
                    },
                    finance_and_safety: {
                        max_open_codes: Number.parseInt(document.getElementById("max_open_codes")?.value || "1", 10),
                        liability_alert_threshold_dollars: Number.parseFloat(document.getElementById("liability_alert_threshold_dollars")?.value || "0") || null,
                        fraud_sensitivity_mode: document.getElementById("fraud_sensitivity_mode")?.value || "balanced",
                        manual_grant_approval_threshold_dollars: Number.parseFloat(document.getElementById("manual_grant_approval_threshold_dollars")?.value || "0") || null,
                    },
                    access_state: {
                        launch_state: document.getElementById("launch_state")?.value || "published",
                        scheduled_launch_at: document.getElementById("scheduled_launch_at")?.value || null,
                        test_mode: !!document.getElementById("test_mode")?.checked,
                    },
                };
            }

            function setEditable(enabled) {
                form.querySelectorAll("input, select, textarea, button").forEach((node) => {
                    if (node.id === "policy-save") {
                        node.disabled = !enabled;
                        return;
                    }

                    node.disabled = !enabled;
                });
            }

            async function loadPolicy() {
                try {
                    showAlert(errorAlert, null);
                    showAlert(successAlert, null);
                    const payload = await requestJson(endpoint);
                    const policy = payload?.data || {};

                    fillForm(policy);
                    renderSummary(policy);

                    const canEdit = editable && payload?.editable === true;
                    setEditable(canEdit);

                    if (!canEdit && payload?.message) {
                        showAlert(errorAlert, payload.message);
                    }
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Rewards policy could not be loaded.");
                    setEditable(false);
                    summaryEl.textContent = "Rewards policy could not be loaded.";
                }
            }

            form.addEventListener("submit", async (event) => {
                event.preventDefault();

                if (saveButton.disabled) {
                    return;
                }

                try {
                    showAlert(errorAlert, null);
                    showAlert(successAlert, null);
                    saveButton.disabled = true;

                    const response = await requestJson(updateEndpoint, {
                        method: "PATCH",
                        body: JSON.stringify(collectPayload()),
                    });

                    renderSummary(response?.data || {});
                    showAlert(successAlert, response?.message || "Rewards settings saved.");
                } catch (error) {
                    const payload = error?.payload || {};
                    const errors = payload?.errors && typeof payload.errors === "object"
                        ? Object.values(payload.errors).flat().filter(Boolean)
                        : [];
                    const message = errors[0] || payload?.message || error?.message || "Rewards settings could not be saved.";
                    showAlert(errorAlert, message);
                } finally {
                    saveButton.disabled = false;
                }
            });

            if (!endpoint || !updateEndpoint) {
                showAlert(errorAlert, "Rewards policy API endpoints are not configured for this page.");
                setEditable(false);
                return;
            }

            loadPolicy();
        })();
    </script>
@endsection
