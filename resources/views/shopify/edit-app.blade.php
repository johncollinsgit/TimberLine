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
            max-width: 1440px;
            width: 100%;
            margin: 0 auto;
        }

        .app-editor-workspace {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(340px, 420px);
            align-items: start;
            gap: 18px;
        }

        .app-editor-preview-card {
            position: sticky;
            top: 18px;
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

        .app-preview-frame {
            display: flex;
            justify-content: center;
            padding: 10px 0 2px;
        }

        .app-phone {
            width: min(100%, 360px);
            aspect-ratio: 390 / 844;
            border-radius: 38px;
            border: 10px solid #101828;
            background: #f8f4ec;
            box-shadow: 0 24px 48px rgba(15, 23, 42, 0.18);
            overflow: hidden;
            position: relative;
        }

        .app-phone::before {
            content: "";
            position: absolute;
            top: 8px;
            left: 50%;
            transform: translateX(-50%);
            width: 84px;
            height: 22px;
            border-radius: 999px;
            background: #101828;
            z-index: 4;
        }

        .app-phone-screen {
            height: 100%;
            overflow: auto;
            background: #f7f1e7;
            color: #1f2937;
            scrollbar-width: thin;
        }

        .app-phone-status {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 22px 8px;
            font-size: 11px;
            font-weight: 800;
            color: #1f2937;
        }

        .app-phone-status-icons {
            display: inline-flex;
            gap: 4px;
            align-items: center;
        }

        .app-phone-status-icons span {
            display: block;
            width: 14px;
            height: 7px;
            border-radius: 999px;
            background: rgba(31, 41, 55, 0.72);
        }

        .app-phone-body {
            display: grid;
            gap: 18px;
            padding: 12px 14px 24px;
        }

        .app-phone-brand {
            text-align: center;
            display: grid;
            gap: 2px;
        }

        .app-phone-brand strong {
            font-family: Georgia, "Times New Roman", serif;
            font-size: 19px;
            letter-spacing: 0.02em;
            color: #1f2a21;
        }

        .app-phone-brand span {
            font-size: 10px;
            letter-spacing: 0.28em;
            text-transform: uppercase;
            color: #6f7f68;
        }

        .app-phone-hero {
            min-height: 382px;
            border-radius: 32px;
            background: linear-gradient(180deg, #f8fafc, #efe7d7);
            overflow: hidden;
            position: relative;
            box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.08);
        }

        .app-phone-hero img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .app-phone-hero::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(0, 0, 0, 0.04), rgba(0, 0, 0, 0.24), rgba(0, 0, 0, 0.76));
        }

        .app-phone-hero-copy {
            position: absolute;
            left: 22px;
            right: 22px;
            bottom: 46px;
            z-index: 2;
            display: grid;
            gap: 9px;
            color: #ffffff;
        }

        .app-phone-hero-copy strong {
            font-family: Georgia, "Times New Roman", serif;
            font-size: 27px;
            line-height: 1.04;
            font-weight: 600;
        }

        .app-phone-hero-copy p {
            margin: 0;
            font-size: 13px;
            line-height: 1.36;
            color: rgba(255, 255, 255, 0.88);
        }

        .app-phone-hero-cta {
            width: fit-content;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            padding: 9px 13px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #fffaf0;
        }

        .app-phone-dots {
            position: absolute;
            left: 22px;
            bottom: 18px;
            z-index: 2;
            display: flex;
            gap: 7px;
        }

        .app-phone-dots span {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.46);
        }

        .app-phone-dots span:first-child {
            width: 24px;
            background: #ffffff;
        }

        .app-phone-section {
            display: grid;
            gap: 11px;
        }

        .app-phone-section h3 {
            margin: 0;
            color: #6d856b;
            font-size: 13px;
            font-weight: 900;
            letter-spacing: 0.2em;
            text-transform: uppercase;
        }

        .app-phone-rail {
            display: flex;
            gap: 12px;
            overflow: hidden;
        }

        .app-phone-collection-card,
        .app-phone-product-card {
            flex: 0 0 auto;
            border-radius: 22px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: #fffdf9;
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }

        .app-phone-collection-card {
            width: 144px;
            padding: 11px;
        }

        .app-phone-collection-card img,
        .app-phone-product-card img,
        .app-phone-image-placeholder {
            display: block;
            width: 100%;
            aspect-ratio: 1;
            object-fit: cover;
            border-radius: 18px;
            background: #f1eadf;
        }

        .app-phone-image-placeholder {
            border: 1px dashed rgba(15, 23, 42, 0.14);
        }

        .app-phone-card-title {
            margin: 10px 0 0;
            font-size: 14px;
            line-height: 1.16;
            font-weight: 800;
            color: #1f2937;
        }

        .app-phone-card-subtitle {
            margin: 6px 0 0;
            min-height: 30px;
            color: #6f6a60;
            font-size: 11px;
            line-height: 1.28;
        }

        .app-phone-products-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .app-phone-product-card {
            padding: 11px;
            min-height: 228px;
        }

        .app-phone-price {
            margin-top: 14px;
            color: #6f6a60;
            font-size: 13px;
            font-weight: 800;
        }

        .app-preview-note {
            margin: 12px 0 0;
            color: rgba(15, 23, 42, 0.58);
            font-size: 12px;
            line-height: 1.45;
        }

        .app-preview-warning {
            margin-top: 10px;
            border-radius: 12px;
            border: 1px solid rgba(180, 35, 24, 0.2);
            background: rgba(180, 35, 24, 0.08);
            color: #b42318;
            padding: 10px 12px;
            font-size: 12px;
            line-height: 1.45;
        }

        .app-preview-warning[hidden] {
            display: none;
        }

        @media (max-width: 760px) {
            .app-editor-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 1100px) {
            .app-editor-workspace {
                grid-template-columns: minmax(0, 1fr);
            }

            .app-editor-preview-card {
                position: static;
            }
        }
    </style>

    <section class="app-editor-root" id="app-content-editor-root">
        @if(is_array($appContentBootstrap ?? null) && (bool) ($appContentBootstrap['authorized'] ?? false))
            <div class="app-editor-workspace">
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

                <article class="app-editor-card app-editor-preview-card">
                    <div class="app-editor-head">
                        <div>
                            <h2>Live Mobile Preview</h2>
                            <p>Draft Mobile Home fields render here as you type. Publish still controls what the iPhone app receives.</p>
                        </div>
                    </div>
                    <div class="app-preview-frame">
                        <div class="app-phone" aria-label="Mobile app home preview">
                            <div class="app-phone-screen">
                                <div class="app-phone-status">
                                    <span>9:41</span>
                                    <span class="app-phone-status-icons" aria-hidden="true"><span></span><span></span></span>
                                </div>
                                <div class="app-phone-body" id="app-phone-preview"></div>
                            </div>
                        </div>
                    </div>
                    <p class="app-preview-note">
                        Product and collection shelves use live mobile API data when available. Hero copy and image URLs use the current draft.
                    </p>
                    <div class="app-preview-warning" id="app-preview-warning" hidden></div>
                </article>
            </div>
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
            const phonePreview = document.getElementById("app-phone-preview");
            const previewWarning = document.getElementById("app-preview-warning");
            const tabs = Array.from(root?.querySelectorAll("[data-app-content-tab]") || []);
            const mobileHomeEndpoint = @json(route('mobile.modern-forestry.home', [], false));
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
                liveHome: null,
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

            function escapeHtml(value) {
                return String(value || "")
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }

            function safeImageUrl(value) {
                const normalized = normalizeString(value);
                if (normalized === "") {
                    return "";
                }

                try {
                    const url = new URL(normalized, window.location.origin);
                    return ["http:", "https:"].includes(url.protocol) ? url.href : "";
                } catch (_error) {
                    return "";
                }
            }

            function formatPrice(value) {
                if (typeof value === "number" && Number.isFinite(value)) {
                    return `$${value.toFixed(2)}`;
                }

                const normalized = normalizeString(value);
                if (normalized === "") {
                    return "$30.00";
                }

                return normalized.startsWith("$") ? normalized : `$${normalized}`;
            }

            function fallbackCollections() {
                return [
                    {
                        title: "Spring",
                        description: "Fresh florals and brighter seasonal favorites.",
                        imageUrl: state.defaults.mobile_slide_1_image_url,
                    },
                    {
                        title: "Classic",
                        description: "Core scents ready all year.",
                        imageUrl: state.defaults.mobile_slide_2_image_url,
                    },
                    {
                        title: "Holiday",
                        description: "Seasonal candles for gifts and gatherings.",
                        imageUrl: state.defaults.mobile_slide_3_image_url,
                    },
                ];
            }

            function fallbackProducts() {
                return [
                    {
                        title: "Sale Candles",
                        price: "$14.00",
                        imageUrl: state.defaults.mobile_slide_1_image_url,
                    },
                    {
                        title: "Holiday Tree Candle",
                        price: "$35.00",
                        imageUrl: state.defaults.mobile_slide_2_image_url,
                    },
                    {
                        title: "Thru Hike",
                        price: "$30.00",
                        imageUrl: state.defaults.mobile_slide_3_image_url,
                    },
                    {
                        title: "Summer Linen",
                        price: "$30.00",
                        imageUrl: state.defaults.mobile_slide_1_image_url,
                    },
                ];
            }

            function currentSlides(content) {
                return [1, 2, 3].map((index) => ({
                    title: normalizeString(content[`mobile_slide_${index}_title`]) || `Slide ${index}`,
                    subtitle: normalizeString(content[`mobile_slide_${index}_subtitle`]),
                    imageUrl: safeImageUrl(content[`mobile_slide_${index}_mobile_image_url`])
                        || safeImageUrl(content[`mobile_slide_${index}_image_url`]),
                    cta: normalizeString(content[`mobile_slide_${index}_cta_label`]) || "Open",
                })).filter((slide) => slide.title !== "" || slide.imageUrl !== "");
            }

            function shelfImage(item) {
                return safeImageUrl(item?.imageUrl || item?.image_url || item?.image || "");
            }

            function renderImage(url, alt) {
                const safeUrl = safeImageUrl(url);
                if (safeUrl === "") {
                    return `<div class="app-phone-image-placeholder" role="img" aria-label="${escapeHtml(alt)}"></div>`;
                }

                return `<img src="${escapeHtml(safeUrl)}" alt="${escapeHtml(alt)}" loading="lazy" onerror="this.style.visibility='hidden'">`;
            }

            function renderCollectionCard(collection) {
                const title = normalizeString(collection?.title) || "Collection";
                const description = normalizeString(collection?.description) || "Browse collection";
                return `
                    <div class="app-phone-collection-card">
                        ${renderImage(shelfImage(collection), title)}
                        <div class="app-phone-card-title">${escapeHtml(title)}</div>
                        <div class="app-phone-card-subtitle">${escapeHtml(description)}</div>
                    </div>
                `;
            }

            function renderProductCard(product) {
                const title = normalizeString(product?.title) || "Product";
                const imageUrl = shelfImage(product);
                const price = formatPrice(product?.price || product?.priceText || product?.displayPrice);
                return `
                    <div class="app-phone-product-card">
                        ${renderImage(imageUrl, title)}
                        <div class="app-phone-card-title">${escapeHtml(title)}</div>
                        <div class="app-phone-price">${escapeHtml(price)}</div>
                    </div>
                `;
            }

            function renderPreviews() {
                const draft = normalizeContent({ ...state.draft, ...collectPayload() });
                if (!phonePreview) {
                    return;
                }

                const slides = currentSlides(draft);
                const heroSlide = slides[0] || {
                    title: draft.mobile_home_title,
                    subtitle: draft.mobile_home_subtitle,
                    imageUrl: state.defaults.mobile_slide_1_image_url,
                    cta: draft.mobile_slide_1_cta_label || "Open",
                };
                const collections = Array.isArray(state.liveHome?.featuredCollections) && state.liveHome.featuredCollections.length > 0
                    ? state.liveHome.featuredCollections.slice(0, 4)
                    : fallbackCollections();
                const products = Array.isArray(state.liveHome?.featuredProducts) && state.liveHome.featuredProducts.length > 0
                    ? state.liveHome.featuredProducts.slice(0, 4)
                    : fallbackProducts();

                phonePreview.innerHTML = `
                    <div class="app-phone-brand">
                        <strong>${escapeHtml(draft.brand_name || "Modern Forestry")}</strong>
                        <span>Soy candles</span>
                    </div>
                    <div class="app-phone-hero">
                        ${renderImage(heroSlide.imageUrl, heroSlide.title)}
                        <div class="app-phone-hero-copy">
                            <strong>${escapeHtml(heroSlide.title || draft.mobile_home_title || "Mobile Home")}</strong>
                            ${heroSlide.subtitle ? `<p>${escapeHtml(heroSlide.subtitle)}</p>` : ""}
                            ${heroSlide.cta ? `<div class="app-phone-hero-cta">${escapeHtml(heroSlide.cta)} &rarr;</div>` : ""}
                        </div>
                        <div class="app-phone-dots">
                            ${slides.slice(0, 3).map(() => "<span></span>").join("") || "<span></span>"}
                        </div>
                    </div>
                    <div class="app-phone-section">
                        <h3>Browse Collections</h3>
                        <div class="app-phone-rail">
                            ${collections.map(renderCollectionCard).join("")}
                        </div>
                    </div>
                    <div class="app-phone-section">
                        <h3>Featured Products</h3>
                        <div class="app-phone-products-grid">
                            ${products.map(renderProductCard).join("")}
                        </div>
                    </div>
                `;
            }

            async function loadPreviewHomeData() {
                try {
                    const response = await fetch(mobileHomeEndpoint, {
                        headers: { "Accept": "application/json" },
                        credentials: "same-origin",
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload || typeof payload !== "object") {
                        throw new Error(payload?.message || "Mobile preview data is unavailable.");
                    }
                    state.liveHome = payload;
                    if (previewWarning) {
                        previewWarning.hidden = true;
                        previewWarning.textContent = "";
                    }
                    renderPreviews();
                } catch (error) {
                    if (previewWarning) {
                        previewWarning.hidden = false;
                        previewWarning.textContent = error?.message || "Live product and collection data could not be loaded. Showing preview placeholders.";
                    }
                }
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
            loadPreviewHomeData();
        })();
    </script>
</x-shopify-embedded-shell>
