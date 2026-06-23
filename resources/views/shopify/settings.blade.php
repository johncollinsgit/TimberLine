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
        /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrlGenerator */
        $embeddedUrlGenerator = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
        $developmentNotesHref = $embeddedUrlGenerator->append(
            route('shopify.app.development-notes', [], false),
            $embeddedUrlGenerator->contextQuery(request(), filled($host) ? (string) $host : null)
        );
        $editAppHref = $embeddedUrlGenerator->append(
            route('shopify.app.edit', [], false),
            $embeddedUrlGenerator->contextQuery(request(), filled($host) ? (string) $host : null)
        );
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

        .content-editor {
            display: grid;
            gap: 16px;
        }

        .content-preview-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .content-preview-card {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.96), rgba(255, 255, 255, 0.98));
            padding: 14px;
        }

        .content-preview-card h3 {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
        }

        .content-preview-card p {
            margin-top: 8px;
            color: rgba(15, 23, 42, 0.68);
            line-height: 1.55;
            font-size: 13px;
        }

        .content-preview-hero {
            margin-top: 12px;
            border-radius: 14px;
            padding: 14px;
            background: radial-gradient(circle at top right, rgba(15, 143, 97, 0.12), transparent 45%), rgba(15, 23, 42, 0.03);
            border: 1px solid rgba(15, 23, 42, 0.06);
        }

        .content-preview-eyebrow {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.5);
        }

        .content-preview-title {
            margin-top: 6px;
            font-size: 1.25rem;
            font-weight: 750;
            line-height: 1.2;
            color: #0f172a;
        }

        .content-preview-body {
            margin-top: 8px;
            font-size: 13px;
            line-height: 1.55;
            color: rgba(15, 23, 42, 0.74);
        }

        .content-preview-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .content-preview-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            border-radius: 999px;
            padding: 0 12px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #ffffff;
            color: #0f172a;
        }

        .content-preview-section {
            margin-top: 12px;
            display: grid;
            gap: 8px;
        }

        .content-preview-section strong {
            font-size: 13px;
            color: #0f172a;
        }

        .content-preview-empty {
            font-size: 13px;
            color: rgba(15, 23, 42, 0.58);
        }

        .content-preview-order {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.88);
            padding: 10px 12px;
        }

        .content-preview-order-title {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }

        .content-preview-order-meta {
            margin-top: 4px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.62);
            line-height: 1.5;
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
                description="Settings visibility and readiness now come from workspace access and setup state."
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

        @if(is_array($appContentBootstrap ?? null) && (bool) ($appContentBootstrap['authorized'] ?? false))
            <article class="settings-card" id="app-content-settings-link-card">
                <div class="settings-head">
                    <div>
                        <h2>Edit App</h2>
                        <p>
                            App homepage, customer dashboard, and mobile hero copy now live on a dedicated editor page.
                        </p>
                    </div>
                    <div class="settings-badges">
                        <span class="settings-badge settings-badge--configured">App content</span>
                    </div>
                </div>
                <div class="settings-actions">
                    <a class="settings-button settings-button--primary" href="{{ $editAppHref }}">Open Edit App</a>
                </div>
            </article>
        @endif

        @if(false && is_array($appContentBootstrap ?? null) && (bool) ($appContentBootstrap['authorized'] ?? false))
            <article class="settings-card" id="app-content-card">
                @php
                    $publishedContent = is_array(data_get($appContentBootstrap, 'settings.published'))
                        ? data_get($appContentBootstrap, 'settings.published')
                        : data_get($appContentBootstrap, 'defaults', []);
                    $draftContent = is_array(data_get($appContentBootstrap, 'settings.draft'))
                        ? data_get($appContentBootstrap, 'settings.draft')
                        : data_get($appContentBootstrap, 'defaults', []);
                @endphp
                <div class="settings-head">
                    <div>
                        <h2>App Content</h2>
                        <p>
                            Update the customer dashboard copy for Modern Forestry. Draft changes stay private until you publish them.
                        </p>
                    </div>
                    <div class="settings-badges" id="app-content-status">
                        <span class="settings-badge">Draft ready</span>
                        <span class="settings-badge settings-badge--configured">Published live</span>
                    </div>
                </div>

                <div class="settings-inline-status" id="app-content-alert" hidden></div>

                <div class="content-editor">
                    <form id="app-content-form">
                        <div class="settings-grid">
                            <div class="settings-field">
                                <label for="content-brand-name">Brand Name</label>
                                <input id="content-brand-name" name="brand_name" type="text" maxlength="120" value="{{ data_get($draftContent, 'brand_name', 'Modern Forestry') }}">
                                <div class="settings-field-error" data-error-for="brand_name"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-hero-eyebrow">Hero Eyebrow</label>
                                <input id="content-hero-eyebrow" name="hero_eyebrow" type="text" maxlength="120" value="{{ data_get($draftContent, 'hero_eyebrow', 'Customer account') }}">
                                <div class="settings-field-error" data-error-for="hero_eyebrow"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-hero-title">Hero Title</label>
                                <input id="content-hero-title" name="hero_title" type="text" maxlength="160" value="{{ data_get($draftContent, 'hero_title', 'Your Modern Forestry account') }}">
                                <div class="settings-field-error" data-error-for="hero_title"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-hero-body">Hero Body</label>
                                <textarea id="content-hero-body" name="hero_body" maxlength="240">{{ data_get($draftContent, 'hero_body', 'Check rewards, recent orders, and quick actions in one place.') }}</textarea>
                                <div class="settings-field-error" data-error-for="hero_body"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-primary-cta">Primary CTA</label>
                                <input id="content-primary-cta" name="primary_cta_label" type="text" maxlength="80" value="{{ data_get($draftContent, 'primary_cta_label', 'View rewards') }}">
                                <div class="settings-field-error" data-error-for="primary_cta_label"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-secondary-cta">Secondary CTA</label>
                                <input id="content-secondary-cta" name="secondary_cta_label" type="text" maxlength="80" value="{{ data_get($draftContent, 'secondary_cta_label', 'Review orders') }}">
                                <div class="settings-field-error" data-error-for="secondary_cta_label"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-rewards-title">Rewards Title</label>
                                <input id="content-rewards-title" name="rewards_title" type="text" maxlength="120" value="{{ data_get($draftContent, 'rewards_title', 'Rewards') }}">
                                <div class="settings-field-error" data-error-for="rewards_title"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-rewards-body">Rewards Body</label>
                                <textarea id="content-rewards-body" name="rewards_body" maxlength="240">{{ data_get($draftContent, 'rewards_body', 'Redeem on Shopify checkout when you are ready.') }}</textarea>
                                <div class="settings-field-error" data-error-for="rewards_body"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-orders-title">Orders Title</label>
                                <input id="content-orders-title" name="orders_title" type="text" maxlength="120" value="{{ data_get($draftContent, 'orders_title', 'Recent orders') }}">
                                <div class="settings-field-error" data-error-for="orders_title"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-orders-body">Orders Body</label>
                                <textarea id="content-orders-body" name="orders_body" maxlength="240">{{ data_get($draftContent, 'orders_body', 'Reorder the items you want again with a Shopify cart handoff.') }}</textarea>
                                <div class="settings-field-error" data-error-for="orders_body"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-support-title">Support Title</label>
                                <input id="content-support-title" name="support_title" type="text" maxlength="120" value="{{ data_get($draftContent, 'support_title', 'Support') }}">
                                <div class="settings-field-error" data-error-for="support_title"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-support-body">Support Body</label>
                                <textarea id="content-support-body" name="support_body" maxlength="240">{{ data_get($draftContent, 'support_body', 'Need help? Reach out and we will follow up.') }}</textarea>
                                <div class="settings-field-error" data-error-for="support_body"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-support-cta">Support CTA</label>
                                <input id="content-support-cta" name="support_cta_label" type="text" maxlength="80" value="{{ data_get($draftContent, 'support_cta_label', 'Contact support') }}">
                                <div class="settings-field-error" data-error-for="support_cta_label"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-support-email">Support Email</label>
                                <input id="content-support-email" name="support_email" type="email" maxlength="255" value="{{ data_get($draftContent, 'support_email', 'support@modernforestry.com') }}">
                                <div class="settings-field-error" data-error-for="support_email"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-support-url">Support URL</label>
                                <input id="content-support-url" name="support_url" type="url" maxlength="500" value="{{ data_get($draftContent, 'support_url', '') }}">
                                <div class="settings-field-error" data-error-for="support_url"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-privacy-url">Privacy URL</label>
                                <input id="content-privacy-url" name="privacy_url" type="url" maxlength="500" value="{{ data_get($draftContent, 'privacy_url', 'https://modernforestry.com/policies/privacy-policy') }}">
                                <div class="settings-field-error" data-error-for="privacy_url"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-terms-url">Terms URL</label>
                                <input id="content-terms-url" name="terms_url" type="url" maxlength="500" value="{{ data_get($draftContent, 'terms_url', 'https://modernforestry.com/policies/terms-of-service') }}">
                                <div class="settings-field-error" data-error-for="terms_url"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-data-deletion-url">Data Request URL</label>
                                <input id="content-data-deletion-url" name="data_deletion_url" type="url" maxlength="500" value="{{ data_get($draftContent, 'data_deletion_url', '') }}">
                                <div class="settings-field-error" data-error-for="data_deletion_url"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-data-deletion-email">Data Request Email</label>
                                <input id="content-data-deletion-email" name="data_deletion_email" type="email" maxlength="255" value="{{ data_get($draftContent, 'data_deletion_email', 'support@modernforestry.com') }}">
                                <div class="settings-field-error" data-error-for="data_deletion_email"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-empty-rewards">Empty Rewards Copy</label>
                                <input id="content-empty-rewards" name="empty_rewards" type="text" maxlength="240" value="{{ data_get($draftContent, 'empty_rewards', 'No active rewards right now.') }}">
                                <div class="settings-field-error" data-error-for="empty_rewards"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-empty-orders">Empty Orders Copy</label>
                                <input id="content-empty-orders" name="empty_orders" type="text" maxlength="240" value="{{ data_get($draftContent, 'empty_orders', 'No recent orders yet.') }}">
                                <div class="settings-field-error" data-error-for="empty_orders"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-account-note">Account Note</label>
                                <textarea id="content-account-note" name="account_note" maxlength="240">{{ data_get($draftContent, 'account_note', 'For privacy or account data requests, contact Modern Forestry support.') }}</textarea>
                                <div class="settings-field-error" data-error-for="account_note"></div>
                            </div>
                            <div class="settings-field" style="grid-column: 1 / -1;">
                                <h3 style="margin: 0 0 6px;">Mobile Home</h3>
                                <small>These published fields feed the native iPhone Home screen without an app rebuild. Paste Shopify CDN image URLs or storefront URLs.</small>
                            </div>
                            <div class="settings-field">
                                <label for="content-mobile-home-eyebrow">Mobile Hero Eyebrow</label>
                                <input id="content-mobile-home-eyebrow" name="mobile_home_eyebrow" type="text" maxlength="120" value="{{ data_get($draftContent, 'mobile_home_eyebrow', data_get($appContentBootstrap, 'defaults.mobile_home_eyebrow', 'Modern Forestry')) }}">
                                <div class="settings-field-error" data-error-for="mobile_home_eyebrow"></div>
                            </div>
                            <div class="settings-field">
                                <label for="content-mobile-home-title">Mobile Hero Title</label>
                                <input id="content-mobile-home-title" name="mobile_home_title" type="text" maxlength="160" value="{{ data_get($draftContent, 'mobile_home_title', data_get($appContentBootstrap, 'defaults.mobile_home_title', 'Hand-poured candles for a slower season.')) }}">
                                <div class="settings-field-error" data-error-for="mobile_home_title"></div>
                            </div>
                            <div class="settings-field" style="grid-column: 1 / -1;">
                                <label for="content-mobile-home-subtitle">Mobile Hero Subtitle</label>
                                <textarea id="content-mobile-home-subtitle" name="mobile_home_subtitle" maxlength="240">{{ data_get($draftContent, 'mobile_home_subtitle', data_get($appContentBootstrap, 'defaults.mobile_home_subtitle', 'Small-batch scents, seasonal favorites, and Candle Cash rewards.')) }}</textarea>
                                <div class="settings-field-error" data-error-for="mobile_home_subtitle"></div>
                            </div>
                            @for($slideIndex = 1; $slideIndex <= 3; $slideIndex++)
                                <div class="settings-field" style="grid-column: 1 / -1;">
                                    <h3 style="margin: 0 0 6px;">Mobile Slide {{ $slideIndex }}</h3>
                                </div>
                                <div class="settings-field">
                                    <label for="content-mobile-slide-{{ $slideIndex }}-title">Slide {{ $slideIndex }} Title</label>
                                    <input id="content-mobile-slide-{{ $slideIndex }}-title" name="mobile_slide_{{ $slideIndex }}_title" type="text" maxlength="160" value="{{ data_get($draftContent, 'mobile_slide_'.$slideIndex.'_title', data_get($appContentBootstrap, 'defaults.mobile_slide_'.$slideIndex.'_title')) }}">
                                    <div class="settings-field-error" data-error-for="mobile_slide_{{ $slideIndex }}_title"></div>
                                </div>
                                <div class="settings-field">
                                    <label for="content-mobile-slide-{{ $slideIndex }}-subtitle">Slide {{ $slideIndex }} Subtitle</label>
                                    <input id="content-mobile-slide-{{ $slideIndex }}-subtitle" name="mobile_slide_{{ $slideIndex }}_subtitle" type="text" maxlength="240" value="{{ data_get($draftContent, 'mobile_slide_'.$slideIndex.'_subtitle', data_get($appContentBootstrap, 'defaults.mobile_slide_'.$slideIndex.'_subtitle')) }}">
                                    <div class="settings-field-error" data-error-for="mobile_slide_{{ $slideIndex }}_subtitle"></div>
                                </div>
                                <div class="settings-field" style="grid-column: 1 / -1;">
                                    <label for="content-mobile-slide-{{ $slideIndex }}-image-url">Slide {{ $slideIndex }} Image URL</label>
                                    <input id="content-mobile-slide-{{ $slideIndex }}-image-url" name="mobile_slide_{{ $slideIndex }}_image_url" type="url" maxlength="500" value="{{ data_get($draftContent, 'mobile_slide_'.$slideIndex.'_image_url', data_get($appContentBootstrap, 'defaults.mobile_slide_'.$slideIndex.'_image_url')) }}">
                                    <div class="settings-field-error" data-error-for="mobile_slide_{{ $slideIndex }}_image_url"></div>
                                </div>
                                <div class="settings-field" style="grid-column: 1 / -1;">
                                    <label for="content-mobile-slide-{{ $slideIndex }}-mobile-image-url">Slide {{ $slideIndex }} Phone Crop URL</label>
                                    <input id="content-mobile-slide-{{ $slideIndex }}-mobile-image-url" name="mobile_slide_{{ $slideIndex }}_mobile_image_url" type="url" maxlength="500" value="{{ data_get($draftContent, 'mobile_slide_'.$slideIndex.'_mobile_image_url', data_get($appContentBootstrap, 'defaults.mobile_slide_'.$slideIndex.'_mobile_image_url')) }}">
                                    <small>Optional. Leave blank to reuse the image URL.</small>
                                    <div class="settings-field-error" data-error-for="mobile_slide_{{ $slideIndex }}_mobile_image_url"></div>
                                </div>
                                <div class="settings-field">
                                    <label for="content-mobile-slide-{{ $slideIndex }}-cta-label">Slide {{ $slideIndex }} Button Label</label>
                                    <input id="content-mobile-slide-{{ $slideIndex }}-cta-label" name="mobile_slide_{{ $slideIndex }}_cta_label" type="text" maxlength="80" value="{{ data_get($draftContent, 'mobile_slide_'.$slideIndex.'_cta_label', data_get($appContentBootstrap, 'defaults.mobile_slide_'.$slideIndex.'_cta_label')) }}">
                                    <div class="settings-field-error" data-error-for="mobile_slide_{{ $slideIndex }}_cta_label"></div>
                                </div>
                                <div class="settings-field">
                                    <label for="content-mobile-slide-{{ $slideIndex }}-cta-url">Slide {{ $slideIndex }} Button URL</label>
                                    <input id="content-mobile-slide-{{ $slideIndex }}-cta-url" name="mobile_slide_{{ $slideIndex }}_cta_url" type="url" maxlength="500" value="{{ data_get($draftContent, 'mobile_slide_'.$slideIndex.'_cta_url', data_get($appContentBootstrap, 'defaults.mobile_slide_'.$slideIndex.'_cta_url')) }}">
                                    <div class="settings-field-error" data-error-for="mobile_slide_{{ $slideIndex }}_cta_url"></div>
                                </div>
                            @endfor
                        </div>
                    </form>

                    <div class="content-preview-grid">
                        <article class="content-preview-card">
                            <h3>Draft Preview</h3>
                            <p>What merchants are editing right now.</p>
                            <div class="content-preview-slot" id="content-preview-draft"></div>
                        </article>
                        <article class="content-preview-card">
                            <h3>Live Preview</h3>
                            <p>What customers see after publish.</p>
                            <div class="content-preview-slot" id="content-preview-live"></div>
                        </article>
                    </div>

                    <div class="settings-actions">
                        <button class="settings-button settings-button--primary" type="button" id="app-content-save">Save Draft</button>
                        <button class="settings-button" type="button" id="app-content-publish">Publish Live</button>
                    </div>
                </div>
            </article>
        @endif

        <article class="settings-card" id="development-notes-nav-card" hidden>
            <h2>Development Notes</h2>
            <p>
                Internal implementation notes and change log workspace. This entry point appears only for allowlisted admin identities.
            </p>
            <div class="settings-actions">
                <a class="settings-button settings-button--primary" href="{{ $developmentNotesHref }}">Open Development Notes</a>
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
                        <span>Keep delivery details and provider hooks ready for campaign reporting and troubleshooting.</span>
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
            const appContentBootstrap = @json($appContentBootstrap ?? []);
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
            const appContentCard = document.getElementById("app-content-card");
            const appContentForm = document.getElementById("app-content-form");
            const appContentAlert = document.getElementById("app-content-alert");
            const appContentStatus = document.getElementById("app-content-status");
            const appContentSaveButton = document.getElementById("app-content-save");
            const appContentPublishButton = document.getElementById("app-content-publish");
            const contentPreviewDraft = document.getElementById("content-preview-draft");
            const contentPreviewLive = document.getElementById("content-preview-live");
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
            const developmentNotesNavCard = document.getElementById("development-notes-nav-card");
            const developmentNotesAccessEndpoint = @json(route('shopify.app.api.development-notes.access', [], false));

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

            const contentState = {
                loading: false,
                saving: false,
                publishing: false,
                defaults: normalizeContent(appContentBootstrap?.defaults || null),
                draft: normalizeContent(appContentBootstrap?.settings?.draft || appContentBootstrap?.settings?.effective || appContentBootstrap?.defaults || null),
                published: appContentBootstrap?.settings?.published ? normalizeContent(appContentBootstrap?.settings?.published) : null,
                effective: normalizeContent(appContentBootstrap?.settings?.effective || appContentBootstrap?.defaults || null),
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
                [saveButton, validateButton, healthButton, testButton, clearKeyButton, appContentSaveButton, appContentPublishButton].forEach((button) => {
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

                if (appContentForm) {
                    Array.from(appContentForm.elements || []).forEach((element) => {
                        if (element instanceof HTMLElement) {
                            element.toggleAttribute("disabled", state.busy || state.loading);
                        }
                    });
                }
            }

            function lockUi(message) {
                setAlert(globalAlert, message, "error");
                Array.from(form.elements || []).forEach((element) => {
                    if (element instanceof HTMLElement) {
                        element.setAttribute("disabled", "disabled");
                    }
                });
                [saveButton, validateButton, healthButton, testButton, clearKeyButton, appContentSaveButton, appContentPublishButton].forEach((button) => {
                    if (button) {
                        button.setAttribute("disabled", "disabled");
                    }
                });
                if (appContentForm) {
                    Array.from(appContentForm.elements || []).forEach((element) => {
                        if (element instanceof HTMLElement) {
                            element.setAttribute("disabled", "disabled");
                        }
                    });
                }
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
                        Custom Provider is scaffolded for future implementation. You can save endpoint/auth details now,
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

            function normalizeContent(input) {
                const defaults = appContentBootstrap?.defaults || {
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
                    support_url: null,
                    privacy_url: "https://modernforestry.com/policies/privacy-policy",
                    terms_url: "https://modernforestry.com/policies/terms-of-service",
                    data_deletion_url: null,
                    data_deletion_email: "support@modernforestry.com",
                    empty_rewards: "No active rewards right now.",
                    empty_orders: "No recent orders yet.",
                    account_note: "For privacy or account data requests, contact Modern Forestry support.",
                    mobile_home_eyebrow: "Modern Forestry",
                    mobile_home_title: "Hand-poured candles for a slower season.",
                    mobile_home_subtitle: "Small-batch scents, seasonal favorites, and Candle Cash rewards.",
                    mobile_slide_1_title: "Shop our Spring Collection",
                    mobile_slide_1_subtitle: null,
                    mobile_slide_1_image_url: "https://theforestrystudio.com/cdn/shop/files/bright-fuschia-spring-blossoms_638cad68-df20-4a7b-b482-68abb3beb3bf_1000x.jpg?v=1772645457",
                    mobile_slide_1_mobile_image_url: null,
                    mobile_slide_1_cta_label: "Click to Shop",
                    mobile_slide_1_cta_url: "https://theforestrystudio.com/collections/spring-collection",
                    mobile_slide_2_title: "Classic scents, always ready",
                    mobile_slide_2_subtitle: "Keep your favorites close.",
                    mobile_slide_2_image_url: "https://theforestrystudio.com/cdn/shop/files/magnolia-bloom-opening_1000x.jpg?v=1772646113",
                    mobile_slide_2_mobile_image_url: null,
                    mobile_slide_2_cta_label: "Shop Classic",
                    mobile_slide_2_cta_url: "https://theforestrystudio.com/collections/classic-collection-1",
                    mobile_slide_3_title: "Earn Candle Cash",
                    mobile_slide_3_subtitle: "Shop, review, and redeem rewards.",
                    mobile_slide_3_image_url: "https://theforestrystudio.com/cdn/shop/files/easter-mini-eggs_1000x.jpg?v=1772646038",
                    mobile_slide_3_mobile_image_url: null,
                    mobile_slide_3_cta_label: "View Rewards",
                    mobile_slide_3_cta_url: "https://theforestrystudio.com/pages/rewards",
                };

                const source = input && typeof input === "object" ? input : {};

                return {
                    brand_name: normalizeString(source.brand_name) || defaults.brand_name,
                    hero_eyebrow: normalizeString(source.hero_eyebrow) || defaults.hero_eyebrow,
                    hero_title: normalizeString(source.hero_title) || defaults.hero_title,
                    hero_body: normalizeString(source.hero_body) || defaults.hero_body,
                    primary_cta_label: normalizeString(source.primary_cta_label) || defaults.primary_cta_label,
                    secondary_cta_label: normalizeString(source.secondary_cta_label) || defaults.secondary_cta_label,
                    rewards_title: normalizeString(source.rewards_title) || defaults.rewards_title,
                    rewards_body: normalizeString(source.rewards_body) || defaults.rewards_body,
                    orders_title: normalizeString(source.orders_title) || defaults.orders_title,
                    orders_body: normalizeString(source.orders_body) || defaults.orders_body,
                    support_title: normalizeString(source.support_title) || defaults.support_title,
                    support_body: normalizeString(source.support_body) || defaults.support_body,
                    support_cta_label: normalizeString(source.support_cta_label) || defaults.support_cta_label,
                    support_email: normalizeString(source.support_email) || defaults.support_email,
                    support_url: normalizeString(source.support_url) || defaults.support_url,
                    privacy_url: normalizeString(source.privacy_url) || defaults.privacy_url,
                    terms_url: normalizeString(source.terms_url) || defaults.terms_url,
                    data_deletion_url: normalizeString(source.data_deletion_url) || defaults.data_deletion_url,
                    data_deletion_email: normalizeString(source.data_deletion_email) || defaults.data_deletion_email,
                    empty_rewards: normalizeString(source.empty_rewards) || defaults.empty_rewards,
                    empty_orders: normalizeString(source.empty_orders) || defaults.empty_orders,
                    account_note: normalizeString(source.account_note) || defaults.account_note,
                    mobile_home_eyebrow: normalizeString(source.mobile_home_eyebrow) || defaults.mobile_home_eyebrow,
                    mobile_home_title: normalizeString(source.mobile_home_title) || defaults.mobile_home_title,
                    mobile_home_subtitle: normalizeString(source.mobile_home_subtitle) || defaults.mobile_home_subtitle,
                    mobile_slide_1_title: normalizeString(source.mobile_slide_1_title) || defaults.mobile_slide_1_title,
                    mobile_slide_1_subtitle: normalizeString(source.mobile_slide_1_subtitle) || defaults.mobile_slide_1_subtitle,
                    mobile_slide_1_image_url: normalizeString(source.mobile_slide_1_image_url) || defaults.mobile_slide_1_image_url,
                    mobile_slide_1_mobile_image_url: normalizeString(source.mobile_slide_1_mobile_image_url) || defaults.mobile_slide_1_mobile_image_url,
                    mobile_slide_1_cta_label: normalizeString(source.mobile_slide_1_cta_label) || defaults.mobile_slide_1_cta_label,
                    mobile_slide_1_cta_url: normalizeString(source.mobile_slide_1_cta_url) || defaults.mobile_slide_1_cta_url,
                    mobile_slide_2_title: normalizeString(source.mobile_slide_2_title) || defaults.mobile_slide_2_title,
                    mobile_slide_2_subtitle: normalizeString(source.mobile_slide_2_subtitle) || defaults.mobile_slide_2_subtitle,
                    mobile_slide_2_image_url: normalizeString(source.mobile_slide_2_image_url) || defaults.mobile_slide_2_image_url,
                    mobile_slide_2_mobile_image_url: normalizeString(source.mobile_slide_2_mobile_image_url) || defaults.mobile_slide_2_mobile_image_url,
                    mobile_slide_2_cta_label: normalizeString(source.mobile_slide_2_cta_label) || defaults.mobile_slide_2_cta_label,
                    mobile_slide_2_cta_url: normalizeString(source.mobile_slide_2_cta_url) || defaults.mobile_slide_2_cta_url,
                    mobile_slide_3_title: normalizeString(source.mobile_slide_3_title) || defaults.mobile_slide_3_title,
                    mobile_slide_3_subtitle: normalizeString(source.mobile_slide_3_subtitle) || defaults.mobile_slide_3_subtitle,
                    mobile_slide_3_image_url: normalizeString(source.mobile_slide_3_image_url) || defaults.mobile_slide_3_image_url,
                    mobile_slide_3_mobile_image_url: normalizeString(source.mobile_slide_3_mobile_image_url) || defaults.mobile_slide_3_mobile_image_url,
                    mobile_slide_3_cta_label: normalizeString(source.mobile_slide_3_cta_label) || defaults.mobile_slide_3_cta_label,
                    mobile_slide_3_cta_url: normalizeString(source.mobile_slide_3_cta_url) || defaults.mobile_slide_3_cta_url,
                };
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

            function contentStatusLabel() {
                if (!appContentBootstrap?.authorized) {
                    return "Unavailable";
                }

                return contentState.published ? "Live published" : "Draft only";
            }

            function contentStatusTone() {
                if (!appContentBootstrap?.authorized) {
                    return "warn";
                }

                return contentState.published ? "configured" : "warn";
            }

            function contentSnapshotCard(snapshot, badgeLabel) {
                const heroTitle = escapeHtml(snapshot.hero_title || "");
                const heroBody = escapeHtml(snapshot.hero_body || "");
                const eyebrow = escapeHtml(snapshot.hero_eyebrow || "");
                const rewardsTitle = escapeHtml(snapshot.rewards_title || "");
                const rewardsBody = escapeHtml(snapshot.rewards_body || "");
                const ordersTitle = escapeHtml(snapshot.orders_title || "");
                const ordersBody = escapeHtml(snapshot.orders_body || "");
                const supportTitle = escapeHtml(snapshot.support_title || "");
                const supportBody = escapeHtml(snapshot.support_body || "");
                const supportCta = escapeHtml(snapshot.support_cta_label || "");
                const supportHref = snapshot.support_url || snapshot.support_email
                    ? escapeHtml(snapshot.support_url || `mailto:${snapshot.support_email}`)
                    : "";
                const privacyHref = escapeHtml(snapshot.privacy_url || "");
                const termsHref = escapeHtml(snapshot.terms_url || "");
                const dataRequestHref = snapshot.data_deletion_url || snapshot.data_deletion_email
                    ? escapeHtml(snapshot.data_deletion_url || `mailto:${snapshot.data_deletion_email}`)
                    : "";
                const brandName = escapeHtml(snapshot.brand_name || "Modern Forestry");
                const note = escapeHtml(snapshot.account_note || "");
                const mobileTitle = escapeHtml(snapshot.mobile_home_title || "");
                const mobileSubtitle = escapeHtml(snapshot.mobile_home_subtitle || "");
                const mobileSlideTitle = escapeHtml(snapshot.mobile_slide_1_title || "");
                const mobileSlideCta = escapeHtml(snapshot.mobile_slide_1_cta_label || "");

                return `
                    <div class="content-preview-hero">
                        <div class="content-preview-eyebrow">${eyebrow}</div>
                        <div class="content-preview-title">${heroTitle}</div>
                        <div class="content-preview-body">${heroBody}</div>
                        <div class="content-preview-actions">
                            <span class="content-preview-action">${escapeHtml(snapshot.primary_cta_label || "View rewards")}</span>
                            <span class="content-preview-action">${escapeHtml(snapshot.secondary_cta_label || "Review orders")}</span>
                        </div>
                    </div>
                    <div class="content-preview-section">
                        <strong>${rewardsTitle}</strong>
                        <div class="content-preview-empty">${rewardsBody}</div>
                    </div>
                    <div class="content-preview-section">
                        <strong>${ordersTitle}</strong>
                        <div class="content-preview-empty">${ordersBody}</div>
                    </div>
                    <div class="content-preview-section">
                        <strong>${supportTitle}</strong>
                        <div class="content-preview-empty">${supportBody}</div>
                        <div class="content-preview-actions">
                            <a class="content-preview-action" href="${supportHref || '#'}">${supportCta}</a>
                            ${privacyHref ? `<a class="content-preview-action" href="${privacyHref}">Privacy</a>` : ""}
                            ${termsHref ? `<a class="content-preview-action" href="${termsHref}">Terms</a>` : ""}
                            ${dataRequestHref ? `<a class="content-preview-action" href="${dataRequestHref}">Data requests</a>` : ""}
                        </div>
                    </div>
                    <div class="content-preview-section">
                        <strong>Native mobile Home</strong>
                        <div class="content-preview-empty">${mobileTitle}</div>
                        <div class="content-preview-empty">${mobileSubtitle}</div>
                        <div class="content-preview-actions">
                            <span class="content-preview-action">${mobileSlideTitle}</span>
                            <span class="content-preview-action">${mobileSlideCta}</span>
                        </div>
                    </div>
                    <div class="content-preview-empty" style="margin-top: 12px;">${brandName} · ${note} · ${badgeLabel}</div>
                `;
            }

            function renderContentPreview() {
                if (!contentPreviewDraft || !contentPreviewLive) {
                    return;
                }

                const draftSnapshot = normalizeContent(collectContentPayload());
                const liveSnapshot = contentState.published || contentState.defaults;
                contentPreviewDraft.innerHTML = contentSnapshotCard(draftSnapshot, "Draft");
                contentPreviewLive.innerHTML = contentSnapshotCard(liveSnapshot, contentState.published ? "Published" : "Defaults");

                if (appContentStatus) {
                    appContentStatus.innerHTML = `
                        <span class="settings-badge">${escapeHtml(contentStatusLabel())}</span>
                        <span class="settings-badge ${contentState.published ? "settings-badge--configured" : "settings-badge--warn"}">${contentState.published ? "Published" : "No live publish yet"}</span>
                    `;
                }
            }

            function populateContentForm() {
                if (!appContentForm) {
                    return;
                }

                const snapshot = contentState.draft || contentState.defaults;
                setContentField("content-brand-name", snapshot.brand_name);
                setContentField("content-hero-eyebrow", snapshot.hero_eyebrow);
                setContentField("content-hero-title", snapshot.hero_title);
                setContentField("content-hero-body", snapshot.hero_body);
                setContentField("content-primary-cta", snapshot.primary_cta_label);
                setContentField("content-secondary-cta", snapshot.secondary_cta_label);
                setContentField("content-rewards-title", snapshot.rewards_title);
                setContentField("content-rewards-body", snapshot.rewards_body);
                setContentField("content-orders-title", snapshot.orders_title);
                setContentField("content-orders-body", snapshot.orders_body);
                setContentField("content-support-title", snapshot.support_title);
                setContentField("content-support-body", snapshot.support_body);
                setContentField("content-support-cta", snapshot.support_cta_label);
                setContentField("content-support-email", snapshot.support_email);
                setContentField("content-support-url", snapshot.support_url);
                setContentField("content-privacy-url", snapshot.privacy_url);
                setContentField("content-terms-url", snapshot.terms_url);
                setContentField("content-data-deletion-url", snapshot.data_deletion_url);
                setContentField("content-data-deletion-email", snapshot.data_deletion_email);
                setContentField("content-empty-rewards", snapshot.empty_rewards);
                setContentField("content-empty-orders", snapshot.empty_orders);
                setContentField("content-account-note", snapshot.account_note);
                setContentField("content-mobile-home-eyebrow", snapshot.mobile_home_eyebrow);
                setContentField("content-mobile-home-title", snapshot.mobile_home_title);
                setContentField("content-mobile-home-subtitle", snapshot.mobile_home_subtitle);
                for (let index = 1; index <= 3; index += 1) {
                    setContentField(`content-mobile-slide-${index}-title`, snapshot[`mobile_slide_${index}_title`]);
                    setContentField(`content-mobile-slide-${index}-subtitle`, snapshot[`mobile_slide_${index}_subtitle`]);
                    setContentField(`content-mobile-slide-${index}-image-url`, snapshot[`mobile_slide_${index}_image_url`]);
                    setContentField(`content-mobile-slide-${index}-mobile-image-url`, snapshot[`mobile_slide_${index}_mobile_image_url`]);
                    setContentField(`content-mobile-slide-${index}-cta-label`, snapshot[`mobile_slide_${index}_cta_label`]);
                    setContentField(`content-mobile-slide-${index}-cta-url`, snapshot[`mobile_slide_${index}_cta_url`]);
                }
                renderContentPreview();
            }

            function setContentField(id, value) {
                const element = document.getElementById(id);
                if (!(element instanceof HTMLInputElement) && !(element instanceof HTMLTextAreaElement)) {
                    return;
                }

                element.value = value || "";
            }

            function collectContentPayload() {
                return {
                    brand_name: normalizeString(document.getElementById("content-brand-name")?.value) || contentState.defaults.brand_name,
                    hero_eyebrow: normalizeString(document.getElementById("content-hero-eyebrow")?.value) || contentState.defaults.hero_eyebrow,
                    hero_title: normalizeString(document.getElementById("content-hero-title")?.value) || contentState.defaults.hero_title,
                    hero_body: normalizeString(document.getElementById("content-hero-body")?.value) || contentState.defaults.hero_body,
                    primary_cta_label: normalizeString(document.getElementById("content-primary-cta")?.value) || contentState.defaults.primary_cta_label,
                    secondary_cta_label: normalizeString(document.getElementById("content-secondary-cta")?.value) || contentState.defaults.secondary_cta_label,
                    rewards_title: normalizeString(document.getElementById("content-rewards-title")?.value) || contentState.defaults.rewards_title,
                    rewards_body: normalizeString(document.getElementById("content-rewards-body")?.value) || contentState.defaults.rewards_body,
                    orders_title: normalizeString(document.getElementById("content-orders-title")?.value) || contentState.defaults.orders_title,
                    orders_body: normalizeString(document.getElementById("content-orders-body")?.value) || contentState.defaults.orders_body,
                    support_title: normalizeString(document.getElementById("content-support-title")?.value) || contentState.defaults.support_title,
                    support_body: normalizeString(document.getElementById("content-support-body")?.value) || contentState.defaults.support_body,
                    support_cta_label: normalizeString(document.getElementById("content-support-cta")?.value) || contentState.defaults.support_cta_label,
                    support_email: normalizeString(document.getElementById("content-support-email")?.value) || contentState.defaults.support_email,
                    support_url: normalizeString(document.getElementById("content-support-url")?.value) || contentState.defaults.support_url,
                    privacy_url: normalizeString(document.getElementById("content-privacy-url")?.value) || contentState.defaults.privacy_url,
                    terms_url: normalizeString(document.getElementById("content-terms-url")?.value) || contentState.defaults.terms_url,
                    data_deletion_url: normalizeString(document.getElementById("content-data-deletion-url")?.value) || contentState.defaults.data_deletion_url,
                    data_deletion_email: normalizeString(document.getElementById("content-data-deletion-email")?.value) || contentState.defaults.data_deletion_email,
                    empty_rewards: normalizeString(document.getElementById("content-empty-rewards")?.value) || contentState.defaults.empty_rewards,
                    empty_orders: normalizeString(document.getElementById("content-empty-orders")?.value) || contentState.defaults.empty_orders,
                    account_note: normalizeString(document.getElementById("content-account-note")?.value) || contentState.defaults.account_note,
                    mobile_home_eyebrow: normalizeString(document.getElementById("content-mobile-home-eyebrow")?.value) || contentState.defaults.mobile_home_eyebrow,
                    mobile_home_title: normalizeString(document.getElementById("content-mobile-home-title")?.value) || contentState.defaults.mobile_home_title,
                    mobile_home_subtitle: normalizeString(document.getElementById("content-mobile-home-subtitle")?.value) || contentState.defaults.mobile_home_subtitle,
                    mobile_slide_1_title: normalizeString(document.getElementById("content-mobile-slide-1-title")?.value) || contentState.defaults.mobile_slide_1_title,
                    mobile_slide_1_subtitle: normalizeString(document.getElementById("content-mobile-slide-1-subtitle")?.value) || contentState.defaults.mobile_slide_1_subtitle,
                    mobile_slide_1_image_url: normalizeString(document.getElementById("content-mobile-slide-1-image-url")?.value) || contentState.defaults.mobile_slide_1_image_url,
                    mobile_slide_1_mobile_image_url: normalizeString(document.getElementById("content-mobile-slide-1-mobile-image-url")?.value) || contentState.defaults.mobile_slide_1_mobile_image_url,
                    mobile_slide_1_cta_label: normalizeString(document.getElementById("content-mobile-slide-1-cta-label")?.value) || contentState.defaults.mobile_slide_1_cta_label,
                    mobile_slide_1_cta_url: normalizeString(document.getElementById("content-mobile-slide-1-cta-url")?.value) || contentState.defaults.mobile_slide_1_cta_url,
                    mobile_slide_2_title: normalizeString(document.getElementById("content-mobile-slide-2-title")?.value) || contentState.defaults.mobile_slide_2_title,
                    mobile_slide_2_subtitle: normalizeString(document.getElementById("content-mobile-slide-2-subtitle")?.value) || contentState.defaults.mobile_slide_2_subtitle,
                    mobile_slide_2_image_url: normalizeString(document.getElementById("content-mobile-slide-2-image-url")?.value) || contentState.defaults.mobile_slide_2_image_url,
                    mobile_slide_2_mobile_image_url: normalizeString(document.getElementById("content-mobile-slide-2-mobile-image-url")?.value) || contentState.defaults.mobile_slide_2_mobile_image_url,
                    mobile_slide_2_cta_label: normalizeString(document.getElementById("content-mobile-slide-2-cta-label")?.value) || contentState.defaults.mobile_slide_2_cta_label,
                    mobile_slide_2_cta_url: normalizeString(document.getElementById("content-mobile-slide-2-cta-url")?.value) || contentState.defaults.mobile_slide_2_cta_url,
                    mobile_slide_3_title: normalizeString(document.getElementById("content-mobile-slide-3-title")?.value) || contentState.defaults.mobile_slide_3_title,
                    mobile_slide_3_subtitle: normalizeString(document.getElementById("content-mobile-slide-3-subtitle")?.value) || contentState.defaults.mobile_slide_3_subtitle,
                    mobile_slide_3_image_url: normalizeString(document.getElementById("content-mobile-slide-3-image-url")?.value) || contentState.defaults.mobile_slide_3_image_url,
                    mobile_slide_3_mobile_image_url: normalizeString(document.getElementById("content-mobile-slide-3-mobile-image-url")?.value) || contentState.defaults.mobile_slide_3_mobile_image_url,
                    mobile_slide_3_cta_label: normalizeString(document.getElementById("content-mobile-slide-3-cta-label")?.value) || contentState.defaults.mobile_slide_3_cta_label,
                    mobile_slide_3_cta_url: normalizeString(document.getElementById("content-mobile-slide-3-cta-url")?.value) || contentState.defaults.mobile_slide_3_cta_url,
                };
            }

            function authFailureMessage(status, fallbackMessage) {
                const messages = {
                    missing_api_auth: "Shopify Admin verification is unavailable. Reload settings from Shopify Admin and try again.",
                    invalid_session_token: "Shopify Admin verification failed. Reload settings from Shopify Admin and try again.",
                    expired_session_token: "Your Shopify Admin session expired. Reload settings from Shopify Admin and try again.",
                };

                return messages[status] || fallbackMessage || null;
            }

            function scheduleIdleTask(callback) {
                if (typeof window === "undefined") {
                    return;
                }

                if (typeof window.requestIdleCallback === "function") {
                    window.requestIdleCallback(callback, { timeout: 800 });
                    return;
                }

                window.setTimeout(callback, 200);
            }

            async function resolveEmbeddedAuthHeaders() {
                const resolver = window.ForestryEmbeddedApp?.resolveEmbeddedAuthHeaders;
                if (typeof resolver !== "function") {
                    throw new Error(
                        authFailureMessage("missing_api_auth", "Shopify Admin verification is unavailable. Reload settings from Shopify Admin and try again."),
                    );
                }

                try {
                    return await resolver();
                } catch (error) {
                    throw new Error(
                        authFailureMessage(error?.code, error?.message || "Shopify Admin verification is unavailable."),
                    );
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

            async function loadDevelopmentNotesAccess() {
                if (!developmentNotesNavCard || !developmentNotesAccessEndpoint) {
                    return;
                }

                try {
                    await fetchJson(developmentNotesAccessEndpoint, {
                        method: "GET",
                    });
                    developmentNotesNavCard.hidden = false;
                } catch (_error) {
                    developmentNotesNavCard.hidden = true;
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

            async function saveAppContent(publish = false) {
                if (!appContentBootstrap?.authorized || !appContentBootstrap?.tenant_id) {
                    return;
                }

                if (!appContentForm) {
                    return;
                }

                clearErrors();
                const endpoint = publish ? appContentBootstrap?.endpoints?.publish : appContentBootstrap?.endpoints?.save;
                if (!endpoint) {
                    return;
                }

                const payload = collectContentPayload();
                contentState.saving = !publish;
                contentState.publishing = Boolean(publish);
                setAlert(appContentAlert, publish ? "Publishing app content..." : "Saving draft...", "neutral");

                try {
                    const response = await fetchJson(endpoint, {
                        method: "POST",
                        body: JSON.stringify(payload),
                    });

                    const nextContent = response?.data?.settings || {};
                    contentState.draft = normalizeContent(nextContent.draft || payload);
                    contentState.published = nextContent.published ? normalizeContent(nextContent.published) : null;
                    contentState.effective = normalizeContent(nextContent.effective || nextContent.published || contentState.defaults);
                    populateContentForm();
                    setAlert(appContentAlert, response?.message || (publish ? "App content published." : "Draft saved."), "success");
                } catch (error) {
                    const payloadError = extractError(error);
                    setErrors(payloadError?.errors || {});
                    setAlert(appContentAlert, payloadError?.message || error?.message || "Failed to save app content.", "error");
                } finally {
                    contentState.saving = false;
                    contentState.publishing = false;
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

            if (appContentForm) {
                appContentForm.addEventListener("input", () => {
                    renderContentPreview();
                });
                appContentSaveButton?.addEventListener("click", () => saveAppContent(false));
                appContentPublishButton?.addEventListener("click", () => saveAppContent(true));
            }

            populateWidgetSettings();
            if (!widgetBootstrap?.settings) {
                scheduleIdleTask(loadWidgetSettings);
            }
            scheduleIdleTask(loadDevelopmentNotesAccess);

            if (appContentCard) {
                populateContentForm();
            }

            syncProviderDraftFromSettings();
            populateFormFromState();
            if (!bootstrap?.settings) {
                loadSettings();
            }
        })();
    </script>
</x-shopify-embedded-shell>
