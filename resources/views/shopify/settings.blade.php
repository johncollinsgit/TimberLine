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
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
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

        <article class="settings-card" id="widget-settings-card">
            <div class="settings-head">
                <div>
                    <h2>Storefront Widget Settings</h2>
                    <p>
                        Configure how wishlist and reviews widgets behave on the storefront. Defaults favor a right-side wishlist drawer and left-aligned reviews.
                    </p>
                </div>
                <div class="settings-badges" id="widget-settings-status"></div>
            </div>

            <div class="settings-inline-status" id="widget-settings-alert" hidden></div>

            <form id="widget-settings-form" class="settings-grid">
                <div class="settings-field">
                    <label for="widget-wishlist-behavior">Wishlist Behavior</label>
                    <select id="widget-wishlist-behavior" name="wishlist_behavior">
                        <option value="drawer">Open right-side drawer (recommended)</option>
                        <option value="account">Redirect to account page</option>
                    </select>
                    <small>Controls what happens when shoppers tap wishlist entry points.</small>
                    <div class="settings-field-error" data-error-for="wishlist_behavior"></div>
                </div>
                <div class="settings-field">
                    <label for="widget-wishlist-drawer-id">Drawer Element ID</label>
                    <input id="widget-wishlist-drawer-id" name="wishlist_drawer_id" type="text" maxlength="120" placeholder="sidebar-wishlist">
                    <small>Advanced: customize the drawer element target when using a custom section/snippet.</small>
                    <div class="settings-field-error" data-error-for="wishlist_drawer_id"></div>
                </div>
                <div class="settings-field">
                    <label for="widget-reviews-position">Reviews Placement</label>
                    <select id="widget-reviews-position" name="reviews_position">
                        <option value="left">Left (reviews) / Right (wishlist)</option>
                        <option value="right">Right (reviews) / Left (wishlist)</option>
                    </select>
                    <small>Applies to split PDP meta layouts on desktop.</small>
                    <div class="settings-field-error" data-error-for="reviews_position"></div>
                </div>
                <div class="settings-field">
                    <label for="widget-image-radius">Image Corner Radius (px)</label>
                    <input id="widget-image-radius" name="image_radius_px" type="number" min="4" max="32" step="1" placeholder="14">
                    <small>Shared radius for non-hero images in app-driven widgets.</small>
                    <div class="settings-field-error" data-error-for="image_radius_px"></div>
                </div>
            </form>

            <div class="settings-actions">
                <button class="settings-button settings-button--primary" type="button" id="widget-settings-save">Save Widget Settings</button>
            </div>
        </article>

        <article class="settings-card">
            <div class="settings-head">
                <div>
                    <h2>Email Settings</h2>
                    <p>
                        Configure tenant-branded email sending with a safe global SendGrid fallback. Tenants can keep using the
                        app-wide sender identity immediately, or move to a verified single sender or authenticated domain when ready.
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
                        <small>Tenants can send from their own company email/domain when it is verified in SendGrid. They do not need inboxes on our domain.</small>
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
                Run a health check or test send with the resolved tenant settings. If tenant branding is incomplete, the fallback
                preview below shows exactly which sender identity and API key source will be used.
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
            const widgetBootstrap = @json($widgetSettingsBootstrap ?? []);
            const root = document.getElementById("email-settings-root");
            if (!root) {
                return;
            }

            const form = document.getElementById("email-settings-form");
            const widgetForm = document.getElementById("widget-settings-form");
            const widgetAlert = document.getElementById("widget-settings-alert");
            const widgetStatus = document.getElementById("widget-settings-status");
            const widgetSaveButton = document.getElementById("widget-settings-save");
            const wishlistBehaviorSelect = document.getElementById("widget-wishlist-behavior");
            const wishlistDrawerInput = document.getElementById("widget-wishlist-drawer-id");
            const reviewsPositionSelect = document.getElementById("widget-reviews-position");
            const imageRadiusInput = document.getElementById("widget-image-radius");
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

            const widgetState = {
                loading: false,
                saving: false,
                settings: normalizeWidgetSettings(widgetBootstrap?.settings || widgetBootstrap?.defaults || null),
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
                    provider_status: "unknown",
                    provider_config: {},
                    analytics_enabled: true,
                    last_tested_at: null,
                    last_error: null,
                    provider_status_checked_at: null,
                    provider_status_message: null,
                    resolved_preview: {},
                    global_defaults: {},
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
                        draft_api_key: normalizeString(document.getElementById("provider-api-key")?.value || "")
                            || existing.draft_api_key
                            || null,
                        sender_mode: normalizeString(document.getElementById("provider-sender-mode")?.value || "")
                            || existing.sender_mode
                            || "global_fallback",
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
                const normalized = String(status || "unknown");
                if (normalized === "healthy" && settings?.last_tested_at) {
                    return "Test successful";
                }

                return ({
                    unknown: "Unknown",
                    healthy: "Healthy",
                    unhealthy: "Unhealthy",
                    unverified: "Needs verification",
                    testing: "Checking",
                })[normalized] || "Not configured";
            }

            function statusTone(status, settings) {
                const normalized = String(status || "unknown");
                if (normalized === "healthy" && settings?.last_tested_at) {
                    return "configured";
                }

                return ({
                    healthy: "configured",
                    unhealthy: "error",
                    unverified: "warn",
                    testing: "testing",
                    unknown: "warn",
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
                if (settings?.provider_config?.sender_mode) {
                    badges.push({
                        label: String(settings.provider_config.sender_mode).replaceAll("_", " "),
                        tone: "neutral",
                    });
                }
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

            function emailDomain(value) {
                const normalized = normalizeString(value);
                if (!normalized || !normalized.includes("@")) {
                    return null;
                }

                return normalized.split("@").pop()?.toLowerCase() || null;
            }

            function senderModeCopy(senderMode) {
                return ({
                    global_fallback: "Use the app-wide SendGrid sender identity and global key when tenant-specific values are missing.",
                    single_sender: "Verify a specific sender email in SendGrid, then run a successful test send before enabling live traffic.",
                    domain_authenticated: "Authenticate the tenant domain in SendGrid with SPF/DKIM records, then confirm delivery with a test send.",
                })[senderMode] || "Choose how this tenant should verify and send email.";
            }

            function senderModeWarning(settings, senderMode) {
                const tenantDomain = emailDomain(settings?.from_email);
                const globalDomain = emailDomain(settings?.global_defaults?.from_email);

                if (senderMode === "global_fallback" && tenantDomain && globalDomain && tenantDomain !== globalDomain) {
                    return `This tenant from address uses ${tenantDomain}, but the global fallback identity is ${globalDomain}. Confirm that SendGrid is allowed to send this sender before going live.`;
                }

                if (senderMode === "domain_authenticated" && tenantDomain && ["gmail.com", "yahoo.com", "outlook.com", "hotmail.com", "icloud.com"].includes(tenantDomain)) {
                    return "Domain-authenticated mode usually should not use a personal mailbox domain. Switch to a company domain that has SPF/DKIM records in SendGrid.";
                }

                if ((senderMode === "single_sender" || senderMode === "domain_authenticated") && !tenantDomain) {
                    return "Add a from email before running verification checks for this sender mode.";
                }

                return null;
            }

            function renderProviderFields() {
                const provider = String(state.settings.email_provider || "sendgrid");
                const config = state.settings.provider_config || {};
                const apiKeyMasked = String(config.api_key_masked || "").trim();
                const hasApiKey = Boolean(config.has_api_key);

                if (provider === "sendgrid") {
                    const senderMode = String(config.sender_mode || "global_fallback");
                    const resolvedPreview = state.settings?.resolved_preview || {};
                    const warning = senderModeWarning(state.settings, senderMode);
                    const apiKeyHelp = senderMode === "global_fallback"
                        ? "Optional. Leave blank to keep using the app-wide SendGrid API key."
                        : "Optional. Save a tenant key here to send through this tenant's own SendGrid account.";
                    const keySourceLabel = String(resolvedPreview.api_key_source || "global") === "tenant" ? "Tenant key" : "Global key";

                    providerSettingsContent.innerHTML = `
                        <div class="settings-provider-help">
                            SendGrid is fully supported for app-driven sends. Tenants can send from their own company email/domain if verified in SendGrid.
                            If nothing tenant-specific is configured, the app-wide sender identity remains the fallback.
                        </div>
                        <div class="settings-grid">
                            <div class="settings-field">
                                <label for="provider-sender-mode">Sender Mode</label>
                                <select id="provider-sender-mode" name="provider_config.sender_mode">
                                    <option value="global_fallback" ${senderMode === "global_fallback" ? "selected" : ""}>Global fallback</option>
                                    <option value="single_sender" ${senderMode === "single_sender" ? "selected" : ""}>Single sender</option>
                                    <option value="domain_authenticated" ${senderMode === "domain_authenticated" ? "selected" : ""}>Domain authenticated</option>
                                </select>
                                <small>${escapeHtml(senderModeCopy(senderMode))}</small>
                                <div class="settings-field-error" data-error-for="provider_config.sender_mode"></div>
                            </div>
                            <div class="settings-field" style="${senderMode === "global_fallback" ? "opacity: 0.72;" : ""}">
                                <label for="provider-api-key">SendGrid API Key</label>
                                <input id="provider-api-key" name="provider_config.api_key" type="password" maxlength="500" autocomplete="off" value="${escapeHtml(config.draft_api_key || "")}" placeholder="SG.xxxxx">
                                <small>${hasApiKey ? `Saved key: ${escapeHtml(apiKeyMasked || "********")}. Leave blank to keep current key. ${escapeHtml(apiKeyHelp)}` : escapeHtml(apiKeyHelp)}</small>
                                <div class="settings-field-error" data-error-for="provider_config.api_key"></div>
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
                        ${warning ? `<div class="settings-inline-status" data-tone="warn" style="margin-top: 12px;">${escapeHtml(warning)}</div>` : ""}
                        <div class="settings-provider-help" style="margin-top: 12px;">
                            <strong>Resolved preview</strong><br>
                            From: ${escapeHtml(resolvedPreview.from_name || state.settings.from_name || "Not set")} &lt;${escapeHtml(resolvedPreview.from_email || state.settings.from_email || "not set")}&gt;<br>
                            Reply-To: ${escapeHtml(resolvedPreview.reply_to_email || state.settings.reply_to_email || "Not set")}<br>
                            API key source: ${escapeHtml(keySourceLabel)}
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
                        sender_mode: normalizeString(document.getElementById("provider-sender-mode")?.value || "") || "global_fallback",
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

            function normalizeWidgetSettings(settings) {
                const defaults = widgetBootstrap?.defaults || {
                    wishlist_behavior: "drawer",
                    wishlist_drawer_id: "sidebar-wishlist",
                    reviews_position: "left",
                    image_radius_px: 14,
                };
                const normalized = typeof settings === "object" && settings !== null ? { ...settings } : {};
                const behavior = String(normalized.wishlist_behavior || defaults.wishlist_behavior).toLowerCase();
                const reviewsPosition = String(normalized.reviews_position || defaults.reviews_position).toLowerCase();
                const radius = Number.parseFloat(normalized.image_radius_px ?? defaults.image_radius_px);

                return {
                    wishlist_behavior: ["drawer", "account"].includes(behavior) ? behavior : defaults.wishlist_behavior,
                    wishlist_drawer_id: (normalized.wishlist_drawer_id || defaults.wishlist_drawer_id || "sidebar-wishlist").toString().trim() || "sidebar-wishlist",
                    reviews_position: ["left", "right"].includes(reviewsPosition) ? reviewsPosition : defaults.reviews_position,
                    image_radius_px: Number.isFinite(radius) ? Math.min(Math.max(radius, 4), 32) : defaults.image_radius_px,
                };
            }

            function populateWidgetSettings() {
                if (!wishlistBehaviorSelect || !reviewsPositionSelect || !imageRadiusInput) {
                    return;
                }

                wishlistBehaviorSelect.value = widgetState.settings.wishlist_behavior;
                reviewsPositionSelect.value = widgetState.settings.reviews_position;
                imageRadiusInput.value = widgetState.settings.image_radius_px;
                if (wishlistDrawerInput) {
                    wishlistDrawerInput.value = widgetState.settings.wishlist_drawer_id;
                }
                if (widgetStatus) {
                    widgetStatus.innerHTML = `
                        <span class="settings-badge">${widgetState.settings.wishlist_behavior === "drawer" ? "Drawer" : "Account redirect"}</span>
                        <span class="settings-badge">${widgetState.settings.reviews_position === "left" ? "Reviews left" : "Reviews right"}</span>
                        <span class="settings-badge">Radius ${widgetState.settings.image_radius_px}px</span>
                    `;
                }
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
                if (window.ForestryEmbeddedApp?.resolveEmbeddedAuthHeaders) {
                    try {
                        return await window.ForestryEmbeddedApp.resolveEmbeddedAuthHeaders();
                    } catch (error) {
                        throw new Error(
                            authFailureMessage(error?.code, error?.message || "Shopify Admin verification is unavailable."),
                        );
                    }
                }

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
                        new Promise((resolve) => window.setTimeout(() => resolve(null), 6000)),
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

            async function loadWidgetSettings() {
                if (!widgetBootstrap?.endpoints?.load || !widgetForm) {
                    return;
                }

                widgetState.loading = true;
                setAlert(widgetAlert, "Loading widget settings...", "neutral");
                try {
                    const response = await fetchJson(widgetBootstrap.endpoints.load, { method: "GET" });
                    widgetState.settings = normalizeWidgetSettings(response?.data?.settings || widgetBootstrap.defaults);
                    setAlert(widgetAlert, "", "neutral");
                    populateWidgetSettings();
                } catch (error) {
                    const payload = extractError(error);
                    setAlert(widgetAlert, payload?.message || error?.message || "Failed to load widget settings.", "error");
                } finally {
                    widgetState.loading = false;
                }
            }

            async function saveWidgetSettings() {
                if (!widgetBootstrap?.endpoints?.save || !widgetForm) {
                    return;
                }

                clearErrors();
                const payload = {
                    wishlist_behavior: String(wishlistBehaviorSelect?.value || widgetState.settings.wishlist_behavior || "drawer"),
                    wishlist_drawer_id: (wishlistDrawerInput?.value || widgetState.settings.wishlist_drawer_id || "sidebar-wishlist").trim() || "sidebar-wishlist",
                    reviews_position: String(reviewsPositionSelect?.value || widgetState.settings.reviews_position || "left"),
                    image_radius_px: Number.parseFloat(imageRadiusInput?.value || widgetState.settings.image_radius_px || 14),
                };

                widgetState.saving = true;
                setAlert(widgetAlert, "Saving widget settings...", "neutral");

                try {
                    const response = await fetchJson(widgetBootstrap.endpoints.save, {
                        method: "POST",
                        body: JSON.stringify(payload),
                    });
                    widgetState.settings = normalizeWidgetSettings(response?.data?.settings || payload);
                    populateWidgetSettings();
                    setAlert(widgetAlert, "Widget settings saved.", "success");
                } catch (error) {
                    const payloadError = extractError(error);
                    const errors = payloadError?.errors || {};
                    Object.entries(errors).forEach(([field, messages]) => {
                        const target = widgetForm.querySelector(`[data-error-for=\"${cssEscape(String(field))}\"]`);
                        if (target) {
                            target.textContent = Array.isArray(messages) ? messages.join(" ") : String(messages || "");
                        }
                    });
                    setAlert(widgetAlert, payloadError?.message || error?.message || "Failed to save widget settings.", "error");
                } finally {
                    widgetState.saving = false;
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
                if (!(target instanceof HTMLInputElement) && !(target instanceof HTMLSelectElement)) {
                    return;
                }

                if (target.id === "provider-clear-api-key") {
                    state.pendingClearKey = Boolean(target.checked);
                }

                if (target.id === "provider-sender-mode") {
                    state.settings.provider_config = {
                        ...(providerDraftFromDom("sendgrid") || {}),
                    };
                    renderProviderFields();
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

            if (widgetSaveButton) {
                widgetSaveButton.addEventListener("click", () => saveWidgetSettings());
            }

            populateWidgetSettings();
            if (!widgetBootstrap?.settings) {
                loadWidgetSettings();
            }

            syncProviderDraftFromSettings();
            populateFormFromState();
            if (!bootstrap?.settings) {
                loadSettings();
            }
        })();
    </script>
</x-shopify-embedded-shell>
