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
        $publishedContent = is_array(data_get($appContentBootstrap, 'settings.published'))
            ? data_get($appContentBootstrap, 'settings.published')
            : data_get($appContentBootstrap, 'defaults', []);
        $draftContent = is_array(data_get($appContentBootstrap, 'settings.draft'))
            ? data_get($appContentBootstrap, 'settings.draft')
            : data_get($appContentBootstrap, 'defaults', []);
        $dashboardFields = [
            ['brand_name', 'Brand Name', 'text', 120],
            ['hero_eyebrow', 'Hero Eyebrow', 'text', 120],
            ['hero_title', 'Hero Title', 'text', 160],
            ['hero_body', 'Hero Body', 'textarea', 240],
            ['primary_cta_label', 'Primary CTA', 'text', 80],
            ['secondary_cta_label', 'Secondary CTA', 'text', 80],
            ['rewards_title', 'Rewards Title', 'text', 120],
            ['rewards_body', 'Rewards Body', 'textarea', 240],
            ['orders_title', 'Orders Title', 'text', 120],
            ['orders_body', 'Orders Body', 'textarea', 240],
            ['support_title', 'Support Title', 'text', 120],
            ['support_body', 'Support Body', 'textarea', 240],
            ['support_cta_label', 'Support CTA', 'text', 80],
            ['support_email', 'Support Email', 'email', 255],
            ['support_url', 'Support URL', 'url', 500],
            ['privacy_url', 'Privacy URL', 'url', 500],
            ['terms_url', 'Terms URL', 'url', 500],
            ['data_deletion_url', 'Data Request URL', 'url', 500],
            ['data_deletion_email', 'Data Request Email', 'email', 255],
            ['empty_rewards', 'Empty Rewards Copy', 'text', 240],
            ['empty_orders', 'Empty Orders Copy', 'text', 240],
            ['account_note', 'Account Note', 'textarea', 240],
        ];
        $mobileFields = [
            ['mobile_home_eyebrow', 'Home Eyebrow', 'text', 120],
            ['mobile_home_title', 'Home Title', 'text', 160],
            ['mobile_home_subtitle', 'Home Subtitle', 'textarea', 240],
            ['mobile_slide_1_title', 'Slide 1 Title', 'text', 160],
            ['mobile_slide_1_subtitle', 'Slide 1 Subtitle', 'textarea', 240],
            ['mobile_slide_1_image_url', 'Slide 1 Image URL', 'url', 500],
            ['mobile_slide_1_mobile_image_url', 'Slide 1 Mobile Image URL', 'url', 500],
            ['mobile_slide_1_cta_label', 'Slide 1 CTA Label', 'text', 80],
            ['mobile_slide_1_cta_url', 'Slide 1 CTA URL', 'url', 500],
            ['mobile_slide_2_title', 'Slide 2 Title', 'text', 160],
            ['mobile_slide_2_subtitle', 'Slide 2 Subtitle', 'textarea', 240],
            ['mobile_slide_2_image_url', 'Slide 2 Image URL', 'url', 500],
            ['mobile_slide_2_mobile_image_url', 'Slide 2 Mobile Image URL', 'url', 500],
            ['mobile_slide_2_cta_label', 'Slide 2 CTA Label', 'text', 80],
            ['mobile_slide_2_cta_url', 'Slide 2 CTA URL', 'url', 500],
            ['mobile_slide_3_title', 'Slide 3 Title', 'text', 160],
            ['mobile_slide_3_subtitle', 'Slide 3 Subtitle', 'textarea', 240],
            ['mobile_slide_3_image_url', 'Slide 3 Image URL', 'url', 500],
            ['mobile_slide_3_mobile_image_url', 'Slide 3 Mobile Image URL', 'url', 500],
            ['mobile_slide_3_cta_label', 'Slide 3 CTA Label', 'text', 80],
            ['mobile_slide_3_cta_url', 'Slide 3 CTA URL', 'url', 500],
        ];
        $fieldId = fn (string $name): string => 'content-'.str_replace('_', '-', $name);
    @endphp

    <style>
        .app-editor-root {
            display: grid;
            gap: 16px;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
        }

        .app-editor-card {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.96);
            padding: 18px;
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.04);
        }

        .app-editor-head {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .app-editor-head h2,
        .app-editor-preview h3 {
            margin: 0;
            color: #0f172a;
        }

        .app-editor-head h2 {
            font-size: 1.18rem;
        }

        .app-editor-head p,
        .app-editor-preview p,
        .app-editor-field small {
            margin: 8px 0 0;
            color: rgba(15, 23, 42, 0.64);
            font-size: 14px;
            line-height: 1.55;
        }

        .app-editor-badges,
        .app-editor-actions,
        .app-editor-tabs {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }

        .app-editor-badge {
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

        .app-editor-badge--configured {
            color: #0f766e;
            border-color: rgba(15, 118, 110, 0.24);
            background: rgba(15, 118, 110, 0.12);
        }

        .app-editor-alert {
            margin-bottom: 14px;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.94);
            color: rgba(15, 23, 42, 0.75);
            padding: 12px 14px;
            font-size: 13px;
            line-height: 1.55;
        }

        .app-editor-alert[data-tone="success"] {
            border-color: rgba(15, 118, 110, 0.24);
            background: rgba(15, 118, 110, 0.1);
            color: #0f766e;
        }

        .app-editor-alert[data-tone="error"] {
            border-color: rgba(180, 35, 24, 0.24);
            background: rgba(180, 35, 24, 0.08);
            color: #b42318;
        }

        .app-editor-alert[hidden],
        .app-editor-panel[hidden] {
            display: none;
        }

        .app-editor-tabs {
            border-radius: 14px;
            background: rgba(241, 245, 249, 0.9);
            padding: 4px;
            margin-bottom: 16px;
        }

        .app-editor-tab,
        .app-editor-button {
            appearance: none;
            border: 1px solid transparent;
            cursor: pointer;
            font-weight: 700;
            transition: transform 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
        }

        .app-editor-tab {
            border-radius: 10px;
            background: transparent;
            color: rgba(15, 23, 42, 0.62);
            padding: 9px 12px;
        }

        .app-editor-tab.is-active {
            color: #0f172a;
            background: #ffffff;
            border-color: rgba(15, 23, 42, 0.08);
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        }

        .app-editor-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .app-editor-field {
            display: grid;
            gap: 6px;
        }

        .app-editor-field--wide {
            grid-column: 1 / -1;
        }

        .app-editor-field label {
            color: rgba(15, 23, 42, 0.5);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.09em;
            text-transform: uppercase;
        }

        .app-editor-field input,
        .app-editor-field textarea {
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

        .app-editor-field textarea {
            min-height: 102px;
            resize: vertical;
        }

        .app-editor-field input:focus,
        .app-editor-field textarea:focus {
            outline: none;
            border-color: rgba(15, 143, 97, 0.36);
            box-shadow: 0 0 0 4px rgba(15, 143, 97, 0.1);
        }

        .app-editor-field-error {
            min-height: 16px;
            color: #b42318;
            font-size: 12px;
        }

        .app-editor-actions {
            justify-content: flex-end;
            margin-top: 16px;
        }

        .app-editor-button {
            border-radius: 12px;
            padding: 10px 14px;
            background: #ffffff;
            color: #0f172a;
            border-color: rgba(15, 23, 42, 0.14);
        }

        .app-editor-button--primary {
            background: #0f5f46;
            color: #ffffff;
            border-color: rgba(15, 95, 70, 0.64);
            box-shadow: 0 10px 18px rgba(15, 95, 70, 0.16);
        }

        .app-editor-button:disabled {
            cursor: wait;
            opacity: 0.6;
        }

        .app-editor-preview-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            margin-top: 14px;
        }

        .app-editor-preview {
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.86);
            padding: 14px;
        }

        .app-editor-preview ul {
            margin: 12px 0 0;
            padding-left: 18px;
            color: rgba(15, 23, 42, 0.72);
            font-size: 13px;
            line-height: 1.55;
        }

        @media (max-width: 760px) {
            .app-editor-grid,
            .app-editor-preview-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>

    <section class="app-editor-root" id="app-content-editor-root">
        @if(is_array($appContentBootstrap ?? null) && (bool) ($appContentBootstrap['authorized'] ?? false))
            <article class="app-editor-card" id="app-content-card">
                <div class="app-editor-head">
                    <div>
                        <h2>App Content</h2>
                        <p>Update customer dashboard and mobile app copy. Draft changes stay private until you publish them.</p>
                    </div>
                    <div class="app-editor-badges" id="app-content-status">
                        <span class="app-editor-badge">Draft ready</span>
                        <span class="app-editor-badge app-editor-badge--configured">Published live</span>
                    </div>
                </div>

                <div class="app-editor-alert" id="app-content-alert" hidden></div>

                <form id="app-content-form">
                    <div class="app-editor-tabs" role="tablist" aria-label="Edit App sections">
                        <button class="app-editor-tab is-active" id="app-content-tab-dashboard" type="button" role="tab" aria-selected="true" aria-controls="app-content-panel-dashboard" data-app-content-tab="dashboard">Customer Dashboard</button>
                        <button class="app-editor-tab" id="app-content-tab-mobile" type="button" role="tab" aria-selected="false" aria-controls="app-content-panel-mobile" data-app-content-tab="mobile">Mobile Home</button>
                    </div>

                    <div class="app-editor-panel" id="app-content-panel-dashboard" role="tabpanel" aria-labelledby="app-content-tab-dashboard">
                        <div class="app-editor-grid">
                            @foreach($dashboardFields as [$name, $label, $type, $max])
                                <div class="app-editor-field {{ $type === 'textarea' ? 'app-editor-field--wide' : '' }}">
                                    <label for="{{ $fieldId($name) }}">{{ $label }}</label>
                                    @if($type === 'textarea')
                                        <textarea id="{{ $fieldId($name) }}" name="{{ $name }}" maxlength="{{ $max }}">{{ data_get($draftContent, $name, data_get($appContentBootstrap, 'defaults.'.$name, '')) }}</textarea>
                                    @else
                                        <input id="{{ $fieldId($name) }}" name="{{ $name }}" type="{{ $type }}" maxlength="{{ $max }}" value="{{ data_get($draftContent, $name, data_get($appContentBootstrap, 'defaults.'.$name, '')) }}">
                                    @endif
                                    <div class="app-editor-field-error" data-error-for="{{ $name }}"></div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="app-editor-panel" id="app-content-panel-mobile" role="tabpanel" aria-labelledby="app-content-tab-mobile" hidden>
                        <div class="app-editor-grid">
                            @foreach($mobileFields as [$name, $label, $type, $max])
                                <div class="app-editor-field {{ $type === 'textarea' ? 'app-editor-field--wide' : '' }}">
                                    <label for="{{ $fieldId($name) }}">{{ $label }}</label>
                                    @if($type === 'textarea')
                                        <textarea id="{{ $fieldId($name) }}" name="{{ $name }}" maxlength="{{ $max }}">{{ data_get($draftContent, $name, data_get($appContentBootstrap, 'defaults.'.$name, '')) }}</textarea>
                                    @else
                                        <input id="{{ $fieldId($name) }}" name="{{ $name }}" type="{{ $type }}" maxlength="{{ $max }}" value="{{ data_get($draftContent, $name, data_get($appContentBootstrap, 'defaults.'.$name, '')) }}">
                                    @endif
                                    <div class="app-editor-field-error" data-error-for="{{ $name }}"></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </form>

                <div class="app-editor-actions">
                    <button class="app-editor-button" type="button" id="app-content-save">Save Draft</button>
                    <button class="app-editor-button app-editor-button--primary" type="button" id="app-content-publish">Publish Live</button>
                </div>
            </article>

            <article class="app-editor-card">
                <div class="app-editor-head">
                    <div>
                        <h2>Preview</h2>
                        <p>Draft updates below refresh as you type. Live content reflects the latest published snapshot.</p>
                    </div>
                </div>
                <div class="app-editor-preview-grid">
                    <div class="app-editor-preview" id="content-preview-draft"></div>
                    <div class="app-editor-preview" id="content-preview-live"></div>
                </div>
            </article>
        @else
            <article class="app-editor-card">
                <div class="app-editor-head">
                    <div>
                        <h2>Edit App is unavailable</h2>
                        <p>Modern Forestry app editing is available only when the embedded Shopify store is mapped to tenant 1.</p>
                    </div>
                </div>
            </article>
        @endif
    </section>

    <script>
        (() => {
            const bootstrap = @json($appContentBootstrap ?? []);
            const root = document.getElementById("app-content-editor-root");
            const form = document.getElementById("app-content-form");
            const alertNode = document.getElementById("app-content-alert");
            const saveButton = document.getElementById("app-content-save");
            const publishButton = document.getElementById("app-content-publish");
            const draftPreview = document.getElementById("content-preview-draft");
            const livePreview = document.getElementById("content-preview-live");
            const tabs = Array.from(root?.querySelectorAll("[data-app-content-tab]") || []);
            const panels = {
                dashboard: document.getElementById("app-content-panel-dashboard"),
                mobile: document.getElementById("app-content-panel-mobile"),
            };

            if (!root || !form) {
                return;
            }

            const state = {
                defaults: normalizeContent(bootstrap?.defaults || {}),
                draft: normalizeContent(bootstrap?.settings?.draft || bootstrap?.settings?.effective || bootstrap?.defaults || {}),
                published: bootstrap?.settings?.published ? normalizeContent(bootstrap.settings.published) : null,
                saving: false,
            };

            function normalizeString(value) {
                return String(value || "").trim();
            }

            function normalizeContent(input) {
                const defaults = {
                    brand_name: "Modern Forestry",
                    hero_eyebrow: "Customer account",
                    hero_title: "Your Modern Forestry account",
                    hero_body: "Check rewards, recent orders, and quick actions in one place.",
                    primary_cta_label: "View rewards",
                    secondary_cta_label: "Review orders",
                    rewards_title: "Rewards",
                    rewards_body: "Redeem on Shopify checkout when you are ready.",
                    orders_title: "Recent orders",
                    orders_body: "Reorder the items you want again with a Shopify cart handoff.",
                    support_title: "Support",
                    support_body: "Need help? Reach out and we will follow up.",
                    support_cta_label: "Contact support",
                    support_email: "support@modernforestry.com",
                    support_url: "",
                    privacy_url: "https://modernforestry.com/policies/privacy-policy",
                    terms_url: "https://modernforestry.com/policies/terms-of-service",
                    data_deletion_url: "",
                    data_deletion_email: "support@modernforestry.com",
                    empty_rewards: "No active rewards right now.",
                    empty_orders: "No recent orders yet.",
                    account_note: "For privacy or account data requests, contact Modern Forestry support.",
                    mobile_home_eyebrow: "Modern Forestry",
                    mobile_home_title: "Hand-poured candles for a slower season.",
                    mobile_home_subtitle: "Small-batch scents, seasonal favorites, and Candle Cash rewards.",
                    mobile_slide_1_title: "Shop our Spring Collection",
                    mobile_slide_1_subtitle: "",
                    mobile_slide_1_image_url: "",
                    mobile_slide_1_mobile_image_url: "",
                    mobile_slide_1_cta_label: "Click to Shop",
                    mobile_slide_1_cta_url: "",
                    mobile_slide_2_title: "Classic scents, always ready",
                    mobile_slide_2_subtitle: "Keep your favorites close.",
                    mobile_slide_2_image_url: "",
                    mobile_slide_2_mobile_image_url: "",
                    mobile_slide_2_cta_label: "Shop Classic",
                    mobile_slide_2_cta_url: "",
                    mobile_slide_3_title: "Earn Candle Cash",
                    mobile_slide_3_subtitle: "Shop, review, and redeem rewards.",
                    mobile_slide_3_image_url: "",
                    mobile_slide_3_mobile_image_url: "",
                    mobile_slide_3_cta_label: "View Rewards",
                    mobile_slide_3_cta_url: "",
                };
                return { ...defaults, ...(input && typeof input === "object" ? input : {}) };
            }

            function setAlert(message, tone = "neutral") {
                const normalized = normalizeString(message);
                if (normalized === "") {
                    alertNode.hidden = true;
                    alertNode.textContent = "";
                    alertNode.removeAttribute("data-tone");
                    return;
                }
                alertNode.hidden = false;
                alertNode.textContent = normalized;
                if (tone === "neutral") {
                    alertNode.removeAttribute("data-tone");
                } else {
                    alertNode.setAttribute("data-tone", tone);
                }
            }

            function cssEscape(value) {
                if (window.CSS && typeof window.CSS.escape === "function") {
                    return window.CSS.escape(String(value));
                }
                return String(value).replace(/"/g, '\\"');
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
                    node.textContent = Array.isArray(value) ? String(value[0] || "") : String(value || "");
                });
            }

            function setBusy(isBusy) {
                state.saving = Boolean(isBusy);
                [saveButton, publishButton].forEach((button) => {
                    if (button) {
                        button.disabled = state.saving;
                    }
                });
                Array.from(form.elements || []).forEach((element) => {
                    if (element instanceof HTMLElement) {
                        element.toggleAttribute("disabled", state.saving);
                    }
                });
            }

            function authFailureMessage(status, fallbackMessage) {
                const messages = {
                    missing_api_auth: "Shopify Admin verification is unavailable. Reload Edit App from Shopify Admin and try again.",
                    invalid_session_token: "Shopify Admin verification failed. Reload Edit App from Shopify Admin and try again.",
                    expired_session_token: "Your Shopify Admin session expired. Reload Edit App from Shopify Admin and try again.",
                };
                return messages[status] || fallbackMessage || null;
            }

            async function resolveEmbeddedAuthHeaders() {
                const resolver = window.ForestryEmbeddedApp?.resolveEmbeddedAuthHeaders;
                if (typeof resolver !== "function") {
                    throw new Error(authFailureMessage("missing_api_auth", "Shopify Admin verification is unavailable."));
                }
                try {
                    return await resolver();
                } catch (error) {
                    throw new Error(authFailureMessage(error?.code, error?.message || "Shopify Admin verification is unavailable."));
                }
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
                    message: "Unexpected response from Everbranch.",
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

            function collectPayload() {
                const payload = {};
                const data = new FormData(form);
                data.forEach((value, key) => {
                    payload[key] = normalizeString(value);
                });
                return payload;
            }

            function renderPreviewCard(node, title, content) {
                if (!node) {
                    return;
                }
                const slides = [1, 2, 3].map((index) => ({
                    title: content[`mobile_slide_${index}_title`] || `Slide ${index}`,
                    cta: content[`mobile_slide_${index}_cta_label`] || "Open",
                }));
                node.innerHTML = `
                    <h3>${escapeHtml(title)}</h3>
                    <p><strong>${escapeHtml(content.hero_title || content.mobile_home_title || "Untitled")}</strong></p>
                    <p>${escapeHtml(content.hero_body || content.mobile_home_subtitle || "")}</p>
                    <ul>
                        <li>Dashboard: ${escapeHtml(content.rewards_title || "Rewards")} / ${escapeHtml(content.orders_title || "Orders")}</li>
                        <li>Mobile Home: ${escapeHtml(content.mobile_home_title || "Home")}</li>
                        <li>Slides: ${slides.map((slide) => `${slide.title} (${slide.cta})`).map(escapeHtml).join(", ")}</li>
                    </ul>
                `;
            }

            function escapeHtml(value) {
                return String(value || "")
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }

            function renderPreviews() {
                const draft = normalizeContent({ ...state.draft, ...collectPayload() });
                renderPreviewCard(draftPreview, "Draft Preview", draft);
                renderPreviewCard(livePreview, "Live Preview", state.published || state.defaults);
            }

            async function saveContent(publish = false) {
                const contentPayload = collectPayload();
                setBusy(true);
                setAlert(publish ? "Publishing app content..." : "Saving app content...");
                clearErrors();
                try {
                    const payload = await fetchJson(publish ? bootstrap?.endpoints?.publish : bootstrap?.endpoints?.save, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "Accept": "application/json",
                        },
                        body: JSON.stringify(contentPayload),
                    });
                    state.draft = normalizeContent(payload?.data?.settings?.draft || contentPayload);
                    state.published = payload?.data?.settings?.published
                        ? normalizeContent(payload.data.settings.published)
                        : state.published;
                    setAlert(payload?.message || (publish ? "App content published." : "App content saved."), "success");
                    renderPreviews();
                    window.ForestryEmbeddedApp?.showToast?.(payload?.message || "Saved.", "success");
                } catch (error) {
                    setErrors(error?.payload?.errors || {});
                    const message = error?.message || (publish ? "App content could not be published." : "App content could not be saved.");
                    setAlert(message, "error");
                    window.ForestryEmbeddedApp?.showToast?.(message, "error");
                } finally {
                    setBusy(false);
                }
            }

            function activateTab(tabKey) {
                tabs.forEach((tab) => {
                    const active = String(tab.dataset.appContentTab || "") === tabKey;
                    tab.classList.toggle("is-active", active);
                    tab.setAttribute("aria-selected", active ? "true" : "false");
                });
                Object.entries(panels).forEach(([key, panel]) => {
                    if (panel) {
                        panel.hidden = key !== tabKey;
                    }
                });
            }

            tabs.forEach((tab) => {
                tab.addEventListener("click", () => activateTab(String(tab.dataset.appContentTab || "dashboard")));
            });
            form.addEventListener("input", renderPreviews);
            saveButton?.addEventListener("click", () => saveContent(false));
            publishButton?.addEventListener("click", () => saveContent(true));
            renderPreviews();
        })();
    </script>
</x-shopify-embedded-shell>
