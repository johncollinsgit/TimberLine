<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-actions="$pageActions"
>
    @php
        $moduleStates = is_array($appNavigation['moduleStates'] ?? null) ? $appNavigation['moduleStates'] : [];
        $settingsModuleState = is_array($moduleStates['settings'] ?? null) ? $moduleStates['settings'] : null;
    @endphp

    <style>
        .settings-root {
            display: grid;
            gap: 16px;
            max-width: 980px;
        }

        .settings-card {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.96);
            padding: 18px;
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.04);
        }

        .settings-card h2 {
            margin: 0;
            font-size: 1.18rem;
            font-weight: 700;
            color: #0f172a;
        }

        .settings-card p {
            margin: 8px 0 0;
            color: rgba(15, 23, 42, 0.68);
            line-height: 1.6;
            font-size: 14px;
        }

        .settings-head {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .settings-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .settings-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            padding: 6px 10px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #334155;
            background: rgba(248, 250, 252, 0.96);
        }

        .settings-badge--configured {
            color: #0f766e;
            border-color: rgba(15, 118, 110, 0.24);
            background: rgba(15, 118, 110, 0.12);
        }

        .settings-badge--error {
            color: #b42318;
            border-color: rgba(180, 35, 24, 0.24);
            background: rgba(180, 35, 24, 0.09);
        }

        .settings-badge--testing {
            color: #075985;
            border-color: rgba(7, 89, 133, 0.24);
            background: rgba(7, 89, 133, 0.1);
        }

        .settings-badge--warn {
            color: #8a4b0f;
            border-color: rgba(138, 75, 15, 0.2);
            background: rgba(180, 83, 9, 0.1);
        }

        .settings-inline-status {
            margin-top: 10px;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.94);
            color: rgba(15, 23, 42, 0.75);
            padding: 12px 14px;
            font-size: 13px;
            line-height: 1.55;
        }

        .settings-inline-status[data-tone="success"] {
            border-color: rgba(15, 118, 110, 0.24);
            background: rgba(15, 118, 110, 0.1);
            color: #0f766e;
        }

        .settings-inline-status[data-tone="error"] {
            border-color: rgba(180, 35, 24, 0.24);
            background: rgba(180, 35, 24, 0.08);
            color: #b42318;
        }

        .settings-inline-status[hidden] {
            display: none;
        }

        .settings-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .settings-grid--single {
            grid-template-columns: minmax(0, 1fr);
        }

        .settings-field {
            display: grid;
            gap: 6px;
        }

        .settings-field label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.5);
        }

        .settings-field input,
        .settings-field select,
        .settings-field textarea {
            width: 100%;
            box-sizing: border-box;
            min-height: 44px;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #ffffff;
            color: rgba(15, 23, 42, 0.9);
            padding: 10px 12px;
            font-size: 14px;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }

        .settings-field textarea {
            min-height: 102px;
            resize: vertical;
        }

        .settings-field input:focus,
        .settings-field select:focus,
        .settings-field textarea:focus {
            outline: none;
            border-color: rgba(15, 143, 97, 0.36);
            box-shadow: 0 0 0 4px rgba(15, 143, 97, 0.1);
        }

        .settings-field small {
            color: rgba(15, 23, 42, 0.58);
            line-height: 1.5;
            font-size: 12px;
        }

        .settings-field-error {
            min-height: 16px;
            font-size: 12px;
            color: #b42318;
        }

        .settings-toggle {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.84);
            padding: 12px;
        }

        .settings-toggle-copy {
            display: grid;
            gap: 4px;
        }

        .settings-toggle-copy strong {
            font-size: 14px;
            color: #0f172a;
        }

        .settings-toggle-copy span {
            font-size: 12px;
            color: rgba(15, 23, 42, 0.64);
            line-height: 1.5;
        }

        .settings-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        .settings-provider-help {
            margin-top: 10px;
            border-radius: 12px;
            border: 1px dashed rgba(15, 23, 42, 0.14);
            background: rgba(247, 249, 246, 0.95);
            padding: 12px;
            color: rgba(15, 23, 42, 0.68);
            font-size: 13px;
            line-height: 1.55;
        }

        .settings-actions {
            margin-top: 14px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .settings-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #ffffff;
            color: rgba(15, 23, 42, 0.82);
            min-height: 40px;
            padding: 0 14px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: border-color 0.18s ease, background 0.18s ease, color 0.18s ease;
        }

        .settings-button:hover:not(:disabled) {
            border-color: rgba(15, 23, 42, 0.22);
            color: rgba(15, 23, 42, 0.95);
        }

        .settings-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .settings-button--primary {
            border-color: rgba(15, 143, 97, 0.32);
            background: rgba(15, 143, 97, 0.12);
            color: #0f6f4c;
        }

        .settings-button--danger {
            border-color: rgba(180, 35, 24, 0.22);
            background: rgba(180, 35, 24, 0.08);
            color: #b42318;
        }

        .settings-hidden {
            display: none;
        }

        .settings-muted {
            color: rgba(15, 23, 42, 0.56);
            font-size: 12px;
        }

        .settings-divider {
            margin: 14px 0;
            height: 1px;
            background: rgba(15, 23, 42, 0.08);
        }

        .settings-sender-list {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .settings-sender-card {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.9);
            padding: 12px 14px;
        }

        .settings-sender-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .settings-sender-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 620;
            color: rgba(15, 23, 42, 0.72);
        }

        @media (max-width: 900px) {
            .settings-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>

    <section class="settings-root" id="email-settings-root">
        @if(is_array($settingsModuleState))
            <x-tenancy.module-state-card
                :module-state="$settingsModuleState"
                title="Settings module state"
                description="Settings visibility and readiness now come from tenant entitlement + setup state."
            />
        @endif

        <article class="settings-card">
            <div class="settings-head">
                <div>
                    <h2>Email Settings</h2>
                    <p>
                        Configure email provider selection per tenant/store. SendGrid is fully supported for app-driven sends,
                        Shopify Email is selectable with current architecture limits, and Custom Provider is scaffolded for future support.
                    </p>
                </div>
                <div class="settings-badges" id="settings-status-badges"></div>
            </div>

            <div class="settings-inline-status" id="settings-global-alert" hidden></div>

            <form id="email-settings-form">
                <div class="settings-grid">
                    <div class="settings-field">
                        <label for="email-provider">Provider</label>
                        <select id="email-provider" name="email_provider"></select>
                        <small>Select how this tenant sends app-driven email.</small>
                        <div class="settings-field-error" data-error-for="email_provider"></div>
                    </div>
                    <div class="settings-field">
                        <label for="provider-status-readonly">Provider Status</label>
                        <input id="provider-status-readonly" type="text" readonly>
                        <small>Status is updated from validation, health checks, and test sends.</small>
                    </div>
                </div>

                <div class="settings-divider"></div>

                <div class="settings-toggle">
                    <div class="settings-toggle-copy">
                        <strong>Email Sending Enabled</strong>
                        <span>Disable this to block app-driven sends for this tenant without deleting provider config.</span>
                    </div>
                    <input id="email-enabled" name="email_enabled" type="checkbox">
                </div>

                <div class="settings-toggle" style="margin-top: 10px;">
                    <div class="settings-toggle-copy">
                        <strong>Email Analytics Enabled</strong>
                        <span>Keep metadata and provider delivery hooks ready for campaign reporting and troubleshooting.</span>
                    </div>
                    <input id="analytics-enabled" name="analytics_enabled" type="checkbox">
                </div>

                <div class="settings-divider"></div>

                <div class="settings-grid">
                    <div class="settings-field">
                        <label for="from-name">From Name</label>
                        <input id="from-name" name="from_name" type="text" maxlength="120" placeholder="Timberline">
                        <small>Friendly sender name customers see in their inbox.</small>
                        <div class="settings-field-error" data-error-for="from_name"></div>
                    </div>
                    <div class="settings-field">
                        <label for="from-email">From Email</label>
                        <input id="from-email" name="from_email" type="email" maxlength="255" placeholder="hello@example.com">
                        <small>Use a sender email aligned with your verified domain.</small>
                        <div class="settings-field-error" data-error-for="from_email"></div>
                    </div>
                    <div class="settings-field">
                        <label for="reply-to-email">Reply-To Email</label>
                        <input id="reply-to-email" name="reply_to_email" type="email" maxlength="255" placeholder="support@example.com">
                        <small>Optional override for customer replies.</small>
                        <div class="settings-field-error" data-error-for="reply_to_email"></div>
                    </div>
                </div>

                <div class="settings-divider"></div>

                <div id="provider-settings-content" class="settings-grid settings-grid--single"></div>

                <div class="settings-actions">
                    <button class="settings-button settings-button--primary" type="button" id="settings-save">Save Settings</button>
                    <button class="settings-button" type="button" id="settings-validate">Validate Config</button>
                    <button class="settings-button" type="button" id="settings-health">Check Health</button>
                </div>
            </form>
        </article>

        <article class="settings-card">
            <h2>Send Test Email</h2>
            <p>
                Send a provider test email for this tenant. Shopify Email and Custom Provider selections return honest
                unsupported/not-implemented responses so setup issues are visible early.
            </p>
            <div class="settings-inline-status" id="settings-test-alert" hidden></div>
            <div class="settings-grid">
                <div class="settings-field">
                    <label for="test-email">Test Recipient Email</label>
                    <input id="test-email" type="email" maxlength="255" placeholder="you@example.com">
                    <div class="settings-field-error" data-error-for="to_email"></div>
                </div>
                <div class="settings-field">
                    <label for="test-subject">Optional Subject Override</label>
                    <input id="test-subject" type="text" maxlength="200" placeholder="Email settings test">
                </div>
            </div>
            <div class="settings-actions">
                <button class="settings-button settings-button--primary" type="button" id="settings-test-send">Send Test Email</button>
                <button class="settings-button settings-button--danger settings-hidden" type="button" id="settings-clear-key">Clear Saved API Key</button>
            </div>
            <p class="settings-muted" id="settings-last-tested"></p>
        </article>

        <article class="settings-card">
            <h2>SMS Sender Visibility</h2>
            <p>
                Shopify mirrors active SMS sender configuration so support and marketing can quickly confirm what is live.
            </p>
            <div class="settings-sender-list">
                @forelse($smsSenders as $sender)
                    <article class="settings-sender-card">
                        <strong>{{ $sender['label'] }}</strong>
                        <div style="margin-top: 6px; color: rgba(15, 23, 42, 0.72); font-size: 14px;">
                            {{ $sender['identity_label'] ?? 'Not configured yet' }}
                        </div>
                        <div class="settings-sender-meta">
                            <span class="settings-sender-pill">{{ $sender['type'] }}</span>
                            <span class="settings-sender-pill">{{ $sender['status'] }}</span>
                            @if(!empty($sender['is_default']))
                                <span class="settings-sender-pill">default sender</span>
                            @endif
                            @if(empty($sender['sendable']))
                                <span class="settings-sender-pill">not sendable yet</span>
                            @endif
                        </div>
                    </article>
                @empty
                    <article class="settings-sender-card">
                        No SMS sender is configured yet. Add one in environment config before enabling sends.
                    </article>
                @endforelse
            </div>
        </article>
    </section>

    <script>
        (() => {
            const bootstrap = @json($emailSettingsBootstrap ?? []);
            const root = document.getElementById("email-settings-root");
            if (!root) {
                return;
            }

            const form = document.getElementById("email-settings-form");
            const providerSelect = document.getElementById("email-provider");
            const providerStatusInput = document.getElementById("provider-status-readonly");
            const providerSettingsContent = document.getElementById("provider-settings-content");
            const emailEnabledInput = document.getElementById("email-enabled");
            const analyticsEnabledInput = document.getElementById("analytics-enabled");
            const fromNameInput = document.getElementById("from-name");
            const fromEmailInput = document.getElementById("from-email");
            const replyToEmailInput = document.getElementById("reply-to-email");
            const saveButton = document.getElementById("settings-save");
            const validateButton = document.getElementById("settings-validate");
            const healthButton = document.getElementById("settings-health");
            const globalAlert = document.getElementById("settings-global-alert");
            const statusBadges = document.getElementById("settings-status-badges");
            const testAlert = document.getElementById("settings-test-alert");
            const testEmailInput = document.getElementById("test-email");
            const testSubjectInput = document.getElementById("test-subject");
            const testButton = document.getElementById("settings-test-send");
            const clearKeyButton = document.getElementById("settings-clear-key");
            const lastTestedLabel = document.getElementById("settings-last-tested");

            const state = {
                loading: false,
                busy: false,
                settings: normalizeSettings(bootstrap?.settings || null),
                availableProviders: Array.isArray(bootstrap?.settings?.available_providers)
                    ? bootstrap.settings.available_providers
                    : defaultProviders(),
                pendingClearKey: false,
                providerDrafts: {},
            };

            if (!bootstrap?.authorized || !bootstrap?.tenant_id) {
                lockUi("Email settings are unavailable until this Shopify store is mapped to a tenant.");
                return;
            }

            function defaultProviders() {
                return [
                    {
                        key: "shopify_email",
                        label: "Shopify Email",
                        implemented: false,
                        description: "Store-native Shopify marketing email selection. App-driven sends are currently limited.",
                    },
                    {
                        key: "sendgrid",
                        label: "SendGrid",
                        implemented: true,
                        description: "App-driven transactional and campaign email delivery through SendGrid.",
                    },
                    {
                        key: "custom",
                        label: "Custom Provider",
                        implemented: false,
                        description: "Scaffolded provider slot for future custom email integrations.",
                    },
                ];
            }

            function emptySettings() {
                return {
                    tenant_id: bootstrap?.tenant_id || null,
                    email_provider: "sendgrid",
                    email_enabled: false,
                    from_name: "",
                    from_email: "",
                    reply_to_email: "",
                    provider_status: "not_configured",
                    provider_config: {},
                    analytics_enabled: true,
                    last_tested_at: null,
                    last_error: null,
                };
            }

            function normalizeSettings(input) {
                const base = emptySettings();
                if (!input || typeof input !== "object") {
                    return base;
                }

                return {
                    ...base,
                    ...input,
                    email_provider: String(input.email_provider || "sendgrid"),
                    email_enabled: Boolean(input.email_enabled),
                    analytics_enabled: Boolean(input.analytics_enabled),
                    provider_config: (input.provider_config && typeof input.provider_config === "object")
                        ? input.provider_config
                        : {},
                };
            }

            function providerLabel(key) {
                const provider = state.availableProviders.find((item) => String(item.key) === String(key));
                return provider?.label || key;
            }

            function syncProviderDraftFromSettings() {
                const provider = String(state.settings?.email_provider || "sendgrid");
                state.providerDrafts[provider] = {
                    ...((state.settings?.provider_config && typeof state.settings.provider_config === "object")
                        ? state.settings.provider_config
                        : {}),
                };
            }

            function providerDraftFromDom(provider) {
                const normalizedProvider = String(provider || "");
                const existing = (state.settings?.provider_config && typeof state.settings.provider_config === "object")
                    ? state.settings.provider_config
                    : {};

                if (normalizedProvider === "sendgrid") {
                    return {
                        ...existing,
                        verified_sender_email: normalizeString(document.getElementById("provider-verified-email")?.value || "")
                            || existing.verified_sender_email
                            || null,
                        verified_sender_name: normalizeString(document.getElementById("provider-verified-name")?.value || "")
                            || existing.verified_sender_name
                            || null,
                        reply_to_email: normalizeString(document.getElementById("provider-reply-to-email")?.value || "")
                            || existing.reply_to_email
                            || null,
                        tracking_enabled: Boolean(document.getElementById("provider-tracking-enabled")?.checked ?? existing.tracking_enabled ?? true),
                        has_api_key: Boolean(existing.has_api_key),
                        api_key_masked: existing.api_key_masked || null,
                    };
                }

                if (normalizedProvider === "shopify_email") {
                    return {
                        notes: normalizeString(document.getElementById("provider-shopify-notes")?.value || "")
                            || existing.notes
                            || null,
                    };
                }

                return {
                    ...existing,
                    driver: normalizeString(document.getElementById("provider-driver")?.value || "") || existing.driver || null,
                    api_endpoint: normalizeString(document.getElementById("provider-endpoint")?.value || "") || existing.api_endpoint || null,
                    auth_scheme: normalizeString(document.getElementById("provider-auth-scheme")?.value || "") || existing.auth_scheme || null,
                    notes: normalizeString(document.getElementById("provider-custom-notes")?.value || "") || existing.notes || null,
                    has_api_key: Boolean(existing.has_api_key),
                    api_key_masked: existing.api_key_masked || null,
                };
            }

            function setAlert(node, message, tone = "neutral") {
                if (!node) {
                    return;
                }

                const normalizedMessage = String(message || "").trim();
                if (normalizedMessage === "") {
                    node.hidden = true;
                    node.textContent = "";
                    node.removeAttribute("data-tone");
                    return;
                }

                node.hidden = false;
                node.textContent = normalizedMessage;
                if (tone === "neutral") {
                    node.removeAttribute("data-tone");
                } else {
                    node.setAttribute("data-tone", tone);
                }
            }

            function clearErrors() {
                root.querySelectorAll("[data-error-for]").forEach((node) => {
                    node.textContent = "";
                });
            }

            function setErrors(errors = {}) {
                clearErrors();
                Object.entries(errors).forEach(([key, value]) => {
                    const node = root.querySelector(`[data-error-for="${cssEscape(key)}"]`);
                    if (!node) {
                        return;
                    }

                    if (Array.isArray(value)) {
                        node.textContent = String(value[0] || "");
                        return;
                    }

                    node.textContent = String(value || "");
                });
            }

            function cssEscape(value) {
                if (window.CSS && typeof window.CSS.escape === "function") {
                    return window.CSS.escape(String(value));
                }

                return String(value).replace(/"/g, '\\"');
            }

            function setBusy(isBusy) {
                state.busy = Boolean(isBusy);
                [saveButton, validateButton, healthButton, testButton, clearKeyButton].forEach((button) => {
                    if (button) {
                        button.disabled = state.busy || state.loading;
                    }
                });

                Array.from(form.elements || []).forEach((element) => {
                    if (!(element instanceof HTMLElement)) {
                        return;
                    }

                    if (element === clearKeyButton) {
                        return;
                    }

                    element.toggleAttribute("disabled", state.busy || state.loading);
                });
            }

            function lockUi(message) {
                setAlert(globalAlert, message, "error");
                Array.from(form.elements || []).forEach((element) => {
                    if (element instanceof HTMLElement) {
                        element.setAttribute("disabled", "disabled");
                    }
                });
                [saveButton, validateButton, healthButton, testButton, clearKeyButton].forEach((button) => {
                    if (button) {
                        button.setAttribute("disabled", "disabled");
                    }
                });
            }

            function statusLabel(status, settings) {
                const normalized = String(status || "not_configured");
                if (normalized === "configured" && settings?.last_tested_at) {
                    return "Test successful";
                }

                return ({
                    not_configured: "Not configured",
                    configured: "Configured",
                    error: "Error",
                    testing: "Testing",
                })[normalized] || "Not configured";
            }

            function statusTone(status, settings) {
                const normalized = String(status || "not_configured");
                if (normalized === "configured" && settings?.last_tested_at) {
                    return "configured";
                }

                return ({
                    configured: "configured",
                    error: "error",
                    testing: "testing",
                    not_configured: "warn",
                })[normalized] || "warn";
            }

            function updateStatusBadges() {
                const settings = state.settings || emptySettings();
                const badges = [];
                badges.push({
                    label: providerLabel(settings.email_provider),
                    tone: "neutral",
                });
                badges.push({
                    label: statusLabel(settings.provider_status, settings),
                    tone: statusTone(settings.provider_status, settings),
                });
                badges.push({
                    label: settings.email_enabled ? "Email enabled" : "Email disabled",
                    tone: settings.email_enabled ? "configured" : "warn",
                });
                if (settings.last_error) {
                    badges.push({
                        label: "Error present",
                        tone: "error",
                    });
                }

                statusBadges.innerHTML = badges.map((badge) => {
                    const className = badge.tone && badge.tone !== "neutral"
                        ? `settings-badge settings-badge--${badge.tone}`
                        : "settings-badge";
                    return `<span class="${className}">${escapeHtml(badge.label)}</span>`;
                }).join("");

                providerStatusInput.value = statusLabel(settings.provider_status, settings);
            }

            function renderProviderOptions() {
                providerSelect.innerHTML = state.availableProviders.map((provider) => {
                    const selected = String(provider.key) === String(state.settings.email_provider) ? "selected" : "";
                    return `<option value="${escapeHtml(provider.key)}" ${selected}>${escapeHtml(provider.label)}</option>`;
                }).join("");
            }

            function renderProviderFields() {
                const provider = String(state.settings.email_provider || "sendgrid");
                const config = state.settings.provider_config || {};
                const apiKeyMasked = String(config.api_key_masked || "").trim();
                const hasApiKey = Boolean(config.has_api_key);

                if (provider === "sendgrid") {
                    providerSettingsContent.innerHTML = `
                        <div class="settings-provider-help">
                            SendGrid is fully supported for app-driven sends. Use a verified sender identity, and for best deliverability
                            complete domain authentication (SPF/DKIM) in DNS. You do not need to move your website, only add the DNS records SendGrid provides.
                        </div>
                        <div class="settings-grid">
                            <div class="settings-field">
                                <label for="provider-api-key">SendGrid API Key</label>
                                <input id="provider-api-key" name="provider_config.api_key" type="password" maxlength="500" autocomplete="off" placeholder="SG.xxxxx">
                                <small>${hasApiKey ? `Saved key: ${escapeHtml(apiKeyMasked || "********")}. Leave blank to keep current key.` : "Paste a SendGrid API key with Mail Send permission."}</small>
                                <div class="settings-field-error" data-error-for="provider_config.api_key"></div>
                            </div>
                            <div class="settings-field">
                                <label for="provider-verified-email">Verified Sender Email</label>
                                <input id="provider-verified-email" name="provider_config.verified_sender_email" type="email" maxlength="255" value="${escapeHtml(config.verified_sender_email || "")}" placeholder="verified@example.com">
                                <small>This address must be verified in SendGrid.</small>
                                <div class="settings-field-error" data-error-for="provider_config.verified_sender_email"></div>
                            </div>
                            <div class="settings-field">
                                <label for="provider-verified-name">Verified Sender Name</label>
                                <input id="provider-verified-name" name="provider_config.verified_sender_name" type="text" maxlength="120" value="${escapeHtml(config.verified_sender_name || "")}" placeholder="Brand Team">
                                <small>Name tied to the verified sender profile.</small>
                                <div class="settings-field-error" data-error-for="provider_config.verified_sender_name"></div>
                            </div>
                            <div class="settings-field">
                                <label for="provider-reply-to-email">Provider Reply-To Override</label>
                                <input id="provider-reply-to-email" name="provider_config.reply_to_email" type="email" maxlength="255" value="${escapeHtml(config.reply_to_email || "")}" placeholder="support@example.com">
                                <small>Optional provider-specific reply-to address.</small>
                                <div class="settings-field-error" data-error-for="provider_config.reply_to_email"></div>
                            </div>
                        </div>
                        <div class="settings-toggle" style="margin-top: 12px;">
                            <div class="settings-toggle-copy">
                                <strong>Tracking Enabled</strong>
                                <span>Enable click/open tracking for analytics and campaign health signals.</span>
                            </div>
                            <input id="provider-tracking-enabled" name="provider_config.tracking_enabled" type="checkbox" ${Boolean(config.tracking_enabled ?? true) ? "checked" : ""}>
                        </div>
                        <div class="settings-toggle" style="margin-top: 10px;">
                            <div class="settings-toggle-copy">
                                <strong>Clear Saved API Key</strong>
                                <span>Use this only when rotating credentials. Existing app sends will fail until a new key is saved.</span>
                            </div>
                            <input id="provider-clear-api-key" name="provider_config.clear_api_key" type="checkbox" ${state.pendingClearKey ? "checked" : ""}>
                        </div>
                    `;
                    clearKeyButton.classList.toggle("settings-hidden", !hasApiKey);
                    return;
                }

                if (provider === "shopify_email") {
                    providerSettingsContent.innerHTML = `
                        <div class="settings-provider-help">
                            Shopify Email is store-native and typically focused on Shopify-managed marketing flows. In this app architecture,
                            provider selection is saved, but app-driven sends and test sends are not available for Shopify Email yet.
                        </div>
                        <div class="settings-grid settings-grid--single">
                            <div class="settings-field">
                                <label for="provider-shopify-notes">Notes</label>
                                <textarea id="provider-shopify-notes" name="provider_config.notes" maxlength="2000" placeholder="Optional internal notes for this tenant setup.">${escapeHtml(config.notes || "")}</textarea>
                                <small>Store internal rollout notes or ownership details here.</small>
                                <div class="settings-field-error" data-error-for="provider_config.notes"></div>
                            </div>
                        </div>
                    `;
                    clearKeyButton.classList.add("settings-hidden");
                    return;
                }

                providerSettingsContent.innerHTML = `
                    <div class="settings-provider-help">
                        Custom Provider is scaffolded for future implementation. You can save endpoint/auth metadata now,
                        but send and test execution are not implemented yet.
                    </div>
                    <div class="settings-grid">
                        <div class="settings-field">
                            <label for="provider-driver">Driver Name</label>
                            <input id="provider-driver" name="provider_config.driver" type="text" maxlength="80" value="${escapeHtml(config.driver || "")}" placeholder="mailgun | ses | postmark">
                            <div class="settings-field-error" data-error-for="provider_config.driver"></div>
                        </div>
                        <div class="settings-field">
                            <label for="provider-endpoint">API Endpoint</label>
                            <input id="provider-endpoint" name="provider_config.api_endpoint" type="url" maxlength="500" value="${escapeHtml(config.api_endpoint || "")}" placeholder="https://api.provider.com/v1/send">
                            <div class="settings-field-error" data-error-for="provider_config.api_endpoint"></div>
                        </div>
                        <div class="settings-field">
                            <label for="provider-auth-scheme">Auth Scheme</label>
                            <input id="provider-auth-scheme" name="provider_config.auth_scheme" type="text" maxlength="80" value="${escapeHtml(config.auth_scheme || "")}" placeholder="Bearer | Basic | HMAC">
                            <div class="settings-field-error" data-error-for="provider_config.auth_scheme"></div>
                        </div>
                        <div class="settings-field">
                            <label for="provider-custom-api-key">API Key / Secret</label>
                            <input id="provider-custom-api-key" name="provider_config.api_key" type="password" maxlength="500" autocomplete="off" placeholder="Paste new secret">
                            <small>${Boolean(config.has_api_key) ? `Saved secret: ${escapeHtml(config.api_key_masked || "********")}. Leave blank to keep current secret.` : "Optional now. Sending is not yet implemented."}</small>
                            <div class="settings-field-error" data-error-for="provider_config.api_key"></div>
                        </div>
                        <div class="settings-field" style="grid-column: 1 / -1;">
                            <label for="provider-custom-notes">Notes</label>
                            <textarea id="provider-custom-notes" name="provider_config.notes" maxlength="2000" placeholder="Implementation notes, owner, webhook requirements.">${escapeHtml(config.notes || "")}</textarea>
                            <div class="settings-field-error" data-error-for="provider_config.notes"></div>
                        </div>
                    </div>
                `;
                clearKeyButton.classList.toggle("settings-hidden", !Boolean(config.has_api_key));
            }

            function populateFormFromState() {
                renderProviderOptions();
                emailEnabledInput.checked = Boolean(state.settings.email_enabled);
                analyticsEnabledInput.checked = Boolean(state.settings.analytics_enabled);
                fromNameInput.value = state.settings.from_name || "";
                fromEmailInput.value = state.settings.from_email || "";
                replyToEmailInput.value = state.settings.reply_to_email || "";
                renderProviderFields();
                updateStatusBadges();
                updateLastTestedLabel();
            }

            function updateLastTestedLabel() {
                if (!state.settings.last_tested_at) {
                    lastTestedLabel.textContent = "";
                    return;
                }

                const date = new Date(state.settings.last_tested_at);
                if (Number.isNaN(date.getTime())) {
                    lastTestedLabel.textContent = `Last tested: ${state.settings.last_tested_at}`;
                    return;
                }

                lastTestedLabel.textContent = `Last tested: ${date.toLocaleString()}`;
            }

            function collectPayload() {
                const provider = String(providerSelect.value || "sendgrid");
                const payload = {
                    email_provider: provider,
                    email_enabled: Boolean(emailEnabledInput.checked),
                    analytics_enabled: Boolean(analyticsEnabledInput.checked),
                    from_name: normalizeString(fromNameInput.value),
                    from_email: normalizeString(fromEmailInput.value),
                    reply_to_email: normalizeString(replyToEmailInput.value),
                    provider_config: {},
                };

                if (provider === "sendgrid") {
                    const apiKeyValue = normalizeString(document.getElementById("provider-api-key")?.value || "");
                    payload.provider_config = {
                        api_key: apiKeyValue,
                        clear_api_key: Boolean(document.getElementById("provider-clear-api-key")?.checked || state.pendingClearKey),
                        verified_sender_email: normalizeString(document.getElementById("provider-verified-email")?.value || ""),
                        verified_sender_name: normalizeString(document.getElementById("provider-verified-name")?.value || ""),
                        reply_to_email: normalizeString(document.getElementById("provider-reply-to-email")?.value || ""),
                        tracking_enabled: Boolean(document.getElementById("provider-tracking-enabled")?.checked),
                    };
                } else if (provider === "shopify_email") {
                    payload.provider_config = {
                        notes: normalizeString(document.getElementById("provider-shopify-notes")?.value || ""),
                    };
                } else {
                    payload.provider_config = {
                        driver: normalizeString(document.getElementById("provider-driver")?.value || ""),
                        api_endpoint: normalizeString(document.getElementById("provider-endpoint")?.value || ""),
                        auth_scheme: normalizeString(document.getElementById("provider-auth-scheme")?.value || ""),
                        api_key: normalizeString(document.getElementById("provider-custom-api-key")?.value || ""),
                        clear_api_key: Boolean(state.pendingClearKey),
                        notes: normalizeString(document.getElementById("provider-custom-notes")?.value || ""),
                    };
                }

                return payload;
            }

            function normalizeString(value) {
                const normalized = String(value || "").trim();
                return normalized === "" ? null : normalized;
            }

            function escapeHtml(value) {
                return String(value ?? "")
                    .replaceAll("&", "&amp;")
                    .replaceAll("<", "&lt;")
                    .replaceAll(">", "&gt;")
                    .replaceAll('"', "&quot;")
                    .replaceAll("'", "&#039;");
            }

            function authFailureMessage(status, fallbackMessage) {
                const messages = {
                    missing_api_auth: "Shopify Admin verification is unavailable. Reload settings from Shopify Admin and try again.",
                    invalid_session_token: "Shopify Admin verification failed. Reload settings from Shopify Admin and try again.",
                    expired_session_token: "Your Shopify Admin session expired. Reload settings from Shopify Admin and try again.",
                };

                return messages[status] || fallbackMessage || null;
            }

            async function resolveEmbeddedAuthHeaders() {
                if (!window.shopify || typeof window.shopify.idToken !== "function") {
                    throw new Error(
                        authFailureMessage("missing_api_auth", "Shopify Admin verification is unavailable."),
                    );
                }

                const headers = {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                };

                let sessionToken = null;

                try {
                    sessionToken = await Promise.race([
                        Promise.resolve(window.shopify.idToken()),
                        new Promise((resolve) => window.setTimeout(() => resolve(null), 1500)),
                    ]);
                } catch (error) {
                    throw new Error(authFailureMessage("invalid_session_token", "Shopify Admin verification failed."));
                }

                if (typeof sessionToken !== "string" || sessionToken.trim() === "") {
                    throw new Error(authFailureMessage("missing_api_auth", "Shopify Admin verification is unavailable."));
                }

                headers.Authorization = `Bearer ${sessionToken.trim()}`;

                return headers;
            }

            async function fetchJson(url, options = {}) {
                const authHeaders = await resolveEmbeddedAuthHeaders();
                const response = await fetch(url, {
                    headers: {
                        ...authHeaders,
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
                    const error = new Error(
                        authFailureMessage(payload?.status, payload?.message || "Request failed.")
                        || payload?.message
                        || "Request failed."
                    );
                    error.payload = payload;
                    throw error;
                }

                return payload;
            }

            function extractError(error) {
                if (error && typeof error === "object" && error.payload) {
                    return error.payload;
                }

                return null;
            }

            async function loadSettings() {
                if (!bootstrap?.endpoints?.load) {
                    return;
                }

                state.loading = true;
                setBusy(true);
                setAlert(globalAlert, "Loading email settings...", "neutral");

                try {
                    const response = await fetchJson(bootstrap.endpoints.load, {
                        method: "GET",
                    });
                    const data = response?.data || {};
                    state.settings = normalizeSettings(data.settings || null);
                    if (Array.isArray(data.settings?.available_providers)) {
                        state.availableProviders = data.settings.available_providers;
                    }
                    syncProviderDraftFromSettings();
                    setAlert(globalAlert, "", "neutral");
                    clearErrors();
                    populateFormFromState();
                } catch (error) {
                    const payload = extractError(error);
                    setAlert(globalAlert, payload?.message || error?.message || "Failed to load email settings.", "error");
                } finally {
                    state.loading = false;
                    setBusy(false);
                }
            }

            async function saveSettings() {
                clearErrors();
                setAlert(testAlert, "", "neutral");
                setBusy(true);
                setAlert(globalAlert, "Saving email settings...", "neutral");

                try {
                    const response = await fetchJson(bootstrap.endpoints.save, {
                        method: "POST",
                        body: JSON.stringify(collectPayload()),
                    });

                    state.pendingClearKey = false;
                    state.settings = normalizeSettings(response?.data?.settings || state.settings);
                    if (Array.isArray(response?.data?.settings?.available_providers)) {
                        state.availableProviders = response.data.settings.available_providers;
                    }

                    syncProviderDraftFromSettings();
                    populateFormFromState();
                    setAlert(globalAlert, response?.message || "Email settings saved.", "success");
                } catch (error) {
                    const payload = extractError(error);
                    setErrors(payload?.errors || {});
                    setAlert(globalAlert, payload?.message || error?.message || "Failed to save settings.", "error");
                } finally {
                    setBusy(false);
                }
            }

            async function validateSettings() {
                clearErrors();
                setBusy(true);
                setAlert(globalAlert, "Validating provider configuration...", "neutral");

                try {
                    const response = await fetchJson(bootstrap.endpoints.validate, {
                        method: "POST",
                        body: JSON.stringify({}),
                    });
                    state.settings = normalizeSettings(response?.data?.settings || state.settings);
                    syncProviderDraftFromSettings();
                    populateFormFromState();
                    setAlert(globalAlert, response?.message || "Provider configuration validated.", "success");
                } catch (error) {
                    const payload = extractError(error);
                    if (payload?.errors) {
                        setErrors(payload.errors);
                    }
                    if (payload?.data?.settings) {
                        state.settings = normalizeSettings(payload.data.settings);
                        syncProviderDraftFromSettings();
                        populateFormFromState();
                    }
                    setAlert(globalAlert, payload?.message || error?.message || "Provider validation failed.", "error");
                } finally {
                    setBusy(false);
                }
            }

            async function checkHealth() {
                clearErrors();
                setBusy(true);
                setAlert(globalAlert, "Checking provider health...", "neutral");

                try {
                    const response = await fetchJson(bootstrap.endpoints.health, {
                        method: "POST",
                        body: JSON.stringify({}),
                    });
                    state.settings = normalizeSettings(response?.data?.settings || state.settings);
                    syncProviderDraftFromSettings();
                    populateFormFromState();
                    setAlert(globalAlert, response?.message || "Provider health looks good.", "success");
                } catch (error) {
                    const payload = extractError(error);
                    if (payload?.data?.settings) {
                        state.settings = normalizeSettings(payload.data.settings);
                        syncProviderDraftFromSettings();
                        populateFormFromState();
                    }
                    setAlert(globalAlert, payload?.message || error?.message || "Provider health check failed.", "error");
                } finally {
                    setBusy(false);
                }
            }

            async function sendTestEmail() {
                clearErrors();
                setBusy(true);
                setAlert(testAlert, "Sending test email...", "neutral");

                try {
                    const response = await fetchJson(bootstrap.endpoints.test, {
                        method: "POST",
                        body: JSON.stringify({
                            to_email: normalizeString(testEmailInput.value),
                            subject: normalizeString(testSubjectInput.value),
                        }),
                    });
                    state.settings = normalizeSettings(response?.data?.settings || state.settings);
                    syncProviderDraftFromSettings();
                    populateFormFromState();
                    setAlert(testAlert, response?.message || "Test email sent.", "success");
                    setAlert(globalAlert, "Provider diagnostics updated after test send.", "success");
                } catch (error) {
                    const payload = extractError(error);
                    setErrors(payload?.errors || {});
                    if (payload?.data?.settings) {
                        state.settings = normalizeSettings(payload.data.settings);
                        syncProviderDraftFromSettings();
                        populateFormFromState();
                    }
                    setAlert(testAlert, payload?.message || error?.message || "Test email failed.", "error");
                } finally {
                    setBusy(false);
                }
            }

            providerSelect.addEventListener("change", () => {
                const previousProvider = String(state.settings.email_provider || "sendgrid");
                state.providerDrafts[previousProvider] = providerDraftFromDom(previousProvider);

                state.settings.email_provider = String(providerSelect.value || "sendgrid");
                state.settings.provider_config = {
                    ...(state.providerDrafts[state.settings.email_provider] || {}),
                };
                state.pendingClearKey = false;
                clearErrors();
                setAlert(globalAlert, "", "neutral");
                renderProviderFields();
                updateStatusBadges();
            });

            providerSettingsContent.addEventListener("change", (event) => {
                const target = event.target;
                if (!(target instanceof HTMLInputElement)) {
                    return;
                }

                if (target.id === "provider-clear-api-key") {
                    state.pendingClearKey = Boolean(target.checked);
                }
            });

            saveButton.addEventListener("click", () => {
                saveSettings();
            });

            validateButton.addEventListener("click", () => {
                validateSettings();
            });

            healthButton.addEventListener("click", () => {
                checkHealth();
            });

            testButton.addEventListener("click", () => {
                sendTestEmail();
            });

            clearKeyButton.addEventListener("click", () => {
                state.pendingClearKey = true;
                const clearToggle = document.getElementById("provider-clear-api-key");
                if (clearToggle instanceof HTMLInputElement) {
                    clearToggle.checked = true;
                }
                setAlert(globalAlert, "API key will be cleared on next save.", "neutral");
            });

            syncProviderDraftFromSettings();
            populateFormFromState();
            if (!bootstrap?.settings) {
                loadSettings();
            }
        })();
    </script>
</x-shopify-embedded-shell>
