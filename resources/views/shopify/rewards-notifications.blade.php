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
            font-size: 1.2rem;
            line-height: 1.3;
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

        .policy-field,
        .policy-check-field {
            display: grid;
            gap: 8px;
        }

        .policy-field--full {
            grid-column: 1 / -1;
        }

        .is-hidden {
            display: none !important;
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

        .policy-field-help,
        .policy-field-guard {
            margin: 0;
            font-size: 12px;
            line-height: 1.5;
            color: rgba(15, 23, 42, 0.6);
        }

        .policy-field-guard {
            color: #9f1239;
            background: rgba(190, 24, 93, 0.08);
            border-radius: 8px;
            padding: 6px 8px;
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
            flex-wrap: wrap;
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

        .policy-button--secondary {
            border-color: rgba(15, 23, 42, 0.18);
            background: rgba(15, 23, 42, 0.06);
            color: #1e293b;
        }

        .policy-button--publish {
            border-color: rgba(15, 107, 146, 0.28);
            background: rgba(15, 107, 146, 0.12);
            color: #0f6b92;
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

        .policy-chips {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .policy-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 700;
            background: rgba(15, 23, 42, 0.08);
            color: rgba(15, 23, 42, 0.8);
        }

        .policy-chip--warning {
            background: rgba(234, 179, 8, 0.16);
            color: #854d0e;
        }

        .policy-chip--error {
            background: rgba(190, 24, 93, 0.16);
            color: #9f1239;
        }

        .policy-chip--success {
            background: rgba(15, 143, 97, 0.16);
            color: #0d6b4a;
        }

        .policy-preview {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .policy-preview-box {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.8);
            padding: 12px;
            font-size: 13px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.82);
            white-space: pre-wrap;
        }

        .policy-audit-list {
            margin: 12px 0 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 10px;
        }

        .policy-audit-item {
            border-radius: 10px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.9);
            padding: 10px 12px;
            font-size: 13px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.78);
        }

        .policy-split-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .policy-stat-grid {
            margin-top: 12px;
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }

        .policy-stat-card {
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.95);
            padding: 14px;
        }

        .policy-stat-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.56);
        }

        .policy-stat-value {
            display: block;
            margin-top: 8px;
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
        }

        .policy-stat-card--success .policy-stat-value {
            color: #0d6b4a;
        }

        .policy-stat-card--warning .policy-stat-value {
            color: #854d0e;
        }

        .policy-stat-card--error .policy-stat-value {
            color: #9f1239;
        }

        .policy-inline-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 13px;
            line-height: 1.5;
        }

        .policy-inline-table th,
        .policy-inline-table td {
            padding: 10px 8px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            text-align: left;
            vertical-align: top;
        }

        .policy-inline-table th {
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.56);
        }

        @media (max-width: 760px) {
            .policy-grid {
                grid-template-columns: 1fr;
            }

            .policy-split-grid {
                grid-template-columns: 1fr;
            }

            .policy-stat-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>

    <div
        id="tenant-rewards-policy"
        class="policy-shell"
        data-endpoint="{{ $rewardsPolicyEndpoint ?? ($policyEndpoint ?? '') }}"
        data-update-endpoint="{{ $rewardsPolicyUpdateEndpoint ?? ($policyUpdateEndpoint ?? '') }}"
        data-review-endpoint="{{ $rewardsPolicyReviewEndpoint ?? '' }}"
        data-alpha-endpoint="{{ $rewardsPolicyAlphaDefaultsEndpoint ?? '' }}"
        data-debug-endpoint="{{ $rewardsPolicyReminderDebugEndpoint ?? '' }}"
        data-history-endpoint="{{ $rewardsPolicyReminderHistoryEndpoint ?? '' }}"
        data-requeue-endpoint="{{ $rewardsPolicyReminderRequeueEndpoint ?? '' }}"
        data-skip-endpoint="{{ $rewardsPolicyReminderSkipEndpoint ?? '' }}"
        data-reminder-export-endpoint="{{ $rewardsPolicyReminderExportEndpoint ?? '' }}"
        data-issuance-export-endpoint="{{ $rewardsPolicyIssuanceExportEndpoint ?? '' }}"
        data-redemption-export-endpoint="{{ $rewardsPolicyRedemptionExportEndpoint ?? '' }}"
        data-expiring-export-endpoint="{{ $rewardsPolicyExpiringExportEndpoint ?? '' }}"
        data-finance-export-endpoint="{{ $rewardsPolicyFinanceExportEndpoint ?? '' }}"
        data-editable="{{ ($rewardsPolicyEditable ?? $rewardsEditorEditable ?? false) ? 'true' : 'false' }}"
    >
        <div class="policy-note">
            <strong>Program setup wizard</strong>
            <div>Choose how customers earn and use rewards in plain business language. Existing wallet, reward code, redemption, and reconciliation logic remains unchanged.</div>
        </div>

        <div id="policy-error" class="policy-alert policy-alert--error"></div>
        <div id="policy-success" class="policy-alert policy-alert--success"></div>

        <section class="policy-card">
            <h2 class="policy-heading">Review and Launch</h2>
            <p id="policy-summary" class="policy-copy">Loading current policy...</p>
            <div class="policy-split-grid" style="margin-top: 12px;">
                <div class="policy-preview-box" id="policy-live-summary">Current live summary loading...</div>
                <div class="policy-preview-box" id="policy-pending-summary">Pending publish summary will appear here.</div>
            </div>
            <div class="policy-chips">
                <span class="policy-chip" id="policy-version-chip">Version: --</span>
                <span class="policy-chip" id="policy-status-chip">Status: --</span>
                <span class="policy-chip" id="policy-updated-chip">Updated: --</span>
            </div>
            <div id="policy-message-chips" class="policy-chips"></div>
            <ul id="policy-errors" class="policy-list"></ul>
            <ul id="policy-warnings" class="policy-list"></ul>
            <ul id="policy-info" class="policy-list"></ul>
            <ul id="policy-change-preview" class="policy-list"></ul>
            <div class="policy-actions">
                <button id="policy-review" type="button" class="policy-button policy-button--secondary">Review changes</button>
                <button id="policy-alpha-defaults" type="button" class="policy-button policy-button--secondary">Apply Alpha defaults</button>
                <button id="policy-publish" type="button" class="policy-button policy-button--publish">Publish</button>
            </div>
        </section>

        <section class="policy-card">
            <h3 class="policy-heading">Launch Readiness</h3>
            <p id="policy-readiness-headline" class="policy-copy">Checking launch readiness...</p>
            <div id="policy-readiness-chips" class="policy-chips"></div>
            <ul id="policy-readiness-messages" class="policy-list"></ul>
            <ul id="policy-readiness-checklist" class="policy-list"></ul>
            <ul id="policy-health-signals" class="policy-list"></ul>
            <div class="policy-split-grid" style="margin-top: 12px;">
                <div class="policy-preview-box" id="policy-readiness-schedule">Reminder schedule summary loading...</div>
                <div class="policy-preview-box" id="policy-alpha-summary">Alpha starter summary loading...</div>
            </div>
        </section>

        <section class="policy-card">
            <h3 class="policy-heading">Automation and Alerts</h3>
            <p id="policy-automation-headline" class="policy-copy">Checking automation status...</p>
            <ul id="policy-automation-messages" class="policy-list"></ul>
            <ul id="policy-alert-list" class="policy-list"></ul>
            <div class="policy-split-grid" style="margin-top: 12px;">
                <div class="policy-preview-box" id="policy-automation-summary">Automation summary loading...</div>
                <div class="policy-preview-box" id="policy-permissions-summary">Team access summary loading...</div>
            </div>
            <div class="policy-split-grid" style="margin-top: 12px;">
                <div class="policy-preview-box" id="policy-usage-summary">Usage summary loading...</div>
                <div class="policy-preview-box" id="policy-simulation-summary">What-if summary loading...</div>
            </div>
        </section>

        <section class="policy-card">
            <h3 class="policy-heading">Customer Reminder Previews</h3>
            <p class="policy-copy">Customer-facing message previews update from live tenant policy settings.</p>
            <div class="policy-preview">
                <div class="policy-preview-box" id="policy-sms-preview">SMS preview loading...</div>
                <div class="policy-preview-box" id="policy-email-preview">Email preview loading...</div>
            </div>
        </section>

        <form id="policy-form" class="policy-shell">
            <section class="policy-card">
                <h3 class="policy-heading">Program Setup</h3>
                <p class="policy-copy">How this reward program is named and explained to customers.</p>
                <div class="policy-grid">
                    <div class="policy-field" data-policy-field="program_identity.program_name">
                        <label for="program_name">Program name</label>
                        <input id="program_name" name="program_name" type="text" maxlength="120" />
                    </div>
                    <div class="policy-field" data-policy-field="program_identity.short_label">
                        <label for="short_label">Short label</label>
                        <input id="short_label" name="short_label" type="text" maxlength="80" />
                    </div>
                    <div class="policy-field" data-policy-field="program_identity.terminology_mode">
                        <label for="terminology_mode">Rewards language</label>
                        <select id="terminology_mode" name="terminology_mode">
                            <option value="cash">Cash</option>
                            <option value="points">Points</option>
                        </select>
                    </div>
                    <div class="policy-field policy-field--full" data-policy-field="program_identity.description">
                        <label for="program_description">Program description</label>
                        <textarea id="program_description" name="program_description"></textarea>
                    </div>
                </div>
            </section>

            <section class="policy-card">
                <h3 class="policy-heading">How Rewards Turn Into Savings</h3>
                <p class="policy-copy">Define conversion, redemption increments, and order economics.</p>
                <div class="policy-grid">
                    <div class="policy-field" data-policy-field="value_model.currency_mode">
                        <label for="currency_mode">Value mode</label>
                        <select id="currency_mode" name="currency_mode">
                            <option value="fixed_cash">Fixed cash</option>
                            <option value="points_to_cash">Points to cash</option>
                        </select>
                    </div>
                    <div class="policy-field" data-policy-field="value_model.points_per_dollar">
                        <label for="points_per_dollar">Points per $1</label>
                        <input id="points_per_dollar" name="points_per_dollar" type="number" min="1" step="1" />
                    </div>
                    <div class="policy-field" data-policy-field="value_model.redeem_increment_dollars">
                        <label for="redeem_increment_dollars">Smallest reward a customer can use</label>
                        <input id="redeem_increment_dollars" name="redeem_increment_dollars" type="number" min="0.01" step="0.01" />
                    </div>
                    <div class="policy-field" data-policy-field="value_model.max_redeemable_per_order_dollars">
                        <label for="max_redeemable_per_order_dollars">Largest reward per order</label>
                        <input id="max_redeemable_per_order_dollars" name="max_redeemable_per_order_dollars" type="number" min="0.01" step="0.01" />
                    </div>
                    <div class="policy-field" data-policy-field="value_model.minimum_purchase_dollars">
                        <label for="minimum_purchase_dollars">Minimum order required to redeem</label>
                        <input id="minimum_purchase_dollars" name="minimum_purchase_dollars" type="number" min="0" step="0.01" />
                    </div>
                    <div class="policy-field" data-policy-field="finance_and_safety.max_open_codes">
                        <label for="max_open_codes">Maximum open reward codes</label>
                        <input id="max_open_codes" name="max_open_codes" type="number" min="1" step="1" />
                    </div>
                </div>
            </section>

            <section class="policy-card">
                <h3 class="policy-heading">Where Customers Earn Rewards</h3>
                <p class="policy-copy">Choose the launch-safe channel strategy for earning and redemption.</p>
                <div class="policy-grid">
                    <div class="policy-field" data-policy-field="earning_rules.rewardable_channels">
                        <label for="rewardable_channels">Channel strategy</label>
                        <select id="rewardable_channels" name="rewardable_channels">
                            <option value="online_only">Online only</option>
                            <option value="show_issued_online_redeemed">Show issued, online redeemed</option>
                            <option value="exclude_shows">Exclude shows</option>
                        </select>
                        <p class="policy-field-help">Online + show hybrid redemption is unavailable until the storefront flow is confirmed safe.</p>
                    </div>
                    <div class="policy-check-field" data-policy-field="earning_rules.candle_club_multiplier_enabled">
                        <label class="policy-check"><input id="candle_club_multiplier_enabled" type="checkbox" /> Candle Club multiplier enabled</label>
                    </div>
                    <div class="policy-field" data-policy-field="earning_rules.candle_club_multiplier_value">
                        <label for="candle_club_multiplier_value">Candle Club multiplier</label>
                        <input id="candle_club_multiplier_value" name="candle_club_multiplier_value" type="number" min="1" step="0.1" />
                        <p class="policy-field-help">Set to 2 for double Candle Cash while membership is active.</p>
                    </div>
                    <div class="policy-check-field" data-policy-field="earning_rules.candle_club_free_shipping_enabled">
                        <label class="policy-check"><input id="candle_club_free_shipping_enabled" type="checkbox" /> Free shipping for active Candle Club members</label>
                    </div>
                    <div class="policy-preview-box policy-field--full" id="policy-channel-summary">Channel strategy summary loading...</div>
                </div>
            </section>

            <section class="policy-card">
                <h3 class="policy-heading">How Customers Use Rewards</h3>
                <p class="policy-copy">Set reward code strategy, stacking behavior, and exclusions.</p>
                <div class="policy-grid">
                    <div class="policy-field" data-policy-field="redemption_rules.code_strategy">
                        <label for="code_strategy">How rewards are delivered</label>
                        <select id="code_strategy" name="code_strategy">
                            <option value="unique_per_customer">Unique per customer</option>
                            <option value="shared">Shared code</option>
                        </select>
                    </div>
                    <div class="policy-field" data-policy-field="redemption_rules.stacking_mode">
                        <label for="stacking_mode">Stacking behavior</label>
                        <select id="stacking_mode" name="stacking_mode">
                            <option value="no_stacking">No stacking</option>
                            <option value="shipping_only">Stack with shipping only</option>
                            <option value="selected_promo_types">Selected promo types only</option>
                        </select>
                    </div>
                    <div class="policy-field" data-policy-field="redemption_rules.max_codes_per_order">
                        <label for="max_codes_per_order">Codes allowed per order</label>
                        <input id="max_codes_per_order" name="max_codes_per_order" type="number" min="1" max="5" step="1" />
                    </div>
                    <div class="policy-field" data-policy-field="redemption_rules.platform_supports_multi_code">
                        <label for="platform_supports_multi_code">Platform supports multiple codes?</label>
                        <select id="platform_supports_multi_code" name="platform_supports_multi_code">
                            <option value="false">No</option>
                            <option value="true">Yes</option>
                        </select>
                    </div>
                    <div class="policy-field policy-field--full">
                        <label>Product exclusions</label>
                        <div class="policy-grid">
                            <div class="policy-check-field" data-policy-field="redemption_rules.exclusions.wholesale">
                                <label class="policy-check"><input id="exclude_wholesale" type="checkbox" /> Wholesale</label>
                            </div>
                            <div class="policy-check-field" data-policy-field="redemption_rules.exclusions.sale_items">
                                <label class="policy-check"><input id="exclude_sale_items" type="checkbox" /> Sale items</label>
                            </div>
                            <div class="policy-check-field" data-policy-field="redemption_rules.exclusions.bundles">
                                <label class="policy-check"><input id="exclude_bundles" type="checkbox" /> Bundles / gift sets</label>
                            </div>
                            <div class="policy-check-field" data-policy-field="redemption_rules.exclusions.limited_releases">
                                <label class="policy-check"><input id="exclude_limited_releases" type="checkbox" /> Limited releases</label>
                            </div>
                            <div class="policy-check-field" data-policy-field="redemption_rules.exclusions.subscriptions">
                                <label class="policy-check"><input id="exclude_subscriptions" type="checkbox" /> Subscriptions</label>
                            </div>
                        </div>
                    </div>
                    <div class="policy-field" data-policy-field="redemption_rules.exclusions.collections">
                        <label for="exclude_collections">Excluded collections</label>
                        <input id="exclude_collections" name="exclude_collections" type="text" placeholder="spring-drop, holiday" />
                        <p class="policy-field-help">Comma-separated collection handles or labels.</p>
                    </div>
                    <div class="policy-field" data-policy-field="redemption_rules.exclusions.tags">
                        <label for="exclude_tags">Excluded product tags</label>
                        <input id="exclude_tags" name="exclude_tags" type="text" placeholder="sale, subscription" />
                        <p class="policy-field-help">Comma-separated product tags.</p>
                    </div>
                    <div class="policy-field policy-field--full" data-policy-field="redemption_rules.exclusions.products">
                        <label for="exclude_products">Excluded products</label>
                        <input id="exclude_products" name="exclude_products" type="text" placeholder="candle-deluxe, mystery-box" />
                        <p class="policy-field-help">Comma-separated product handles or product ids.</p>
                    </div>
                    <div class="policy-preview-box policy-field--full" id="policy-exclusions-summary">Exclusion summary loading...</div>
                </div>
            </section>

            <section class="policy-card">
                <h3 class="policy-heading">Expiration and Reminder Plan</h3>
                <p class="policy-copy">Choose expiration behavior and reminder cadence.</p>
                <div class="policy-grid">
                    <div class="policy-field" data-policy-field="expiration_and_reminders.expiration_mode">
                        <label for="expiration_mode">When do rewards expire?</label>
                        <select id="expiration_mode" name="expiration_mode">
                            <option value="days_from_issue">Days from issue</option>
                            <option value="end_of_season">End of season</option>
                            <option value="none">No expiration</option>
                        </select>
                    </div>
                    <div class="policy-field" data-policy-field="expiration_and_reminders.expiration_days">
                        <label for="expiration_days">Expiration window (days)</label>
                        <input id="expiration_days" name="expiration_days" type="number" min="1" step="1" />
                    </div>
                    <div class="policy-field" data-policy-field="expiration_and_reminders.email_reminder_offsets_days">
                        <label for="email_reminder_offsets_days">Email reminder schedule (days before expiry)</label>
                        <input id="email_reminder_offsets_days" name="email_reminder_offsets_days" type="text" placeholder="14,7,1" />
                        <p class="policy-field-help">Comma-separated day offsets.</p>
                    </div>
                    <div class="policy-field" data-policy-field="expiration_and_reminders.sms_reminder_offsets_days">
                        <label for="sms_reminder_offsets_days">Text reminder schedule (days before expiry)</label>
                        <input id="sms_reminder_offsets_days" name="sms_reminder_offsets_days" type="text" placeholder="3" />
                        <p class="policy-field-help">Keep text reminders limited and close to expiry.</p>
                    </div>
                    <div class="policy-field" data-policy-field="expiration_and_reminders.sms_max_per_reward">
                        <label for="sms_max_per_reward">Max text reminders per reward</label>
                        <input id="sms_max_per_reward" name="sms_max_per_reward" type="number" min="0" step="1" />
                    </div>
                    <div class="policy-field" data-policy-field="expiration_and_reminders.sms_quiet_days">
                        <label for="sms_quiet_days">Text quiet period (days)</label>
                        <input id="sms_quiet_days" name="sms_quiet_days" type="number" min="0" step="1" />
                    </div>
                    <div class="policy-field policy-field--full">
                        <div class="policy-check-field" data-policy-field="expiration_and_reminders.email_enabled">
                            <label class="policy-check"><input id="email_enabled" type="checkbox" /> Reminder emails enabled</label>
                        </div>
                        <div class="policy-check-field" data-policy-field="expiration_and_reminders.sms_enabled">
                            <label class="policy-check"><input id="sms_enabled" type="checkbox" /> Reminder texts enabled</label>
                        </div>
                    </div>
                </div>
            </section>

            <section class="policy-card">
                <h3 class="policy-heading">Finance and Launch Controls</h3>
                <p class="policy-copy">Set risk and launch controls with business-safe defaults.</p>
                <div class="policy-grid">
                    <div class="policy-field" data-policy-field="finance_and_safety.liability_alert_threshold_dollars">
                        <label for="liability_alert_threshold_dollars">Liability alert threshold</label>
                        <input id="liability_alert_threshold_dollars" name="liability_alert_threshold_dollars" type="number" min="0" step="0.01" />
                    </div>
                    <div class="policy-field" data-policy-field="finance_and_safety.fraud_sensitivity_mode">
                        <label for="fraud_sensitivity_mode">Fraud sensitivity</label>
                        <select id="fraud_sensitivity_mode" name="fraud_sensitivity_mode">
                            <option value="low">Low</option>
                            <option value="balanced">Balanced</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="policy-field" data-policy-field="finance_and_safety.manual_grant_approval_threshold_dollars">
                        <label for="manual_grant_approval_threshold_dollars">Manual grant approval threshold</label>
                        <input id="manual_grant_approval_threshold_dollars" name="manual_grant_approval_threshold_dollars" type="number" min="0" step="0.01" />
                    </div>
                    <div class="policy-field" data-policy-field="access_state.launch_state">
                        <label for="launch_state">Program status</label>
                        <select id="launch_state" name="launch_state">
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                            <option value="scheduled">Scheduled</option>
                        </select>
                    </div>
                    <div class="policy-field" data-policy-field="access_state.scheduled_launch_at">
                        <label for="scheduled_launch_at">Scheduled launch time</label>
                        <input id="scheduled_launch_at" name="scheduled_launch_at" type="text" placeholder="2026-04-15T09:00:00-04:00" />
                    </div>
                    <div class="policy-check-field policy-field--full" data-policy-field="access_state.test_mode">
                        <label class="policy-check"><input id="test_mode" type="checkbox" /> Test mode enabled</label>
                    </div>
                </div>
            </section>

            <section class="policy-card">
                <h3 class="policy-heading">Automation and Report Delivery</h3>
                <p class="policy-copy">Choose whether reminders run automatically or only when a teammate starts them, where alerts go, and how finance receives scheduled reports.</p>
                <div class="policy-grid">
                    <div class="policy-field" data-policy-field="automation_and_reporting.automation_mode">
                        <label for="automation_mode">Automation mode</label>
                        <select id="automation_mode" name="automation_mode">
                            <option value="manual">Manual mode</option>
                            <option value="automatic">Run automatically</option>
                        </select>
                    </div>
                    <div class="policy-check-field" data-policy-field="automation_and_reporting.alert_email_enabled">
                        <label class="policy-check"><input id="alert_email_enabled" type="checkbox" /> Send operator alert emails</label>
                    </div>
                    <div class="policy-field" data-policy-field="automation_and_reporting.alert_email">
                        <label for="alert_email">Alert email</label>
                        <input id="alert_email" name="alert_email" type="email" placeholder="ops@example.com" />
                    </div>
                    <div class="policy-field" data-policy-field="automation_and_reporting.alert_no_sends_hours">
                        <label for="alert_no_sends_hours">No-send alert window (hours)</label>
                        <input id="alert_no_sends_hours" name="alert_no_sends_hours" type="number" min="1" max="168" step="1" />
                    </div>
                    <div class="policy-field" data-policy-field="automation_and_reporting.alert_high_skip_rate_percent">
                        <label for="alert_high_skip_rate_percent">High skip-rate alert (%)</label>
                        <input id="alert_high_skip_rate_percent" name="alert_high_skip_rate_percent" type="number" min="10" max="100" step="1" />
                    </div>
                    <div class="policy-field" data-policy-field="automation_and_reporting.alert_failure_spike_count">
                        <label for="alert_failure_spike_count">Failure spike alert count</label>
                        <input id="alert_failure_spike_count" name="alert_failure_spike_count" type="number" min="1" max="100" step="1" />
                    </div>
                    <div class="policy-field" data-policy-field="automation_and_reporting.report_frequency">
                        <label for="report_frequency">Finance report cadence</label>
                        <select id="report_frequency" name="report_frequency">
                            <option value="off">Off</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                        </select>
                    </div>
                    <div class="policy-field" data-policy-field="automation_and_reporting.report_delivery_mode">
                        <label for="report_delivery_mode">Finance report delivery</label>
                        <select id="report_delivery_mode" name="report_delivery_mode">
                            <option value="email_link">Email links</option>
                            <option value="download_link">Workspace only</option>
                        </select>
                    </div>
                    <div class="policy-field" data-policy-field="automation_and_reporting.report_email">
                        <label for="report_email">Finance report email</label>
                        <input id="report_email" name="report_email" type="email" placeholder="finance@example.com" />
                    </div>
                    <div class="policy-field" data-policy-field="automation_and_reporting.report_day_of_week">
                        <label for="report_day_of_week">Weekly report day</label>
                        <select id="report_day_of_week" name="report_day_of_week">
                            <option value="monday">Monday</option>
                            <option value="tuesday">Tuesday</option>
                            <option value="wednesday">Wednesday</option>
                            <option value="thursday">Thursday</option>
                            <option value="friday">Friday</option>
                            <option value="saturday">Saturday</option>
                            <option value="sunday">Sunday</option>
                        </select>
                    </div>
                </div>
            </section>

            <section class="policy-card">
                <h3 class="policy-heading">Team Access</h3>
                <p class="policy-copy">Choose who can edit settings, publish live changes, switch automation mode, and use support tools.</p>
                <div class="policy-grid">
                    <div class="policy-field" data-policy-field="team_access.edit_role">
                        <label for="edit_role">Who can edit program settings</label>
                        <select id="edit_role" name="edit_role">
                            <option value="tenant_member">Any team member</option>
                            <option value="marketing_manager_or_admin">Marketing lead or admin</option>
                            <option value="manager_or_admin">Manager or admin</option>
                            <option value="admin_only">Admin only</option>
                        </select>
                    </div>
                    <div class="policy-field" data-policy-field="team_access.publish_role">
                        <label for="publish_role">Who can publish live changes</label>
                        <select id="publish_role" name="publish_role">
                            <option value="tenant_member">Any team member</option>
                            <option value="marketing_manager_or_admin">Marketing lead or admin</option>
                            <option value="manager_or_admin">Manager or admin</option>
                            <option value="admin_only">Admin only</option>
                        </select>
                    </div>
                    <div class="policy-field" data-policy-field="team_access.support_role">
                        <label for="support_role">Who can use reminder support tools</label>
                        <select id="support_role" name="support_role">
                            <option value="tenant_member">Any team member</option>
                            <option value="marketing_manager_or_admin">Marketing lead or admin</option>
                            <option value="manager_or_admin">Manager or admin</option>
                            <option value="admin_only">Admin only</option>
                        </select>
                    </div>
                    <div class="policy-field" data-policy-field="team_access.automation_role">
                        <label for="automation_role">Who can switch automation mode</label>
                        <select id="automation_role" name="automation_role">
                            <option value="tenant_member">Any team member</option>
                            <option value="marketing_manager_or_admin">Marketing lead or admin</option>
                            <option value="manager_or_admin">Manager or admin</option>
                            <option value="admin_only">Admin only</option>
                        </select>
                    </div>
                </div>
            </section>

            <div class="policy-actions">
                <button id="policy-save" type="submit" class="policy-button">Save program settings</button>
            </div>
        </form>

        <section class="policy-card">
            <h3 class="policy-heading">Reminder Activity</h3>
            <p id="policy-report-headline" class="policy-copy">Loading reminder activity...</p>
            <div id="policy-report-cards" class="policy-stat-grid"></div>
            <div class="policy-split-grid" style="margin-top: 12px;">
                <div class="policy-preview-box" id="policy-report-queue">Reminder queue preview loading...</div>
                <div class="policy-preview-box" id="policy-report-expiring">Expiring rewards summary loading...</div>
            </div>
            <div class="policy-split-grid" style="margin-top: 12px;">
                <div>
                    <h4 class="policy-heading" style="font-size: 1rem;">Top Skip Reasons</h4>
                    <ul id="policy-report-skip-reasons" class="policy-list"></ul>
                </div>
                <div>
                    <h4 class="policy-heading" style="font-size: 1rem;">Recommended Next Steps</h4>
                    <ul id="policy-launch-next-steps" class="policy-list"></ul>
                </div>
            </div>
            <div class="policy-split-grid" style="margin-top: 12px;">
                <div class="policy-preview-box" id="policy-report-channel-breakdown">Channel breakdown loading...</div>
                <div class="policy-preview-box" id="policy-launch-summary">Launch summary loading...</div>
            </div>
        </section>

        <section class="policy-card">
            <h3 class="policy-heading">Finance Visibility</h3>
            <p class="policy-copy">Estimated liability, realized discount value, and expiring reward exposure based on current reward activity.</p>
            <div id="policy-finance-cards" class="policy-stat-grid"></div>
            <div class="policy-split-grid" style="margin-top: 12px;">
                <div class="policy-preview-box" id="policy-finance-summary">Finance summary loading...</div>
                <div class="policy-preview-box" id="policy-impact-summary">Impact summary loading...</div>
            </div>
            <ul id="policy-finance-signals" class="policy-list"></ul>
        </section>

        <section class="policy-card">
            <h3 class="policy-heading">Reporting Filters and Exports</h3>
            <p class="policy-copy">Filter reminder activity by time, channel, and status, then export the current rewards picture as CSV.</p>
            <div class="policy-grid">
                <div class="policy-field">
                    <label for="report_date_from">From date</label>
                    <input id="report_date_from" type="text" placeholder="2026-03-01" />
                </div>
                <div class="policy-field">
                    <label for="report_date_to">To date</label>
                    <input id="report_date_to" type="text" placeholder="2026-03-31" />
                </div>
                <div class="policy-field">
                    <label for="report_channel">Channel</label>
                    <select id="report_channel">
                        <option value="">All channels</option>
                        <option value="email">Email</option>
                        <option value="sms">Text</option>
                    </select>
                </div>
                <div class="policy-field">
                    <label for="report_status">Status</label>
                    <select id="report_status">
                        <option value="">All statuses</option>
                        <option value="sent">Sent</option>
                        <option value="skipped">Skipped</option>
                        <option value="failed">Failed</option>
                        <option value="attempted">Attempted</option>
                        <option value="scheduled">Scheduled</option>
                    </select>
                </div>
                <div class="policy-field">
                    <label for="report_reward_type">Reward type</label>
                    <input id="report_reward_type" type="text" placeholder="order_purchase_earn" />
                </div>
                <div class="policy-field">
                    <label for="report_expiring_days">Expiring soon window</label>
                    <input id="report_expiring_days" type="number" min="1" max="90" step="1" value="14" />
                </div>
            </div>
            <div class="policy-actions">
                <button id="policy-apply-report-filters" type="button" class="policy-button policy-button--secondary">Apply filters</button>
                <button id="policy-export-reminders" type="button" class="policy-button policy-button--secondary">Export reminder history</button>
                <button id="policy-export-issuance" type="button" class="policy-button policy-button--secondary">Export reward issuance</button>
                <button id="policy-export-redemption" type="button" class="policy-button policy-button--secondary">Export reward redemption</button>
                <button id="policy-export-expiring" type="button" class="policy-button policy-button--secondary">Export expiring rewards</button>
                <button id="policy-export-finance" type="button" class="policy-button policy-button--secondary">Export finance snapshot</button>
            </div>
            <ul id="policy-report-table" class="policy-audit-list"></ul>
        </section>

        <section class="policy-card">
            <h3 class="policy-heading">Reminder Debug and Support</h3>
            <p class="policy-copy">Look up one reward or customer, explain reminder eligibility, and run narrowly scoped support actions with a reason.</p>
            <div class="policy-grid">
                <div class="policy-field">
                    <label for="support_reward_identifier">Reward id</label>
                    <input id="support_reward_identifier" type="text" placeholder="earned-bucket:tx:123" />
                </div>
                <div class="policy-field">
                    <label for="support_marketing_profile_id">Customer id</label>
                    <input id="support_marketing_profile_id" type="number" min="1" step="1" placeholder="123" />
                </div>
                <div class="policy-field">
                    <label for="support_channel">Channel</label>
                    <select id="support_channel">
                        <option value="">Email or text</option>
                        <option value="email">Email</option>
                        <option value="sms">Text</option>
                    </select>
                </div>
                <div class="policy-field">
                    <label for="support_timing_days">Timing days before expiry</label>
                    <input id="support_timing_days" type="number" min="0" max="365" step="1" placeholder="7" />
                </div>
                <div class="policy-field policy-field--full">
                    <label for="support_reason">Support note</label>
                    <input id="support_reason" type="text" maxlength="240" placeholder="Customer asked why this reminder did not send." />
                </div>
            </div>
            <div class="policy-actions">
                <button id="policy-run-debug" type="button" class="policy-button policy-button--secondary">Explain reminder</button>
                <button id="policy-load-customer-history" type="button" class="policy-button policy-button--secondary">Load customer history</button>
                <button id="policy-requeue-reminder" type="button" class="policy-button policy-button--secondary">Requeue eligible reminder</button>
                <button id="policy-skip-reminder" type="button" class="policy-button policy-button--secondary">Mark reminder skipped</button>
            </div>
            <div class="policy-split-grid" style="margin-top: 12px;">
                <div class="policy-preview-box" id="policy-debug-summary">Reminder explanation will appear here.</div>
                <div class="policy-preview-box" id="policy-support-summary">Support action results will appear here.</div>
            </div>
            <ul id="policy-debug-list" class="policy-audit-list"></ul>
        </section>

        <section class="policy-card">
            <h3 class="policy-heading">Change History</h3>
            <p class="policy-copy">Recent program setting updates for audit and accountability.</p>
            <ul id="policy-audit" class="policy-audit-list"></ul>
        </section>

        <section class="policy-card">
            <h3 class="policy-heading">Support Action History</h3>
            <p class="policy-copy">Recent reminder support actions with reasons so the team can trace what changed and why.</p>
            <ul id="policy-support-history" class="policy-audit-list"></ul>
        </section>

        <section class="policy-card">
            <h3 class="policy-heading">Customer Reminder History</h3>
            <p class="policy-copy">Recent reminder scheduling and send activity tied back to the live policy version.</p>
            <ul id="policy-reminder-history" class="policy-audit-list"></ul>
        </section>
    </div>

    <script>
        (() => {
            const root = document.getElementById("tenant-rewards-policy");
            if (!root) {
                return;
            }

            const endpoint = root.dataset.endpoint;
            const updateEndpoint = root.dataset.updateEndpoint;
            const reviewEndpoint = root.dataset.reviewEndpoint;
            const alphaEndpoint = root.dataset.alphaEndpoint;
            const debugEndpoint = root.dataset.debugEndpoint;
            const historyEndpoint = root.dataset.historyEndpoint;
            const requeueEndpoint = root.dataset.requeueEndpoint;
            const skipEndpoint = root.dataset.skipEndpoint;
            const reminderExportEndpoint = root.dataset.reminderExportEndpoint;
            const issuanceExportEndpoint = root.dataset.issuanceExportEndpoint;
            const redemptionExportEndpoint = root.dataset.redemptionExportEndpoint;
            const expiringExportEndpoint = root.dataset.expiringExportEndpoint;
            const financeExportEndpoint = root.dataset.financeExportEndpoint;
            const editable = root.dataset.editable === "true";

            const form = document.getElementById("policy-form");
            const saveButton = document.getElementById("policy-save");
            const reviewButton = document.getElementById("policy-review");
            const publishButton = document.getElementById("policy-publish");
            const alphaButton = document.getElementById("policy-alpha-defaults");
            const errorAlert = document.getElementById("policy-error");
            const successAlert = document.getElementById("policy-success");
            const summaryEl = document.getElementById("policy-summary");
            const errorsEl = document.getElementById("policy-errors");
            const warningsEl = document.getElementById("policy-warnings");
            const infoEl = document.getElementById("policy-info");
            const messageChipsEl = document.getElementById("policy-message-chips");
            const versionChipEl = document.getElementById("policy-version-chip");
            const statusChipEl = document.getElementById("policy-status-chip");
            const updatedChipEl = document.getElementById("policy-updated-chip");
            const liveSummaryEl = document.getElementById("policy-live-summary");
            const pendingSummaryEl = document.getElementById("policy-pending-summary");
            const changePreviewEl = document.getElementById("policy-change-preview");
            const readinessHeadlineEl = document.getElementById("policy-readiness-headline");
            const readinessChipsEl = document.getElementById("policy-readiness-chips");
            const readinessMessagesEl = document.getElementById("policy-readiness-messages");
            const readinessChecklistEl = document.getElementById("policy-readiness-checklist");
            const healthSignalsEl = document.getElementById("policy-health-signals");
            const readinessScheduleEl = document.getElementById("policy-readiness-schedule");
            const alphaSummaryEl = document.getElementById("policy-alpha-summary");
            const automationHeadlineEl = document.getElementById("policy-automation-headline");
            const automationMessagesEl = document.getElementById("policy-automation-messages");
            const alertListEl = document.getElementById("policy-alert-list");
            const automationSummaryEl = document.getElementById("policy-automation-summary");
            const permissionsSummaryEl = document.getElementById("policy-permissions-summary");
            const usageSummaryEl = document.getElementById("policy-usage-summary");
            const simulationSummaryEl = document.getElementById("policy-simulation-summary");
            const smsPreviewEl = document.getElementById("policy-sms-preview");
            const emailPreviewEl = document.getElementById("policy-email-preview");
            const channelSummaryEl = document.getElementById("policy-channel-summary");
            const exclusionsSummaryEl = document.getElementById("policy-exclusions-summary");
            const reportHeadlineEl = document.getElementById("policy-report-headline");
            const reportCardsEl = document.getElementById("policy-report-cards");
            const reportQueueEl = document.getElementById("policy-report-queue");
            const reportExpiringEl = document.getElementById("policy-report-expiring");
            const reportSkipReasonsEl = document.getElementById("policy-report-skip-reasons");
            const launchNextStepsEl = document.getElementById("policy-launch-next-steps");
            const reportChannelBreakdownEl = document.getElementById("policy-report-channel-breakdown");
            const launchSummaryEl = document.getElementById("policy-launch-summary");
            const financeCardsEl = document.getElementById("policy-finance-cards");
            const financeSummaryEl = document.getElementById("policy-finance-summary");
            const financeSignalsEl = document.getElementById("policy-finance-signals");
            const impactSummaryEl = document.getElementById("policy-impact-summary");
            const reportDateFromEl = document.getElementById("report_date_from");
            const reportDateToEl = document.getElementById("report_date_to");
            const reportChannelEl = document.getElementById("report_channel");
            const reportStatusEl = document.getElementById("report_status");
            const reportRewardTypeEl = document.getElementById("report_reward_type");
            const reportExpiringDaysEl = document.getElementById("report_expiring_days");
            const applyReportFiltersButton = document.getElementById("policy-apply-report-filters");
            const exportRemindersButton = document.getElementById("policy-export-reminders");
            const exportIssuanceButton = document.getElementById("policy-export-issuance");
            const exportRedemptionButton = document.getElementById("policy-export-redemption");
            const exportExpiringButton = document.getElementById("policy-export-expiring");
            const exportFinanceButton = document.getElementById("policy-export-finance");
            const reportTableEl = document.getElementById("policy-report-table");
            const supportRewardIdentifierEl = document.getElementById("support_reward_identifier");
            const supportMarketingProfileIdEl = document.getElementById("support_marketing_profile_id");
            const supportChannelEl = document.getElementById("support_channel");
            const supportTimingDaysEl = document.getElementById("support_timing_days");
            const supportReasonEl = document.getElementById("support_reason");
            const runDebugButton = document.getElementById("policy-run-debug");
            const loadCustomerHistoryButton = document.getElementById("policy-load-customer-history");
            const requeueReminderButton = document.getElementById("policy-requeue-reminder");
            const skipReminderButton = document.getElementById("policy-skip-reminder");
            const debugSummaryEl = document.getElementById("policy-debug-summary");
            const supportSummaryEl = document.getElementById("policy-support-summary");
            const debugListEl = document.getElementById("policy-debug-list");
            const auditEl = document.getElementById("policy-audit");
            const supportHistoryEl = document.getElementById("policy-support-history");
            const reminderHistoryEl = document.getElementById("policy-reminder-history");

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

            function parseList(raw) {
                return String(raw || "")
                    .split(",")
                    .map((value) => value.trim())
                    .filter((value) => value.length > 0);
            }

            function toInt(value) {
                const parsed = Number.parseInt(String(value ?? "").trim(), 10);
                return Number.isFinite(parsed) ? parsed : null;
            }

            function buildQuery(params) {
                const search = new URLSearchParams();
                Object.entries(params || {}).forEach(([key, value]) => {
                    if (value === null || value === undefined || value === "") return;
                    search.set(key, String(value));
                });
                const query = search.toString();
                return query ? `?${query}` : "";
            }

            function formatTimestamp(value) {
                if (!value) return "--";
                const date = new Date(value);
                if (Number.isNaN(date.getTime())) return String(value);
                return date.toLocaleString();
            }

            function collectReportFilters() {
                return {
                    date_from: reportDateFromEl?.value?.trim() || null,
                    date_to: reportDateToEl?.value?.trim() || null,
                    channel: reportChannelEl?.value || null,
                    status: reportStatusEl?.value || null,
                    reward_type: reportRewardTypeEl?.value?.trim() || null,
                    expiring_soon_days: toInt(reportExpiringDaysEl?.value) || 14,
                };
            }

            function collectSupportPayload() {
                return {
                    reward_identifier: supportRewardIdentifierEl?.value?.trim() || null,
                    marketing_profile_id: toInt(supportMarketingProfileIdEl?.value),
                    channel: supportChannelEl?.value || null,
                    timing_days_before_expiration: toInt(supportTimingDaysEl?.value),
                    reason: supportReasonEl?.value?.trim() || null,
                };
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

            async function downloadCsv(url, params, fallbackName) {
                const headers = await authHeaders();
                const response = await fetch(`${url}${buildQuery(params)}`, {
                    method: "GET",
                    headers,
                    credentials: "same-origin",
                });

                if (!response.ok) {
                    const payload = await response.json().catch(() => ({ message: "Export failed." }));
                    throw new Error(payload?.message || "Export failed.");
                }

                const blob = await response.blob();
                const disposition = response.headers.get("Content-Disposition") || "";
                const matchedName = disposition.match(/filename=\"?([^\";]+)\"?/i);
                const filename = matchedName?.[1] || fallbackName;
                const downloadUrl = window.URL.createObjectURL(blob);
                const link = document.createElement("a");
                link.href = downloadUrl;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.URL.revokeObjectURL(downloadUrl);
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
                setField("rewardable_channels", policy?.earning_rules?.rewardable_channels);
                setField("candle_club_multiplier_enabled", policy?.earning_rules?.candle_club_multiplier_enabled);
                setField("candle_club_multiplier_value", policy?.earning_rules?.candle_club_multiplier_value);
                setField("candle_club_free_shipping_enabled", policy?.earning_rules?.candle_club_free_shipping_enabled);

                setField("code_strategy", policy?.redemption_rules?.code_strategy);
                setField("stacking_mode", policy?.redemption_rules?.stacking_mode);
                setField("max_codes_per_order", policy?.redemption_rules?.max_codes_per_order);
                setField("platform_supports_multi_code", policy?.redemption_rules?.platform_supports_multi_code ? "true" : "false");

                setField("exclude_wholesale", policy?.redemption_rules?.exclusions?.wholesale);
                setField("exclude_sale_items", policy?.redemption_rules?.exclusions?.sale_items);
                setField("exclude_bundles", policy?.redemption_rules?.exclusions?.bundles);
                setField("exclude_limited_releases", policy?.redemption_rules?.exclusions?.limited_releases);
                setField("exclude_subscriptions", policy?.redemption_rules?.exclusions?.subscriptions);
                setField("exclude_collections", (policy?.redemption_rules?.exclusions?.collections || []).join(","));
                setField("exclude_tags", (policy?.redemption_rules?.exclusions?.tags || []).join(","));
                setField("exclude_products", (policy?.redemption_rules?.exclusions?.products || []).join(","));

                setField("expiration_mode", policy?.expiration_and_reminders?.expiration_mode);
                setField("expiration_days", policy?.expiration_and_reminders?.expiration_days);
                setField("email_reminder_offsets_days", (policy?.expiration_and_reminders?.email_reminder_offsets_days || policy?.expiration_and_reminders?.reminder_offsets_days || []).join(","));
                setField("sms_reminder_offsets_days", (policy?.expiration_and_reminders?.sms_reminder_offsets_days || []).join(","));
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

                setField("automation_mode", policy?.automation_and_reporting?.automation_mode);
                setField("alert_email_enabled", policy?.automation_and_reporting?.alert_email_enabled);
                setField("alert_email", policy?.automation_and_reporting?.alert_email);
                setField("alert_no_sends_hours", policy?.automation_and_reporting?.alert_no_sends_hours);
                setField("alert_high_skip_rate_percent", policy?.automation_and_reporting?.alert_high_skip_rate_percent);
                setField("alert_failure_spike_count", policy?.automation_and_reporting?.alert_failure_spike_count);
                setField("report_frequency", policy?.automation_and_reporting?.report_frequency);
                setField("report_delivery_mode", policy?.automation_and_reporting?.report_delivery_mode);
                setField("report_email", policy?.automation_and_reporting?.report_email);
                setField("report_day_of_week", policy?.automation_and_reporting?.report_day_of_week);

                setField("edit_role", policy?.team_access?.edit_role);
                setField("publish_role", policy?.team_access?.publish_role);
                setField("support_role", policy?.team_access?.support_role);
                setField("automation_role", policy?.team_access?.automation_role);
            }

            function renderMessageList(target, items, emptyMessage) {
                if (!target) return;
                target.innerHTML = "";

                const rows = Array.isArray(items) ? items : [];
                if (rows.length === 0) {
                    const item = document.createElement("li");
                    item.textContent = emptyMessage;
                    target.appendChild(item);
                    return;
                }

                rows.forEach((row) => {
                    const item = document.createElement("li");
                    item.textContent = row?.message || "Message";
                    target.appendChild(item);
                });
            }

            function renderSummary(policy) {
                summaryEl.textContent = policy?.summary || "No summary available.";
                liveSummaryEl.textContent = `Current live policy\n\n${policy?.publish_preview?.live_summary || policy?.summary || "No live summary available."}`;
                pendingSummaryEl.textContent = `Pending publish view\n\n${policy?.publish_preview?.pending_summary || policy?.summary || "Run Review changes to preview the next publish."}`;
                channelSummaryEl.textContent = policy?.channel_strategy_summary || "Channel strategy summary is unavailable.";
                exclusionsSummaryEl.textContent = policy?.exclusions_summary || "Exclusion summary is unavailable.";

                const messages = policy?.messages || {};
                renderMessageList(errorsEl, messages.errors, "No high-severity business alerts.");
                renderMessageList(warningsEl, messages.warnings, "No medium-severity business warnings.");
                renderMessageList(infoEl, messages.info, "No informational notes.");
                renderMessageList(changePreviewEl, policy?.publish_preview?.change_preview, "No pending changes yet.");

                messageChipsEl.innerHTML = "";
                const chipRows = [
                    { label: `Errors: ${(messages?.errors || []).length}`, className: "policy-chip--error" },
                    { label: `Warnings: ${(messages?.warnings || []).length}`, className: "policy-chip--warning" },
                    { label: `Info: ${(messages?.info || []).length}`, className: "policy-chip--success" },
                ];

                chipRows.forEach((chipRow) => {
                    const chip = document.createElement("span");
                    chip.className = `policy-chip ${chipRow.className}`;
                    chip.textContent = chipRow.label;
                    messageChipsEl.appendChild(chip);
                });
            }

            function renderReadiness(policy) {
                const readiness = policy?.readiness || {};
                const alphaPreset = policy?.alpha_preset || {};

                readinessHeadlineEl.textContent = readiness?.headline || "Launch readiness details are unavailable.";

                readinessChipsEl.innerHTML = "";
                [
                    { label: `Launch status: ${readiness?.launch_state || "published"}`, className: "policy-chip" },
                    { label: `Policy version: ${readiness?.policy_version ?? "--"}`, className: "policy-chip" },
                    { label: `Warnings: ${readiness?.warning_count ?? 0}`, className: "policy-chip policy-chip--warning" },
                    { label: `Alpha starter: ${readiness?.alpha_defaults_applied ? "Applied" : "Custom"}`, className: readiness?.alpha_defaults_applied ? "policy-chip policy-chip--success" : "policy-chip" },
                ].forEach((row) => {
                    const chip = document.createElement("span");
                    chip.className = row.className;
                    chip.textContent = row.label;
                    readinessChipsEl.appendChild(chip);
                });

                renderMessageList(readinessMessagesEl, (readiness?.messages || []).map((message) => ({ message })), "No launch-readiness notes.");
                renderMessageList(
                    readinessChecklistEl,
                    (readiness?.checklist || []).map((item) => ({
                        message: `${item?.label || "Checklist item"}: ${(item?.status || "needs_attention") === "ready" ? "Ready" : "Needs attention"}`,
                    })),
                    "No launch checklist available."
                );

                const emailChannel = readiness?.channels?.email || {};
                const smsChannel = readiness?.channels?.sms || {};
                readinessScheduleEl.textContent = [
                    readiness?.summary || "No reminder schedule summary available.",
                    "",
                    `Email reminders: ${emailChannel?.enabled ? "On" : "Off"}${emailChannel?.enabled ? ` · ${emailChannel?.offsets_days?.join(", ") || "no timing selected"}` : ""}`,
                    `Text reminders: ${smsChannel?.enabled ? "On" : "Off"}${smsChannel?.enabled ? ` · ${smsChannel?.offsets_days?.join(", ") || "no timing selected"}` : ""}`,
                    `Reminder timing valid: ${readiness?.schedule_valid ? "Yes" : "Needs attention"}`,
                ].join("\n");

                alphaSummaryEl.textContent = [
                    alphaPreset?.headline || "Alpha starter summary unavailable.",
                    "",
                    ...((alphaPreset?.items || []).slice(0, 8)),
                ].join("\n");
            }

            function renderAutomation(policy) {
                const automation = policy?.automation || {};
                const permissions = policy?.permissions || {};
                const usage = policy?.usage_indicators || {};
                const simulation = policy?.simulation_view || {};
                const automationModeLabel = automation?.automation_mode === "manual" ? "Manual mode" : "Automatic";

                automationHeadlineEl.textContent = automation?.headline || "Automation status is unavailable.";
                renderMessageList(
                    automationMessagesEl,
                    (automation?.messages || []).map((message) => ({ message })),
                    "No automation notes right now."
                );
                renderMessageList(
                    alertListEl,
                    (policy?.alerts || []).map((alert) => ({ message: alert?.message || "Alert" })),
                    "No rewards automation alerts right now."
                );

                automationSummaryEl.textContent = [
                    automation?.headline || "Automation status unavailable.",
                    "",
                    `Automation mode: ${automationModeLabel}`,
                    `Current state: ${automation?.status || "--"}`,
                    `Last run: ${formatTimestamp(automation?.last_run_at)}`,
                    `Last success: ${formatTimestamp(automation?.last_success_at)}`,
                    `Last failure: ${formatTimestamp(automation?.last_failure_at)}`,
                    `Failure count: ${automation?.failure_count ?? 0}`,
                    automation?.last_error_message ? `Last issue: ${automation.last_error_message}` : "",
                ].filter(Boolean).join("\n");

                const permissionLines = Object.entries(permissions?.actions || {}).map(([key, row]) => {
                    return `${row?.label || key}: ${row?.required_role_label || "--"}${row?.allowed === false ? " • blocked for current user" : ""}`;
                });
                permissionsSummaryEl.textContent = [
                    permissions?.headline || "Team access summary unavailable.",
                    "",
                    `Current access mode: ${permissions?.mode || "--"}`,
                    `Current user: ${permissions?.current_user_label || "Shopify admin session"}`,
                    "",
                    ...permissionLines,
                ].join("\n");

                usageSummaryEl.textContent = [
                    usage?.headline || "Usage summary unavailable.",
                    "",
                    ...((usage?.items || []).slice(0, 6).map((row) => {
                        const limit = row?.included_limit ? ` / ${row.included_limit}` : "";
                        const state = row?.usage_state && row.usage_state !== "normal" ? ` • ${row.usage_state}` : "";
                        return `${row?.label || "Metric"}: ${row?.value ?? 0}${limit}${state}`;
                    })),
                ].join("\n");

                simulationSummaryEl.textContent = [
                    simulation?.headline || "What-if summary unavailable.",
                    "",
                    `Current reward value: $${Number(simulation?.current?.reward_value || 0).toFixed(2)}`,
                    `Scenario reward value: $${Number(simulation?.scenario?.reward_value || 0).toFixed(2)}`,
                    `Current expiration: ${simulation?.current?.expiration_days ?? "--"} days`,
                    `Scenario expiration: ${simulation?.scenario?.expiration_days ?? "--"} days`,
                    `Estimated cost impact: ${simulation?.estimated_cost_impact?.formatted_value || "--"}`,
                    "",
                    ...((simulation?.messages || []).slice(0, 3)),
                ].join("\n");
            }

            function renderVersioning(policy) {
                const versioning = policy?.versioning || {};
                const launchState = policy?.access_state?.launch_state || "published";

                versionChipEl.textContent = `Version: ${versioning?.current_version ?? "--"}`;
                statusChipEl.textContent = `Status: ${launchState}`;
                updatedChipEl.textContent = `Updated: ${formatTimestamp(versioning?.last_updated_at)}`;
            }

            function renderPreviews(policy) {
                const previews = policy?.message_previews || {};
                const sms = previews?.sms || {};
                const email = previews?.email || {};

                smsPreviewEl.textContent = [
                    `SMS (${sms?.enabled ? "enabled" : "disabled"})`,
                    "",
                    sms?.body || "No SMS preview available.",
                    "",
                    `Characters: ${sms?.character_count ?? 0}`,
                    `Segments: ${sms?.segments ?? 1}`,
                ].join("\n");

                emailPreviewEl.textContent = [
                    `Email (${email?.enabled ? "enabled" : "disabled"})`,
                    `Subject: ${email?.subject || "--"}`,
                    `Preview text: ${email?.preview_text || "--"}`,
                    "",
                    `Headline: ${email?.headline || "--"}`,
                    email?.body || "No email body preview available.",
                    "",
                    `CTA: ${email?.cta || "--"}`,
                ].join("\n");
            }

            function renderReportFilters(policy) {
                const filters = policy?.reminder_reporting?.filters || {};
                setField("report_date_from", filters?.date_from || "");
                setField("report_date_to", filters?.date_to || "");
                setField("report_channel", filters?.channel || "");
                setField("report_status", filters?.status || "");
                setField("report_reward_type", filters?.reward_type || "");
                setField("report_expiring_days", filters?.expiring_soon_days || 14);
            }

            function renderFinance(policy) {
                const finance = policy?.finance_summary || {};
                const impact = policy?.reminder_reporting?.impact_view || {};

                financeCardsEl.innerHTML = "";
                (finance?.cards || []).forEach((card) => {
                    const item = document.createElement("div");
                    item.className = `policy-stat-card policy-stat-card--${card?.tone || "neutral"}`;
                    item.innerHTML = `<span class="policy-stat-label">${card?.label || "Metric"}</span><span class="policy-stat-value">${card?.value ?? "--"}</span>`;
                    financeCardsEl.appendChild(item);
                });

                financeSummaryEl.textContent = [
                    finance?.headline || "Finance summary unavailable.",
                    "",
                    `Outstanding liability: ${finance?.outstanding_liability?.formatted_amount || "--"} across ${finance?.outstanding_liability?.open_reward_count ?? 0} open rewards`,
                    `Rewards issued: ${finance?.issued?.formatted_amount || "--"}`,
                    `Rewards redeemed: ${finance?.redeemed?.formatted_amount || "--"}`,
                    `Unredeemed value: ${finance?.unredeemed?.formatted_amount || "--"}`,
                    `Breakage estimate: ${finance?.breakage_estimate?.formatted_amount || "--"}`,
                    `Realized discount value: ${finance?.realized_discount_value?.formatted_amount || "--"}`,
                ].join("\n");

                impactSummaryEl.textContent = [
                    impact?.headline || "Impact summary unavailable.",
                    "",
                    `${impact?.estimated_reminder_volume?.label || "Estimated reminder volume"}: ${impact?.estimated_reminder_volume?.value ?? "--"}`,
                    `${impact?.estimated_expiring_rewards?.label || "Estimated expiring reward value"}: ${impact?.estimated_expiring_rewards?.formatted_value || "--"}`,
                    `${impact?.estimated_redemption_exposure?.label || "Estimated redemption exposure"}: ${impact?.estimated_redemption_exposure?.formatted_value || "--"}`,
                    "",
                    ...((impact?.messages || []).slice(0, 3)),
                ].join("\n");

                renderMessageList(
                    financeSignalsEl,
                    (finance?.signals || []).map((signal) => ({ message: signal?.message || "Finance signal" })),
                    "No finance signals yet."
                );
            }

            function renderReporting(policy) {
                const report = policy?.reminder_reporting || {};
                reportHeadlineEl.textContent = report?.headline || "Reminder activity is unavailable.";

                reportCardsEl.innerHTML = "";
                (report?.summary_cards || []).forEach((card) => {
                    const item = document.createElement("div");
                    item.className = `policy-stat-card policy-stat-card--${card?.tone || "neutral"}`;
                    item.innerHTML = `<span class="policy-stat-label">${card?.label || "Metric"}</span><span class="policy-stat-value">${card?.value ?? 0}</span>`;
                    reportCardsEl.appendChild(item);
                });

                const queuePreview = report?.queue_preview || {};
                reportQueueEl.textContent = [
                    `Current reminder queue • Policy v${report?.policy_version ?? "--"}`,
                    "",
                    `Due now: ${queuePreview?.due_now_count ?? 0}`,
                    `Upcoming: ${queuePreview?.upcoming_count ?? 0}`,
                    `Current schedule skips: ${queuePreview?.schedule_skip_count ?? 0}`,
                    "",
                    ...((queuePreview?.due_now || []).slice(0, 5).map((row) => {
                        return `${row?.customer_name || "Customer"} • ${(row?.channel || "channel").toUpperCase()} • ${row?.formatted_remaining_amount || row?.remaining_amount || "--"} • ${row?.timing_days_before_expiration ?? "--"} days before expiry`;
                    })),
                ].join("\n");

                const expiringSoon = report?.expiring_soon || {};
                reportExpiringEl.textContent = [
                    `Rewards expiring soon`,
                    "",
                    `Rewards: ${expiringSoon?.count ?? 0}`,
                    `Value: ${expiringSoon?.amount ?? 0}`,
                    "",
                    ...((expiringSoon?.items || []).slice(0, 5).map((row) => {
                        return `${row?.customer_name || "Customer"} • ${row?.formatted_remaining_amount || row?.remaining_amount || "--"} • ${formatTimestamp(row?.expires_at)}`;
                    })),
                ].join("\n");

                renderMessageList(
                    reportSkipReasonsEl,
                    (report?.top_skip_reasons || []).map((row) => ({
                        message: `${row?.code || "other"}: ${row?.count ?? 0}`,
                    })),
                    "No skip reasons recorded yet."
                );

                renderMessageList(
                    launchNextStepsEl,
                    (policy?.readiness?.next_steps || []).map((message) => ({ message })),
                    "No launch follow-up steps are currently needed."
                );

                const channelBreakdown = report?.channel_breakdown || {};
                reportChannelBreakdownEl.textContent = [
                    `Channel breakdown`,
                    "",
                    `Email • sent ${channelBreakdown?.email?.sent ?? 0} • skipped ${channelBreakdown?.email?.skipped ?? 0} • failed ${channelBreakdown?.email?.failed ?? 0}`,
                    `Text • sent ${channelBreakdown?.sms?.sent ?? 0} • skipped ${channelBreakdown?.sms?.skipped ?? 0} • failed ${channelBreakdown?.sms?.failed ?? 0}`,
                ].join("\n");

                launchSummaryEl.textContent = [
                    `Launch summary`,
                    "",
                    policy?.readiness?.launch_summary || policy?.summary || "No launch summary available.",
                    "",
                    `Current live version: ${policy?.versioning?.current_version ?? 0}`,
                    `Launch status: ${policy?.readiness?.status || policy?.access_state?.launch_state || "unknown"}`,
                ].join("\n");

                renderMessageList(
                    reportTableEl,
                    (report?.activity_table?.items || []).map((row) => ({
                        message: `${formatTimestamp(row?.occurred_at || row?.scheduled_at)} • ${(row?.channel || "channel").toUpperCase()} • ${row?.status || "scheduled"} • ${row?.reward_source_label || row?.reward_source_key || "reward"} • ${row?.reward_identifier || "reward"}${row?.skip_reason ? ` • ${row.skip_reason}` : ""}`,
                    })),
                    "No reminder activity matched these filters."
                );
            }

            function renderHealth(policy) {
                renderMessageList(
                    healthSignalsEl,
                    (policy?.reminder_reporting?.health_signals || []).map((signal) => ({ message: signal?.message || "Health signal" })),
                    "No health signals right now."
                );
            }

            function renderAudit(policy) {
                auditEl.innerHTML = "";
                const auditRows = Array.isArray(policy?.audit_history) ? policy.audit_history : [];

                if (auditRows.length === 0) {
                    const item = document.createElement("li");
                    item.className = "policy-audit-item";
                    item.textContent = "No program setting updates recorded yet.";
                    auditEl.appendChild(item);
                    return;
                }

                auditRows.forEach((row) => {
                    const item = document.createElement("li");
                    item.className = "policy-audit-item";
                    const changed = Array.isArray(row?.changed_fields) ? row.changed_fields.join(", ") : "none";
                    item.textContent = `${formatTimestamp(row?.created_at)} • v${row?.policy_version || 0} • ${row?.action || "update"} • fields: ${changed}`;
                    auditEl.appendChild(item);
                });
            }

            function renderSupportHistory(policy) {
                supportHistoryEl.innerHTML = "";
                const rows = Array.isArray(policy?.support_action_history) ? policy.support_action_history : [];

                if (rows.length === 0) {
                    const item = document.createElement("li");
                    item.className = "policy-audit-item";
                    item.textContent = "No reminder support actions recorded yet.";
                    supportHistoryEl.appendChild(item);
                    return;
                }

                rows.forEach((row) => {
                    const item = document.createElement("li");
                    item.className = "policy-audit-item";
                    item.textContent = `${formatTimestamp(row?.created_at)} • ${row?.action || "support action"} • ${(row?.reward_identifier || row?.marketing_profile_id || "target")} • ${row?.reason || "No note provided."}`;
                    supportHistoryEl.appendChild(item);
                });
            }

            function renderReminderHistory(policy) {
                reminderHistoryEl.innerHTML = "";
                const rows = Array.isArray(policy?.reminder_reporting?.recent_activity)
                    ? policy.reminder_reporting.recent_activity
                    : (Array.isArray(policy?.reminder_history) ? policy.reminder_history : []);

                if (rows.length === 0) {
                    const item = document.createElement("li");
                    item.className = "policy-audit-item";
                    item.textContent = "No customer reminder events recorded yet.";
                    reminderHistoryEl.appendChild(item);
                    return;
                }

                rows.forEach((row) => {
                    const item = document.createElement("li");
                    item.className = "policy-audit-item";
                    item.textContent = `${formatTimestamp(row?.occurred_at || row?.scheduled_at)} • v${row?.policy_version || 0} • ${(row?.channel || "channel").toUpperCase()} • ${row?.status || "scheduled"} • ${(row?.reward_identifier || "reward")} ${row?.skip_reason ? `• ${row.skip_reason}` : ""}`;
                    reminderHistoryEl.appendChild(item);
                });
            }

            function setFieldGuard(wrapper, message) {
                if (!wrapper) return;
                let guard = wrapper.querySelector(".policy-field-guard");
                if (!guard) {
                    guard = document.createElement("p");
                    guard.className = "policy-field-guard";
                    wrapper.appendChild(guard);
                }

                if (!message) {
                    guard.classList.add("is-hidden");
                    guard.textContent = "";
                    return;
                }

                guard.classList.remove("is-hidden");
                guard.textContent = message;
            }

            function applyFieldControls(policy) {
                const controls = policy?.field_controls || {};
                document.querySelectorAll("[data-policy-field]").forEach((wrapper) => {
                    const path = wrapper.getAttribute("data-policy-field");
                    const control = controls?.[path] || { access: "editable", message: null };
                    const access = control?.access || "editable";

                    wrapper.classList.remove("is-hidden");
                    setFieldGuard(wrapper, null);

                    if (access === "restricted") {
                        wrapper.classList.add("is-hidden");
                        return;
                    }

                    if (access === "editable_with_warning") {
                        setFieldGuard(wrapper, control?.message || "Check this setting before publishing.");
                    }
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
                    earning_rules: {
                        rewardable_channels: document.getElementById("rewardable_channels")?.value || "online_only",
                        candle_club_multiplier_enabled: !!document.getElementById("candle_club_multiplier_enabled")?.checked,
                        candle_club_multiplier_value: Number.parseFloat(document.getElementById("candle_club_multiplier_value")?.value || "2"),
                        candle_club_free_shipping_enabled: !!document.getElementById("candle_club_free_shipping_enabled")?.checked,
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
                            limited_releases: !!document.getElementById("exclude_limited_releases")?.checked,
                            subscriptions: !!document.getElementById("exclude_subscriptions")?.checked,
                            collections: parseList(document.getElementById("exclude_collections")?.value || ""),
                            tags: parseList(document.getElementById("exclude_tags")?.value || ""),
                            products: parseList(document.getElementById("exclude_products")?.value || ""),
                        },
                    },
                    expiration_and_reminders: {
                        expiration_mode: document.getElementById("expiration_mode")?.value || "days_from_issue",
                        expiration_days: Number.parseInt(document.getElementById("expiration_days")?.value || "90", 10),
                        reminder_offsets_days: parseOffsets(document.getElementById("email_reminder_offsets_days")?.value || ""),
                        email_reminder_offsets_days: parseOffsets(document.getElementById("email_reminder_offsets_days")?.value || ""),
                        sms_reminder_offsets_days: parseOffsets(document.getElementById("sms_reminder_offsets_days")?.value || ""),
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
                    automation_and_reporting: {
                        automation_mode: document.getElementById("automation_mode")?.value || "manual",
                        alert_email_enabled: !!document.getElementById("alert_email_enabled")?.checked,
                        alert_email: document.getElementById("alert_email")?.value || null,
                        alert_no_sends_hours: Number.parseInt(document.getElementById("alert_no_sends_hours")?.value || "24", 10),
                        alert_high_skip_rate_percent: Number.parseInt(document.getElementById("alert_high_skip_rate_percent")?.value || "50", 10),
                        alert_failure_spike_count: Number.parseInt(document.getElementById("alert_failure_spike_count")?.value || "5", 10),
                        report_frequency: document.getElementById("report_frequency")?.value || "off",
                        report_delivery_mode: document.getElementById("report_delivery_mode")?.value || "email_link",
                        report_email: document.getElementById("report_email")?.value || null,
                        report_day_of_week: document.getElementById("report_day_of_week")?.value || "monday",
                    },
                    team_access: {
                        edit_role: document.getElementById("edit_role")?.value || "manager_or_admin",
                        publish_role: document.getElementById("publish_role")?.value || "manager_or_admin",
                        support_role: document.getElementById("support_role")?.value || "marketing_manager_or_admin",
                        automation_role: document.getElementById("automation_role")?.value || "manager_or_admin",
                    },
                };
            }

            function setEditable(enabled) {
                form.querySelectorAll("input, select, textarea, button").forEach((node) => {
                    node.disabled = !enabled;
                });
                reviewButton.disabled = !enabled;
                publishButton.disabled = !enabled;
                alphaButton.disabled = !enabled;
                requeueReminderButton.disabled = !enabled;
                skipReminderButton.disabled = !enabled;
            }

            function applyPermissionState(policy, baseEditable) {
                const canEdit = baseEditable && policy?.permissions?.actions?.edit?.allowed !== false;
                const canPublish = baseEditable && policy?.permissions?.actions?.publish?.allowed !== false;
                const canSupport = baseEditable && policy?.permissions?.actions?.support?.allowed !== false;
                const canSwitchAutomation = baseEditable && policy?.permissions?.actions?.automation?.allowed !== false;

                form.querySelectorAll("input, select, textarea").forEach((node) => {
                    node.disabled = !canEdit;
                });
                const automationModeField = document.getElementById("automation_mode");
                if (automationModeField) {
                    automationModeField.disabled = !canSwitchAutomation;
                }
                saveButton.disabled = !canPublish;
                reviewButton.disabled = !canEdit;
                publishButton.disabled = !canPublish;
                alphaButton.disabled = !canPublish;
                runDebugButton.disabled = !canSupport;
                loadCustomerHistoryButton.disabled = !canSupport;
                requeueReminderButton.disabled = !canSupport;
                skipReminderButton.disabled = !canSupport;
            }

            function renderPolicy(policy) {
                fillForm(policy);
                renderSummary(policy);
                renderVersioning(policy);
                renderReadiness(policy);
                renderAutomation(policy);
                renderHealth(policy);
                renderPreviews(policy);
                renderReportFilters(policy);
                renderReporting(policy);
                renderFinance(policy);
                renderAudit(policy);
                renderSupportHistory(policy);
                renderReminderHistory(policy);
                applyFieldControls(policy);
            }

            function syncRenderedPolicy(policy, serverEditable = editable) {
                const canEdit = serverEditable === true;
                setEditable(canEdit);
                applyPermissionState(policy, canEdit);
            }

            async function loadPolicy(filters = collectReportFilters()) {
                try {
                    showAlert(errorAlert, null);
                    showAlert(successAlert, null);
                    const payload = await requestJson(`${endpoint}${buildQuery(filters)}`);
                    const policy = payload?.data || {};
                    const canEdit = editable && payload?.editable === true;

                    renderPolicy(policy);
                    syncRenderedPolicy(policy, canEdit);

                    if (!canEdit && payload?.message) {
                        showAlert(errorAlert, payload.message);
                    }
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Program settings could not be loaded.");
                    setEditable(false);
                    summaryEl.textContent = "Program settings could not be loaded.";
                }
            }

            async function savePayload(payload, successMessage) {
                const response = await requestJson(updateEndpoint, {
                    method: "PATCH",
                    body: JSON.stringify(payload),
                });

                const policy = response?.data || {};
                renderPolicy(policy);
                syncRenderedPolicy(policy, editable);
                showAlert(successAlert, response?.message || successMessage);
            }

            form.addEventListener("submit", async (event) => {
                event.preventDefault();
                if (saveButton.disabled) return;

                try {
                    showAlert(errorAlert, null);
                    showAlert(successAlert, null);
                    saveButton.disabled = true;
                    await savePayload(collectPayload(), "Program settings saved.");
                } catch (error) {
                    const payload = error?.payload || {};
                    const errors = payload?.errors && typeof payload.errors === "object"
                        ? Object.values(payload.errors).flat().filter(Boolean)
                        : [];
                    showAlert(errorAlert, errors[0] || payload?.message || error?.message || "Program settings could not be saved.");
                } finally {
                    saveButton.disabled = false;
                }
            });

            reviewButton.addEventListener("click", async () => {
                if (reviewButton.disabled || !reviewEndpoint) return;

                try {
                    showAlert(errorAlert, null);
                    showAlert(successAlert, null);
                    reviewButton.disabled = true;

                    const response = await requestJson(reviewEndpoint, {
                        method: "POST",
                        body: JSON.stringify(collectPayload()),
                    });

                    const reviewData = response?.data || {};
                    renderPolicy(reviewData);
                    syncRenderedPolicy(reviewData, editable);

                    const errors = reviewData?.validation_errors && typeof reviewData.validation_errors === "object"
                        ? Object.values(reviewData.validation_errors).flat().filter(Boolean)
                        : [];

                    if (errors.length > 0) {
                        showAlert(errorAlert, errors[0]);
                    } else {
                        showAlert(successAlert, "Review preview updated.");
                    }
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Review preview could not be generated.");
                } finally {
                    reviewButton.disabled = false;
                }
            });

            publishButton.addEventListener("click", async () => {
                if (publishButton.disabled) return;

                try {
                    showAlert(errorAlert, null);
                    showAlert(successAlert, null);
                    publishButton.disabled = true;

                    const payload = collectPayload();
                    payload.access_state = {
                        ...(payload.access_state || {}),
                        launch_state: "published",
                    };

                    await savePayload(payload, "Program published.");
                } catch (error) {
                    const payload = error?.payload || {};
                    const errors = payload?.errors && typeof payload.errors === "object"
                        ? Object.values(payload.errors).flat().filter(Boolean)
                        : [];
                    showAlert(errorAlert, errors[0] || payload?.message || error?.message || "Publish failed.");
                } finally {
                    publishButton.disabled = false;
                }
            });

            alphaButton.addEventListener("click", async () => {
                if (alphaButton.disabled || !alphaEndpoint) return;

                try {
                    showAlert(errorAlert, null);
                    showAlert(successAlert, null);
                    alphaButton.disabled = true;

                    const response = await requestJson(alphaEndpoint, {
                        method: "POST",
                        body: JSON.stringify({}),
                    });

                    const policy = response?.data || {};
                    renderPolicy(policy);
                    syncRenderedPolicy(policy, editable);
                    showAlert(successAlert, response?.message || "Alpha starter settings applied.");
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Alpha defaults could not be applied.");
                } finally {
                    alphaButton.disabled = false;
                }
            });

            applyReportFiltersButton.addEventListener("click", async () => {
                try {
                    showAlert(errorAlert, null);
                    showAlert(successAlert, null);
                    applyReportFiltersButton.disabled = true;
                    await loadPolicy(collectReportFilters());
                    showAlert(successAlert, "Reporting filters applied.");
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Reporting filters could not be applied.");
                } finally {
                    applyReportFiltersButton.disabled = false;
                }
            });

            async function runSupportJson(url, body, successMessage) {
                const response = await requestJson(url, {
                    method: "POST",
                    body: JSON.stringify(body),
                });

                showAlert(successAlert, response?.message || successMessage);
                return response?.data || {};
            }

            runDebugButton.addEventListener("click", async () => {
                if (!debugEndpoint) return;

                try {
                    showAlert(errorAlert, null);
                    showAlert(successAlert, null);
                    runDebugButton.disabled = true;
                    const data = await requestJson(debugEndpoint, {
                        method: "POST",
                        body: JSON.stringify(collectSupportPayload()),
                    });
                    const result = data?.data || {};
                    const item = Array.isArray(result?.items) ? result.items[0] : null;
                    debugSummaryEl.textContent = [
                        `Policy version: ${result?.policy_version ?? "--"}`,
                        `Launch gate: ${result?.launch_gate?.message || "--"}`,
                        `Email readiness: ${result?.delivery_readiness?.email?.message || "--"}`,
                        `Text readiness: ${result?.delivery_readiness?.sms?.message || "--"}`,
                        "",
                        item?.schedule_explanation?.summary
                            ? `Due now: ${item.schedule_explanation.summary?.due_count ?? 0} • Upcoming: ${item.schedule_explanation.summary?.upcoming_count ?? 0} • Skipped: ${item.schedule_explanation.summary?.skipped_count ?? 0}`
                            : "No reminder explanation matched that lookup.",
                    ].join("\n");
                    renderMessageList(
                        debugListEl,
                        (item?.schedule_explanation?.evaluated_timings || []).map((row) => ({
                            message: `${String(row?.channel || "channel").toUpperCase()} • ${row?.timing_days_before_expiration ?? "--"} days • ${row?.decision || row?.status || "unknown"}${row?.skip_reason ? ` • ${row.skip_reason}` : ""}`,
                        })),
                        "No reminder timings matched that lookup."
                    );
                    showAlert(successAlert, data?.message || "Reminder explanation ready.");
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Reminder explanation could not be loaded.");
                } finally {
                    runDebugButton.disabled = false;
                }
            });

            loadCustomerHistoryButton.addEventListener("click", async () => {
                if (!historyEndpoint) return;

                const payload = collectSupportPayload();
                if (!payload.marketing_profile_id) {
                    showAlert(errorAlert, "Enter a customer id before loading reminder history.");
                    return;
                }

                try {
                    showAlert(errorAlert, null);
                    showAlert(successAlert, null);
                    loadCustomerHistoryButton.disabled = true;
                    const response = await requestJson(`${historyEndpoint}${buildQuery({
                        marketing_profile_id: payload.marketing_profile_id,
                        channel: payload.channel,
                        date_from: collectReportFilters().date_from,
                        date_to: collectReportFilters().date_to,
                    })}`);
                    const data = response?.data || {};
                    supportSummaryEl.textContent = `Loaded ${data?.count ?? 0} reminder history row(s) for customer ${payload.marketing_profile_id}.`;
                    renderMessageList(
                        debugListEl,
                        (data?.items || []).map((row) => ({
                            message: `${formatTimestamp(row?.occurred_at || row?.scheduled_at)} • ${(row?.channel || "channel").toUpperCase()} • ${row?.status || "scheduled"} • ${row?.reward_identifier || "reward"}${row?.skip_reason ? ` • ${row.skip_reason}` : ""}`,
                        })),
                        "No reminder history found for that customer."
                    );
                    showAlert(successAlert, response?.message || "Customer reminder history loaded.");
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Customer reminder history could not be loaded.");
                } finally {
                    loadCustomerHistoryButton.disabled = false;
                }
            });

            requeueReminderButton.addEventListener("click", async () => {
                if (requeueReminderButton.disabled || !requeueEndpoint) return;

                try {
                    showAlert(errorAlert, null);
                    showAlert(successAlert, null);
                    requeueReminderButton.disabled = true;
                    const result = await runSupportJson(requeueEndpoint, collectSupportPayload(), "Reminder requeue requested.");
                    supportSummaryEl.textContent = [
                        `Queued reminders: ${result?.queued_count ?? 0}`,
                        `Remaining due reminders: ${result?.remaining_due_count ?? 0}`,
                        "",
                        ...((result?.items || []).map((row) => `${row?.customer_name || "Customer"} • ${(row?.channel || "channel").toUpperCase()} • ${row?.timing_days_before_expiration ?? "--"} days before expiry`)),
                    ].join("\n");
                    await loadPolicy(collectReportFilters());
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Reminder requeue could not be requested.");
                } finally {
                    requeueReminderButton.disabled = false;
                }
            });

            skipReminderButton.addEventListener("click", async () => {
                if (skipReminderButton.disabled || !skipEndpoint) return;

                try {
                    showAlert(errorAlert, null);
                    showAlert(successAlert, null);
                    skipReminderButton.disabled = true;
                    const result = await runSupportJson(skipEndpoint, collectSupportPayload(), "Reminder marked as skipped.");
                    supportSummaryEl.textContent = [
                        `Skipped reminders: ${result?.summary?.skipped_count ?? 0}`,
                        `Schedule skips recorded: ${result?.summary?.schedule_skip_count ?? 0}`,
                    ].join("\n");
                    await loadPolicy(collectReportFilters());
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Reminder skip could not be recorded.");
                } finally {
                    skipReminderButton.disabled = false;
                }
            });

            exportRemindersButton.addEventListener("click", async () => {
                try {
                    await downloadCsv(reminderExportEndpoint, collectReportFilters(), "rewards-reminder-history.csv");
                    showAlert(successAlert, "Reminder history export downloaded.");
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Reminder history export failed.");
                }
            });

            exportIssuanceButton.addEventListener("click", async () => {
                try {
                    await downloadCsv(issuanceExportEndpoint, collectReportFilters(), "rewards-issuance.csv");
                    showAlert(successAlert, "Reward issuance export downloaded.");
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Reward issuance export failed.");
                }
            });

            exportRedemptionButton.addEventListener("click", async () => {
                try {
                    await downloadCsv(redemptionExportEndpoint, collectReportFilters(), "rewards-redemption.csv");
                    showAlert(successAlert, "Reward redemption export downloaded.");
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Reward redemption export failed.");
                }
            });

            exportExpiringButton.addEventListener("click", async () => {
                try {
                    await downloadCsv(expiringExportEndpoint, collectReportFilters(), "rewards-expiring.csv");
                    showAlert(successAlert, "Expiring rewards export downloaded.");
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Expiring rewards export failed.");
                }
            });

            exportFinanceButton.addEventListener("click", async () => {
                try {
                    await downloadCsv(financeExportEndpoint, collectReportFilters(), "rewards-finance-summary.csv");
                    showAlert(successAlert, "Finance snapshot export downloaded.");
                } catch (error) {
                    showAlert(errorAlert, error?.message || "Finance snapshot export failed.");
                }
            });

            if (!endpoint || !updateEndpoint || !reviewEndpoint || !alphaEndpoint) {
                showAlert(errorAlert, "Rewards policy API endpoints are not configured for this page.");
                setEditable(false);
                return;
            }

            loadPolicy();
        })();
    </script>
@endsection
