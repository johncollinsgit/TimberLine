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
        $messagingModuleState = is_array($messagingModuleState ?? null) ? $messagingModuleState : null;
        $messagingAccess = is_array($messagingAccess ?? null) ? $messagingAccess : [];
        $messagingEnabled = (bool) ($messagingAccess['enabled'] ?? false);
        $messagingStatus = trim((string) ($messagingAccess['status'] ?? ''));
        $messagingMessage = trim((string) ($messagingAccess['message'] ?? ''));
    @endphp

    <style>
        .messages-root {
            --messages-bg: #f6f7f8;
            --messages-surface: #ffffff;
            --messages-border: rgba(15, 23, 42, 0.1);
            --messages-muted: rgba(15, 23, 42, 0.62);
            --messages-text: #0f172a;
            --messages-accent: #10633f;
            --messages-accent-soft: rgba(16, 99, 63, 0.12);

            width: 100%;
            max-width: 1220px;
            margin: 0 auto;
            display: grid;
            gap: 14px;
        }

        .messages-card {
            border: 1px solid var(--messages-border);
            background: var(--messages-surface);
            border-radius: 14px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
            padding: 14px;
            display: grid;
            gap: 10px;
        }

        .messages-card h2,
        .messages-card h3,
        .messages-card h4,
        .messages-card p {
            margin: 0;
        }

        .messages-card h2 {
            color: var(--messages-text);
            font-size: 1rem;
            font-weight: 700;
        }

        .messages-card p {
            color: var(--messages-muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .messages-card[data-tone="error"] {
            border-color: rgba(180, 35, 24, 0.2);
            background: rgba(180, 35, 24, 0.06);
        }

        .messages-card[data-tone="success"] {
            border-color: rgba(15, 118, 110, 0.22);
            background: rgba(15, 118, 110, 0.08);
        }

        .messages-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .messages-tabs {
            display: inline-flex;
            gap: 6px;
            padding: 5px;
            border: 1px solid var(--messages-border);
            border-radius: 999px;
            background: var(--messages-bg);
        }

        .messages-tabs button {
            border: 0;
            border-radius: 999px;
            background: transparent;
            color: rgba(15, 23, 42, 0.72);
            min-height: 34px;
            padding: 0 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .messages-tabs button[aria-selected="true"] {
            background: var(--messages-accent-soft);
            color: var(--messages-accent);
        }

        .messages-layout {
            display: grid;
            grid-template-columns: minmax(230px, 300px) minmax(0, 1fr);
            gap: 12px;
        }

        .messages-audience-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }

        .messages-audience-row {
            width: 100%;
            border: 1px solid var(--messages-border);
            border-radius: 10px;
            background: #fff;
            color: var(--messages-text);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 10px;
            cursor: pointer;
        }

        .messages-audience-row[aria-current="true"] {
            border-color: rgba(16, 99, 63, 0.38);
            background: var(--messages-accent-soft);
        }

        .messages-audience-name {
            display: grid;
            gap: 2px;
            text-align: left;
        }

        .messages-audience-name strong {
            font-size: 13px;
            font-weight: 700;
        }

        .messages-audience-name small,
        .messages-muted {
            color: var(--messages-muted);
            font-size: 12px;
        }

        .messages-audience-row[aria-current="true"] .messages-muted {
            color: rgba(15, 23, 42, 0.74);
        }

        .messages-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .messages-pill {
            border: 1px solid var(--messages-border);
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 700;
            color: var(--messages-text);
            background: #fff;
        }

        .messages-inline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .messages-button {
            min-height: 36px;
            border-radius: 999px;
            border: 1px solid var(--messages-border);
            background: #fff;
            color: var(--messages-text);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
            padding: 0 12px;
            cursor: pointer;
        }

        .messages-button:hover:not(:disabled) {
            border-color: rgba(15, 23, 42, 0.25);
        }

        .messages-button:disabled {
            opacity: 0.62;
            cursor: not-allowed;
        }

        .messages-button--primary {
            border-color: rgba(16, 99, 63, 0.35);
            color: var(--messages-accent);
            background: var(--messages-accent-soft);
        }

        .messages-button--danger {
            border-color: rgba(180, 35, 24, 0.26);
            color: #9f2419;
            background: rgba(180, 35, 24, 0.08);
        }

        .messages-field {
            display: grid;
            gap: 6px;
            position: relative;
        }

        .messages-field label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-weight: 700;
            color: rgba(15, 23, 42, 0.5);
        }

        .messages-field input,
        .messages-field textarea,
        .messages-field select {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--messages-border);
            border-radius: 11px;
            min-height: 40px;
            padding: 9px 11px;
            font-size: 14px;
            color: var(--messages-text);
            background: #fff;
        }

        .messages-field textarea {
            min-height: 130px;
            resize: vertical;
            line-height: 1.5;
        }

        .messages-field input:focus,
        .messages-field textarea:focus,
        .messages-field select:focus {
            outline: none;
            border-color: rgba(16, 99, 63, 0.36);
            box-shadow: 0 0 0 3px rgba(16, 99, 63, 0.12);
        }

        .messages-channel-toggle {
            display: inline-flex;
            border-radius: 999px;
            border: 1px solid var(--messages-border);
            overflow: hidden;
            width: fit-content;
            background: var(--messages-bg);
        }

        .messages-channel-toggle button {
            border: 0;
            min-height: 34px;
            padding: 0 12px;
            background: transparent;
            color: var(--messages-text);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .messages-channel-toggle button[aria-pressed="true"] {
            background: var(--messages-accent-soft);
            color: var(--messages-accent);
        }

        .messages-results {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 4px);
            z-index: 12;
            list-style: none;
            margin: 0;
            padding: 6px;
            border-radius: 10px;
            border: 1px solid var(--messages-border);
            background: #fff;
            box-shadow: 0 16px 30px rgba(15, 23, 42, 0.14);
            display: grid;
            gap: 4px;
            max-height: 250px;
            overflow: auto;
        }

        .messages-results[hidden] {
            display: none;
        }

        .messages-results button {
            width: 100%;
            border: 0;
            border-radius: 8px;
            text-align: left;
            background: rgba(246, 247, 248, 0.95);
            color: var(--messages-text);
            padding: 8px 10px;
            display: grid;
            gap: 3px;
            cursor: pointer;
        }

        .messages-results button:hover {
            background: var(--messages-accent-soft);
            color: var(--messages-accent);
        }

        .messages-members {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .messages-member-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--messages-border);
            border-radius: 999px;
            background: var(--messages-bg);
            padding: 5px 9px;
            font-size: 11px;
            color: var(--messages-text);
        }

        .messages-member-chip button {
            border: 0;
            background: transparent;
            color: var(--messages-muted);
            cursor: pointer;
            padding: 0;
            font-size: 11px;
        }

        .messages-preview-box {
            border: 1px solid var(--messages-border);
            border-radius: 12px;
            background: #fff;
            padding: 10px;
            display: grid;
            gap: 8px;
        }

        .messages-sms-preview {
            border-radius: 16px;
            background: #eef2f7;
            border: 1px solid #d8dde8;
            padding: 10px;
            white-space: pre-wrap;
            font-size: 13px;
            line-height: 1.4;
            color: var(--messages-text);
        }

        .messages-email-preview-frame {
            width: 100%;
            min-height: 280px;
            border: 1px solid var(--messages-border);
            border-radius: 10px;
            background: #fff;
        }

        .messages-email-composer-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 10px;
        }

        .messages-email-sections {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }

        .messages-email-section-row {
            border: 1px solid var(--messages-border);
            border-radius: 11px;
            padding: 10px;
            background: #fff;
            display: grid;
            gap: 8px;
        }

        .messages-email-section-row[data-selected="true"] {
            border-color: rgba(16, 99, 63, 0.38);
            background: var(--messages-accent-soft);
        }

        .messages-email-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
        }

        .messages-email-section-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--messages-text);
        }

        .messages-email-section-actions {
            display: inline-flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .messages-email-icon-button {
            border: 1px solid var(--messages-border);
            border-radius: 999px;
            min-height: 30px;
            padding: 0 10px;
            background: #fff;
            color: var(--messages-text);
            cursor: pointer;
            font-size: 11px;
            font-weight: 700;
        }

        .messages-email-icon-button:hover {
            border-color: rgba(15, 23, 42, 0.25);
        }

        .messages-email-icon-button--danger {
            color: #9f2419;
            border-color: rgba(180, 35, 24, 0.26);
            background: rgba(180, 35, 24, 0.08);
        }

        .messages-email-settings-empty {
            border: 1px dashed var(--messages-border);
            border-radius: 10px;
            padding: 12px;
            color: var(--messages-muted);
            font-size: 12px;
            background: var(--messages-bg);
        }

        .messages-email-product-results {
            list-style: none;
            margin: 0;
            padding: 6px;
            border: 1px solid var(--messages-border);
            border-radius: 10px;
            display: grid;
            gap: 6px;
            max-height: 220px;
            overflow: auto;
            background: #fff;
        }

        .messages-email-product-results button {
            width: 100%;
            border: 1px solid var(--messages-border);
            border-radius: 10px;
            background: #fff;
            color: var(--messages-text);
            text-align: left;
            padding: 8px 10px;
            cursor: pointer;
            display: grid;
            gap: 3px;
        }

        .messages-email-product-results button:hover {
            border-color: rgba(16, 99, 63, 0.38);
            background: var(--messages-accent-soft);
        }

        .messages-send-card {
            width: 100%;
            display: grid;
            gap: 8px;
        }

        .messages-history {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }

        .messages-history li {
            border: 1px solid var(--messages-border);
            border-radius: 11px;
            padding: 10px;
            background: #fff;
            display: grid;
            gap: 4px;
        }

        .messages-customer-card {
            border: 1px solid var(--messages-border);
            border-radius: 12px;
            background: var(--messages-bg);
            padding: 10px;
            display: grid;
            gap: 6px;
        }

        .messages-customer-card h3 {
            font-size: 14px;
            font-weight: 700;
            color: var(--messages-text);
            margin: 0;
        }

        .messages-status-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        [hidden] {
            display: none !important;
        }

        @media (max-width: 1060px) {
            .messages-layout {
                grid-template-columns: minmax(0, 1fr);
            }

            .messages-email-composer-grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .messages-send-card {
                position: static;
            }
        }
    </style>

    <section class="messages-root" id="messages-root">
        @if(is_array($messagingModuleState))
            <x-tenancy.module-state-card
                :module-state="$messagingModuleState"
                title="Messaging module state"
                description="Visibility and access follow tenant entitlement + module-state conventions."
            />
        @endif

        <article class="messages-card" id="messages-global-alert" hidden></article>

        @if(! $authorized)
            <article class="messages-card">
                <h2>Messages requires Shopify context</h2>
                <p>Open this page from Shopify Admin so Backstage can verify the store session and tenant scope.</p>
            </article>
        @elseif(! $messagingEnabled)
            <article class="messages-card" data-tone="error">
                <h2>Messaging is locked</h2>
                <p>{{ $messagingMessage !== '' ? $messagingMessage : 'Messaging is not enabled for this tenant.' }}</p>
                @if($messagingStatus !== '')
                    <p class="messages-muted">Status: {{ $messagingStatus }}</p>
                @endif
            </article>
        @else
            <article class="messages-card">
                <div class="messages-header">
                    <div>
                        <h2>Messages Workspace</h2>
                        <p>Choose an audience, pick channel, compose, preview, then confirm send.</p>
                    </div>
                    <nav class="messages-tabs" aria-label="Messages tabs">
                        <button type="button" data-tab-button="groups" aria-selected="true">Groups</button>
                        <button type="button" data-tab-button="individuals" aria-selected="false">Individuals</button>
                        <button type="button" data-tab-button="history" aria-selected="false">History</button>
                    </nav>
                </div>
            </article>

            <section data-tab-panel="groups">
                <div class="messages-layout">
                    <aside class="messages-card">
                        <div class="messages-header" style="align-items:flex-start;">
                            <div>
                                <h3>Audience Groups</h3>
                                <p class="messages-muted">Select or deselect a target group. Nothing is auto-selected.</p>
                            </div>
                            <button type="button" class="messages-button" id="messages-open-group-editor">Edit groups</button>
                        </div>

                        <div class="messages-pills" id="messages-audience-pills">
                            <span class="messages-pill">SMS: --</span>
                            <span class="messages-pill">Email: --</span>
                            <span class="messages-pill">Unique: --</span>
                        </div>

                        <ul class="messages-audience-list" id="messages-audience-list"></ul>
                        <p class="messages-muted" id="messages-audience-diagnostics"></p>
                    </aside>

                    <article class="messages-card">
                        <h3>Compose Group Message</h3>
                        <p class="messages-muted" id="messages-selected-target">No group selected.</p>

                        <div class="messages-field">
                            <label>Channel</label>
                            <div class="messages-channel-toggle" id="messages-group-channel-toggle">
                                <button type="button" data-group-channel="sms" aria-pressed="true">SMS</button>
                                <button type="button" data-group-channel="email" aria-pressed="false">Email</button>
                            </div>
                        </div>

                        <div class="messages-field" id="messages-group-subject-wrap" hidden>
                            <label for="messages-group-subject">Email subject</label>
                            <input id="messages-group-subject" type="text" maxlength="200" placeholder="Subject line" />
                        </div>

                        <div class="messages-field">
                            <label for="messages-group-body">Message</label>
                            <textarea id="messages-group-body" maxlength="5000" placeholder="Write your message"></textarea>
                        </div>

                        <div class="messages-field" id="messages-group-sender-wrap">
                            <label for="messages-group-sender">SMS sender key (optional)</label>
                            <input id="messages-group-sender" type="text" maxlength="80" placeholder="default sender" />
                        </div>

                        <div id="messages-email-editor-shell" hidden>
                            <section class="messages-card" id="messages-email-editor">
                                <div class="messages-header" style="align-items:flex-start;">
                                    <div>
                                        <h4>Email composer</h4>
                                        <p class="messages-muted">Use sections instead of raw HTML. Add blocks, reorder, then send.</p>
                                    </div>
                                    <div class="messages-inline-actions">
                                        <button type="button" class="messages-button" data-email-add-section="image">Add photo</button>
                                        <button type="button" class="messages-button" data-email-add-section="product">Add Shopify product</button>
                                        <button type="button" class="messages-button" data-email-add-section="button">Add button</button>
                                        <button type="button" class="messages-button" data-email-add-section="text">Add rich text</button>
                                        <button type="button" class="messages-button" data-email-add-section="heading">Add heading</button>
                                        <button type="button" class="messages-button" data-email-add-section="divider">Add divider</button>
                                        <button type="button" class="messages-button" data-email-add-section="spacer">Add spacer</button>
                                        <button type="button" class="messages-button" id="messages-email-advanced-toggle">Advanced HTML</button>
                                    </div>
                                </div>

                                <div class="messages-email-composer-grid">
                                    <section class="messages-card messages-email-sections-card">
                                        <h4>Sections</h4>
                                        <p class="messages-muted">Use move up/down to reorder sections. Drag and drop can be added later.</p>
                                        <ul class="messages-email-sections" id="messages-email-sections"></ul>
                                    </section>

                                    <section class="messages-card messages-email-settings-card">
                                        <h4>Section settings</h4>
                                        <p class="messages-muted">Select a section to edit its options.</p>
                                        <div id="messages-email-section-settings"></div>
                                    </section>
                                </div>

                                <section class="messages-card" id="messages-email-advanced-panel" hidden>
                                    <div class="messages-header" style="align-items:flex-start;">
                                        <div>
                                            <h4>Advanced HTML</h4>
                                            <p class="messages-muted">Legacy HTML mode is available for edge cases. Section JSON remains the primary model.</p>
                                        </div>
                                        <div class="messages-inline-actions">
                                            <button type="button" class="messages-button" id="messages-email-use-sections">Use section composer</button>
                                        </div>
                                    </div>
                                    <div class="messages-field">
                                        <label for="messages-email-template-kind">Legacy preset</label>
                                        <select id="messages-email-template-kind">
                                            <option value="simple">Simple</option>
                                            <option value="announcement">Announcement</option>
                                            <option value="reminder">Reminder</option>
                                        </select>
                                    </div>
                                    <div class="messages-field">
                                        <label for="messages-email-template-html">Template HTML</label>
                                        <textarea id="messages-email-template-html" spellcheck="false"></textarea>
                                    </div>
                                </section>

                                <div class="messages-field">
                                    <label>Live email preview</label>
                                    <iframe title="Email preview" class="messages-email-preview-frame" id="messages-email-live-preview" sandbox="allow-same-origin"></iframe>
                                </div>
                            </section>
                        </div>

                        <section class="messages-preview-box" id="messages-group-preview" hidden>
                            <h4 style="margin:0;font-size:13px;color:#0f172a;">Preview & confirmation</h4>
                            <p class="messages-muted" id="messages-group-preview-summary"></p>
                            <div id="messages-group-preview-body"></div>
                        </section>
                    </article>
                </div>

                <article class="messages-card" id="messages-group-editor" hidden>
                    <h3>Group Editor</h3>
                    <p class="messages-muted">Create or update saved groups without cluttering the composer.</p>

                    <div class="messages-field">
                        <label for="messages-group-name">Group name</label>
                        <input id="messages-group-name" type="text" maxlength="120" placeholder="VIP customers" />
                    </div>

                    <div class="messages-field">
                        <label for="messages-group-description">Description (optional)</label>
                        <input id="messages-group-description" type="text" maxlength="500" placeholder="Internal notes" />
                    </div>

                    <div class="messages-field">
                        <label for="messages-group-member-search">Add members</label>
                        <input id="messages-group-member-search" type="search" autocomplete="off" placeholder="Search customer" />
                        <ul class="messages-results" id="messages-group-member-results" hidden></ul>
                    </div>

                    <div class="messages-members" id="messages-group-members"></div>

                    <div class="messages-inline-actions">
                        <button type="button" class="messages-button messages-button--primary" id="messages-group-save">Save group</button>
                        <button type="button" class="messages-button" id="messages-group-reset">New group</button>
                        <button type="button" class="messages-button messages-button--danger" id="messages-group-close">Close editor</button>
                    </div>
                    <p class="messages-muted" id="messages-group-status"></p>
                </article>

                <article class="messages-card messages-send-card" id="messages-send-card">
                    <h3>Send to group</h3>
                    <p class="messages-muted" id="messages-send-estimate">Select a group to estimate recipients.</p>
                    <div class="messages-inline-actions">
                        <button type="button" class="messages-button" id="messages-group-preview-button">Preview message</button>
                        <button type="button" class="messages-button" id="messages-group-preview-back" hidden>Back to edit</button>
                        <button type="button" class="messages-button messages-button--primary" id="messages-group-send-button" hidden>Confirm and send</button>
                    </div>
                    <p class="messages-muted" id="messages-group-send-status"></p>
                </article>
            </section>

            <section data-tab-panel="individuals" hidden>
                <div class="messages-layout" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr);">
                    <article class="messages-card">
                        <h3>Select Customer</h3>
                        <p class="messages-muted">Search by name, email, or phone using the Customers lookup behavior.</p>
                        <div class="messages-field">
                            <label for="messages-individual-search">Customer search</label>
                            <input id="messages-individual-search" type="search" autocomplete="off" placeholder="Search customer" />
                            <ul class="messages-results" id="messages-individual-results" hidden></ul>
                        </div>
                        <div class="messages-customer-card" id="messages-individual-customer"></div>
                    </article>

                    <article class="messages-card">
                        <h3>Compose Individual Message</h3>

                        <div class="messages-field">
                            <label for="messages-individual-channel">Channel</label>
                            <select id="messages-individual-channel">
                                <option value="sms">SMS</option>
                                <option value="email">Email</option>
                            </select>
                        </div>

                        <div class="messages-field" id="messages-individual-subject-wrap" hidden>
                            <label for="messages-individual-subject">Email subject</label>
                            <input id="messages-individual-subject" type="text" maxlength="200" placeholder="Subject line" />
                        </div>

                        <div class="messages-field">
                            <label for="messages-individual-body">Message</label>
                            <textarea id="messages-individual-body" maxlength="5000" placeholder="Write your message"></textarea>
                        </div>

                        <div class="messages-field">
                            <label for="messages-individual-sender">SMS sender key (optional)</label>
                            <input id="messages-individual-sender" type="text" maxlength="80" placeholder="default sender" />
                        </div>

                        <div class="messages-inline-actions">
                            <button type="button" class="messages-button" id="messages-individual-preview">Preview message</button>
                            <button type="button" class="messages-button" id="messages-individual-preview-back" hidden>Back to edit</button>
                            <button type="button" class="messages-button messages-button--primary" id="messages-individual-send" hidden>Confirm and send</button>
                        </div>

                        <section class="messages-preview-box" id="messages-individual-preview-box" hidden>
                            <p class="messages-muted" id="messages-individual-preview-summary"></p>
                            <div id="messages-individual-preview-body"></div>
                        </section>

                        <p class="messages-muted" id="messages-individual-status"></p>
                    </article>
                </div>
            </section>

            <section data-tab-panel="history" hidden>
                <article class="messages-card">
                    <h3>Recent Messaging</h3>
                    <p class="messages-muted">History loads only when needed for faster page startup.</p>
                    <ul class="messages-history" id="messages-history-list"></ul>
                </article>
            </section>
        @endif
    </section>

    @if($authorized && $messagingEnabled)
        <script>
            (function () {
                const bootstrap = @json($messagingBootstrap ?? []);
                const endpoints = typeof bootstrap?.endpoints === "object" && bootstrap.endpoints !== null
                    ? bootstrap.endpoints
                    : {};
                const initialData = typeof bootstrap?.data === "object" && bootstrap.data !== null
                    ? bootstrap.data
                    : {};

                const EMAIL_TEMPLATES = {
                    simple: `<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f3f4f6;padding:16px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="620" cellspacing="0" cellpadding="0" style="background:#ffffff;border-radius:12px;padding:22px;"><tr><td><h2 style="margin:0 0 12px 0;color:#0f172a;">@{{subject}}</h2><div style="font-size:15px;line-height:1.55;color:#1f2937;">@{{message_body}}</div></td></tr></table></td></tr></table></body></html>`,
                    announcement: `<!doctype html><html><body style="font-family:Arial,sans-serif;background:#eef6f1;padding:16px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="640" cellspacing="0" cellpadding="0" style="background:#ffffff;border-radius:12px;border:1px solid #d1e6db;"><tr><td style="padding:16px 18px;background:#0f6f4c;color:#ffffff;border-radius:12px 12px 0 0;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;">Backstage Message</td></tr><tr><td style="padding:18px;"><h2 style="margin:0 0 12px 0;color:#0f172a;">@{{subject}}</h2><div style="font-size:15px;line-height:1.55;color:#1f2937;">@{{message_body}}</div></td></tr></table></td></tr></table></body></html>`,
                    reminder: `<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f8fafc;padding:16px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="620" cellspacing="0" cellpadding="0" style="background:#ffffff;border-radius:12px;padding:20px;border:1px solid #e2e8f0;"><tr><td><div style="display:inline-block;background:#e2e8f0;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:700;color:#334155;margin-bottom:10px;">Reminder</div><h2 style="margin:0 0 12px 0;color:#0f172a;">@{{subject}}</h2><div style="font-size:15px;line-height:1.55;color:#334155;">@{{message_body}}</div></td></tr></table></td></tr></table></body></html>`,
                };
                const EMAIL_COMPOSER_STORAGE_KEY = "shopify-embedded-messaging-email-composer-v1";

                const state = {
                    tab: "groups",
                    groups: typeof initialData.groups === "object" && initialData.groups !== null
                        ? initialData.groups
                        : { saved: [], auto: [] },
                    audienceSummary: { sms: 0, email: 0, overlap: 0, unique: 0 },
                    groupSummaries: {},
                    audienceDiagnostics: {},
                    history: [],
                    historyLoaded: false,
                    selectedTarget: null,
                    groupChannel: "sms",
                    previewReady: false,
                    groupPreviewPayload: null,
                    editingGroupId: null,
                    groupMembers: new Map(),
                    selectedCustomer: null,
                    individualPreviewReady: false,
                    emailComposer: {
                        mode: "sections",
                        sections: [],
                        selectedSectionId: null,
                        productSearchResults: [],
                    },
                };

                const alertCard = document.getElementById("messages-global-alert");
                const tabButtons = Array.from(document.querySelectorAll("[data-tab-button]"));
                const tabPanels = Array.from(document.querySelectorAll("[data-tab-panel]"));

                const audienceList = document.getElementById("messages-audience-list");
                const audiencePills = document.getElementById("messages-audience-pills");
                const audienceDiagnostics = document.getElementById("messages-audience-diagnostics");
                const selectedTargetSummary = document.getElementById("messages-selected-target");
                const sendEstimate = document.getElementById("messages-send-estimate");
                const groupSendStatus = document.getElementById("messages-group-send-status");

                const groupChannelToggle = document.getElementById("messages-group-channel-toggle");
                const groupSubjectWrap = document.getElementById("messages-group-subject-wrap");
                const groupSubject = document.getElementById("messages-group-subject");
                const groupBody = document.getElementById("messages-group-body");
                const groupSenderWrap = document.getElementById("messages-group-sender-wrap");
                const groupSender = document.getElementById("messages-group-sender");

                const emailEditorShell = document.getElementById("messages-email-editor-shell");
                const emailEditor = document.getElementById("messages-email-editor");
                const emailAdvancedToggle = document.getElementById("messages-email-advanced-toggle");
                const emailUseSections = document.getElementById("messages-email-use-sections");
                const emailAdvancedPanel = document.getElementById("messages-email-advanced-panel");
                const emailAddSectionButtons = Array.from(document.querySelectorAll("[data-email-add-section]"));
                const emailSectionsList = document.getElementById("messages-email-sections");
                const emailSectionSettings = document.getElementById("messages-email-section-settings");
                const emailTemplateKind = document.getElementById("messages-email-template-kind");
                const emailTemplateHtml = document.getElementById("messages-email-template-html");
                const emailLivePreview = document.getElementById("messages-email-live-preview");

                const groupPreviewWrap = document.getElementById("messages-group-preview");
                const groupPreviewSummary = document.getElementById("messages-group-preview-summary");
                const groupPreviewBody = document.getElementById("messages-group-preview-body");
                const groupPreviewButton = document.getElementById("messages-group-preview-button");
                const groupPreviewBack = document.getElementById("messages-group-preview-back");
                const groupSendButton = document.getElementById("messages-group-send-button");

                const groupEditor = document.getElementById("messages-group-editor");
                const groupEditorOpen = document.getElementById("messages-open-group-editor");
                const groupEditorClose = document.getElementById("messages-group-close");
                const groupName = document.getElementById("messages-group-name");
                const groupDescription = document.getElementById("messages-group-description");
                const groupMemberSearch = document.getElementById("messages-group-member-search");
                const groupMemberResults = document.getElementById("messages-group-member-results");
                const groupMembersWrap = document.getElementById("messages-group-members");
                const groupSave = document.getElementById("messages-group-save");
                const groupReset = document.getElementById("messages-group-reset");
                const groupStatus = document.getElementById("messages-group-status");

                const historyList = document.getElementById("messages-history-list");

                const individualSearch = document.getElementById("messages-individual-search");
                const individualResults = document.getElementById("messages-individual-results");
                const individualCustomer = document.getElementById("messages-individual-customer");
                const individualChannel = document.getElementById("messages-individual-channel");
                const individualSubjectWrap = document.getElementById("messages-individual-subject-wrap");
                const individualSubject = document.getElementById("messages-individual-subject");
                const individualBody = document.getElementById("messages-individual-body");
                const individualSender = document.getElementById("messages-individual-sender");
                const individualPreview = document.getElementById("messages-individual-preview");
                const individualPreviewBack = document.getElementById("messages-individual-preview-back");
                const individualSend = document.getElementById("messages-individual-send");
                const individualPreviewBox = document.getElementById("messages-individual-preview-box");
                const individualPreviewSummary = document.getElementById("messages-individual-preview-summary");
                const individualPreviewBody = document.getElementById("messages-individual-preview-body");
                const individualStatus = document.getElementById("messages-individual-status");

                function authFailureMessage(status, fallbackMessage) {
                    const messages = {
                        missing_api_auth: "Shopify Admin verification is unavailable. Reload from Shopify Admin and try again.",
                        invalid_session_token: "Shopify Admin verification failed. Reload from Shopify Admin and try again.",
                        expired_session_token: "Your Shopify Admin session expired. Reload from Shopify Admin and try again.",
                    };

                    return messages[status] || fallbackMessage || null;
                }

                async function resolveEmbeddedAuthHeaders() {
                    if (!window.shopify || typeof window.shopify.idToken !== "function") {
                        throw new Error(authFailureMessage("missing_api_auth", "Shopify Admin verification is unavailable."));
                    }

                    let token = null;
                    try {
                        token = await Promise.race([
                            Promise.resolve(window.shopify.idToken()),
                            new Promise((resolve) => window.setTimeout(() => resolve(null), 1500)),
                        ]);
                    } catch (error) {
                        throw new Error(authFailureMessage("invalid_session_token", "Shopify Admin verification failed."));
                    }

                    if (typeof token !== "string" || token.trim() === "") {
                        throw new Error(authFailureMessage("missing_api_auth", "Shopify Admin verification is unavailable."));
                    }

                    return {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        Authorization: `Bearer ${token.trim()}`,
                    };
                }

                async function fetchJson(url, options = {}) {
                    const headers = await resolveEmbeddedAuthHeaders();
                    const response = await fetch(url, {
                        method: options.method || "GET",
                        headers: { ...headers, ...(options.headers || {}) },
                        credentials: "same-origin",
                        body: options.body ?? null,
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

                function escapeHtml(value) {
                    return String(value ?? "")
                        .replace(/&/g, "&amp;")
                        .replace(/</g, "&lt;")
                        .replace(/>/g, "&gt;")
                        .replace(/"/g, "&quot;")
                        .replace(/'/g, "&#039;");
                }

                function setAlert(message, tone = "neutral") {
                    if (!alertCard) {
                        return;
                    }

                    const text = typeof message === "string" ? message.trim() : "";
                    if (text === "") {
                        alertCard.hidden = true;
                        alertCard.textContent = "";
                        alertCard.removeAttribute("data-tone");
                        return;
                    }

                    alertCard.hidden = false;
                    alertCard.setAttribute("data-tone", tone);
                    alertCard.textContent = text;
                }

                function setInlineStatus(el, message) {
                    if (el) {
                        el.textContent = typeof message === "string" ? message : "";
                    }
                }

                function debounce(fn, wait = 240) {
                    let timer = null;
                    return (...args) => {
                        if (timer) {
                            window.clearTimeout(timer);
                        }
                        timer = window.setTimeout(() => fn(...args), wait);
                    };
                }

                function switchTab(tab) {
                    state.tab = tab;
                    tabButtons.forEach((button) => {
                        const active = button.dataset.tabButton === tab;
                        button.setAttribute("aria-selected", active ? "true" : "false");
                    });
                    tabPanels.forEach((panel) => {
                        panel.hidden = panel.dataset.tabPanel !== tab;
                    });

                    if (tab === "history" && !state.historyLoaded) {
                        refreshHistory();
                    }
                }

                function replaceGroupEndpoint(base, groupId) {
                    return String(base || "").replace("__GROUP__", encodeURIComponent(String(groupId)));
                }

                function normalizedCustomer(row) {
                    if (!row || typeof row !== "object") {
                        return null;
                    }

                    const id = Number.parseInt(row.id, 10);
                    if (!Number.isFinite(id) || id <= 0) {
                        return null;
                    }

                    return {
                        id,
                        name: String(row.name || `Customer #${id}`),
                        email: row.email ? String(row.email) : null,
                        phone: row.phone ? String(row.phone) : null,
                        sms_contactable: Boolean(row.sms_contactable),
                        email_contactable: Boolean(row.email_contactable),
                    };
                }

                function targetRows() {
                    const saved = Array.isArray(state.groups?.saved) ? state.groups.saved : [];
                    const auto = Array.isArray(state.groups?.auto) ? state.groups.auto : [];

                    const rows = [];
                    auto.forEach((group) => {
                        const key = String(group?.key || "").trim();
                        if (key === "") {
                            return;
                        }
                        const summary = autoGroupSummaryForKey(key, group?.counts);
                        const channels = normalizedChannels(group?.channels);
                        const count = Number(summary.unique || 0);

                        rows.push({
                            type: "auto",
                            key,
                            name: String(group?.name || "Automatic audience"),
                            description: String(group?.description || ""),
                            channels,
                            count,
                            counts: summary,
                        });
                    });

                    saved.forEach((group) => {
                        rows.push({
                            type: "saved",
                            id: Number(group?.id || 0),
                            name: String(group?.name || "Saved group"),
                            description: String(group?.description || ""),
                            count: Number(group?.members_count || 0),
                        });
                    });

                    return rows
                        .filter((row) => row.type === "auto" || (Number(row.id || 0) > 0))
                        .sort((a, b) => Number(b.count || 0) - Number(a.count || 0));
                }

                function normalizedChannels(channels) {
                    const unique = Array.from(new Set(
                        (Array.isArray(channels) ? channels : ["sms", "email"])
                            .map((channel) => String(channel || "").trim().toLowerCase())
                            .filter((channel) => channel === "sms" || channel === "email")
                    ));

                    return unique.length > 0 ? unique : ["sms", "email"];
                }

                function autoGroupSummaryForKey(groupKey, fallback) {
                    const key = String(groupKey || "").trim();
                    const fallbackSummary = typeof fallback === "object" && fallback !== null
                        ? fallback
                        : {};
                    const groupSummary = typeof state.groupSummaries?.[key] === "object" && state.groupSummaries[key] !== null
                        ? state.groupSummaries[key]
                        : {};
                    const allSubscribed = key === "all_subscribed"
                        ? state.audienceSummary
                        : {};

                    return {
                        sms: Number(groupSummary.sms ?? fallbackSummary.sms ?? allSubscribed.sms ?? 0),
                        email: Number(groupSummary.email ?? fallbackSummary.email ?? allSubscribed.email ?? 0),
                        unique: Number(groupSummary.unique ?? fallbackSummary.unique ?? allSubscribed.unique ?? 0),
                        overlap: Number(groupSummary.overlap ?? fallbackSummary.overlap ?? allSubscribed.overlap ?? 0),
                    };
                }

                function renderAudiencePills() {
                    if (!audiencePills) {
                        return;
                    }

                    audiencePills.innerHTML = "";
                    const entries = [
                        `SMS: ${Number(state.audienceSummary.sms || 0).toLocaleString()}`,
                        `Email: ${Number(state.audienceSummary.email || 0).toLocaleString()}`,
                        `Unique: ${Number(state.audienceSummary.unique || 0).toLocaleString()}`,
                    ];

                    entries.forEach((value) => {
                        const pill = document.createElement("span");
                        pill.className = "messages-pill";
                        pill.textContent = value;
                        audiencePills.appendChild(pill);
                    });
                }

                function renderAudienceDiagnostics() {
                    if (!audienceDiagnostics) {
                        return;
                    }

                    const sms = state.audienceDiagnostics?.sms || {};
                    const email = state.audienceDiagnostics?.email || {};
                    audienceDiagnostics.textContent = `SMS displayed ${Number(sms.displayed_audience_count || 0).toLocaleString()} (query ${Number(sms.query_candidate_count || 0).toLocaleString()} · sendable ${Number(sms.resolved_sendable_count || 0).toLocaleString()}) · Email displayed ${Number(email.displayed_audience_count || 0).toLocaleString()} (query ${Number(email.query_candidate_count || 0).toLocaleString()} · sendable ${Number(email.resolved_sendable_count || 0).toLocaleString()})`;
                }

                function renderAudienceList() {
                    if (!audienceList) {
                        return;
                    }

                    audienceList.innerHTML = "";
                    const rows = targetRows();
                    if (rows.length === 0) {
                        const li = document.createElement("li");
                        li.className = "messages-muted";
                        li.textContent = "No audience groups available.";
                        audienceList.appendChild(li);
                        return;
                    }

                    rows.forEach((row) => {
                        const li = document.createElement("li");
                        const button = document.createElement("button");
                        button.type = "button";
                        button.className = "messages-audience-row";

                        const active = row.type === "auto"
                            ? state.selectedTarget?.type === "auto" && state.selectedTarget?.key === row.key
                            : state.selectedTarget?.type === "saved" && Number(state.selectedTarget?.id || 0) === Number(row.id || 0);
                        button.setAttribute("aria-current", active ? "true" : "false");

                        const countLabel = Number(row.count || 0).toLocaleString();
                        const subtitle = row.type === "auto"
                            ? `SMS ${Number(row.counts?.sms || 0).toLocaleString()} · Email ${Number(row.counts?.email || 0).toLocaleString()} · Unique ${Number(row.counts?.unique || 0).toLocaleString()}`
                            : `${countLabel} member${Number(row.count || 0) === 1 ? "" : "s"}`;

                        button.innerHTML = `
                            <span class="messages-audience-name">
                                <strong>${escapeHtml(row.name || "Group")}</strong>
                                <small class="messages-muted">${escapeHtml(subtitle)}</small>
                            </span>
                            <span class="messages-muted">${active ? "Selected" : "Select"}</span>
                        `;

                        button.addEventListener("click", () => {
                            if (active) {
                                state.selectedTarget = null;
                                resetGroupPreviewState();
                                updateGroupChannelUi();
                                renderAudienceList();
                                renderSelectedTargetSummary();
                                renderGroupEstimate();
                                return;
                            }

                            state.selectedTarget = row.type === "auto"
                                ? { type: "auto", key: row.key, name: row.name, channels: row.channels }
                                : { type: "saved", id: row.id, name: row.name, members_count: row.count };

                            resetGroupPreviewState();
                            updateGroupChannelUi();
                            renderAudienceList();
                            renderSelectedTargetSummary();
                            renderGroupEstimate();
                        });

                        li.appendChild(button);
                        audienceList.appendChild(li);
                    });
                }

                function renderSelectedTargetSummary() {
                    if (!selectedTargetSummary) {
                        return;
                    }

                    if (!state.selectedTarget) {
                        selectedTargetSummary.textContent = "No group selected.";
                        return;
                    }

                    if (state.selectedTarget.type === "auto") {
                        selectedTargetSummary.textContent = `Selected: ${state.selectedTarget.name || "All Subscribed"} (optional automatic audience)`;
                        return;
                    }

                    selectedTargetSummary.textContent = `Selected: ${state.selectedTarget.name || "Saved group"} (saved group)`;
                }

                function renderGroupEstimate() {
                    if (!sendEstimate) {
                        return;
                    }

                    if (!state.selectedTarget) {
                        sendEstimate.textContent = "Select a group to estimate recipients.";
                        return;
                    }

                    if (state.groupPreviewPayload && state.previewReady) {
                        sendEstimate.textContent = `Preview ready: ${Number(state.groupPreviewPayload.estimated_recipients || 0).toLocaleString()} recipients estimated.`;
                        return;
                    }

                    if (state.selectedTarget.type === "auto") {
                        const summary = autoGroupSummaryForKey(
                            state.selectedTarget.key,
                            state.selectedTarget?.counts
                        );
                        const estimated = state.groupChannel === "email"
                            ? Number(summary.email || 0)
                            : Number(summary.sms || 0);
                        sendEstimate.textContent = `Estimated recipients (${state.groupChannel.toUpperCase()}): ${estimated.toLocaleString()} before final preview resolution.`;
                        return;
                    }

                    const estimate = Number(state.selectedTarget.members_count || 0);
                    sendEstimate.textContent = `Estimated recipients: ${estimate.toLocaleString()} members before channel filtering.`;
                }

                function selectedTargetChannels() {
                    if (state.selectedTarget?.type !== "auto") {
                        return ["sms", "email"];
                    }

                    return normalizedChannels(state.selectedTarget?.channels);
                }

                function updateGroupChannelUi() {
                    const buttons = Array.from(groupChannelToggle?.querySelectorAll("button[data-group-channel]") || []);
                    const allowedChannels = selectedTargetChannels();

                    if (!allowedChannels.includes(state.groupChannel)) {
                        state.groupChannel = allowedChannels[0] || "sms";
                    }

                    buttons.forEach((button) => {
                        const channel = String(button.dataset.groupChannel || "").trim().toLowerCase();
                        const allowed = allowedChannels.includes(channel);
                        const active = channel === state.groupChannel;
                        button.disabled = !allowed;
                        button.setAttribute("aria-disabled", allowed ? "false" : "true");
                        button.setAttribute("aria-pressed", active ? "true" : "false");
                    });

                    const isEmail = state.groupChannel === "email";
                    if (groupSubjectWrap) {
                        groupSubjectWrap.hidden = !isEmail;
                    }
                    if (groupSenderWrap) {
                        groupSenderWrap.hidden = isEmail;
                    }
                    if (emailEditorShell) {
                        emailEditorShell.hidden = !isEmail;
                    }
                    if (!isEmail) {
                        if (emailAdvancedPanel) {
                            emailAdvancedPanel.hidden = true;
                        }
                        state.emailComposer.mode = "sections";
                        persistEmailComposerState();
                    }

                    renderGroupEstimate();
                    resetGroupPreviewState();
                }

                function resetGroupPreviewState() {
                    state.previewReady = false;
                    state.groupPreviewPayload = null;
                    if (groupPreviewWrap) {
                        groupPreviewWrap.hidden = true;
                    }
                    if (groupPreviewSummary) {
                        groupPreviewSummary.textContent = "";
                    }
                    if (groupPreviewBody) {
                        groupPreviewBody.innerHTML = "";
                    }
                    if (groupSendButton) {
                        groupSendButton.hidden = true;
                    }
                    if (groupPreviewBack) {
                        groupPreviewBack.hidden = true;
                    }
                    if (groupPreviewButton) {
                        groupPreviewButton.hidden = false;
                    }
                }

                function renderedMessageBodyAsHtml(messageText) {
                    return escapeHtml(messageText || "")
                        .replace(/\n{2,}/g, "</p><p>")
                        .replace(/\n/g, "<br>");
                }

                function randomSectionId() {
                    if (window.crypto && typeof window.crypto.randomUUID === "function") {
                        return window.crypto.randomUUID();
                    }

                    return `sec_${Date.now()}_${Math.random().toString(16).slice(2, 9)}`;
                }

                function emailSectionTypeLabel(type) {
                    const map = {
                        image: "Photo",
                        product: "Shopify product",
                        button: "Button",
                        text: "Rich text",
                        divider: "Divider",
                        heading: "Heading",
                        spacer: "Spacer",
                    };

                    return map[type] || "Section";
                }

                function createSection(type) {
                    const id = randomSectionId();
                    const normalizedType = String(type || "").trim().toLowerCase();

                    if (normalizedType === "image") {
                        return { id, type: "image", imageUrl: "", alt: "", href: "", padding: "12px 0" };
                    }
                    if (normalizedType === "product") {
                        return { id, type: "product", productId: "", title: "", imageUrl: "", price: "", href: "", buttonLabel: "View product" };
                    }
                    if (normalizedType === "button") {
                        return { id, type: "button", label: "Learn more", href: "", align: "center" };
                    }
                    if (normalizedType === "heading") {
                        return { id, type: "heading", text: "Heading", align: "left" };
                    }
                    if (normalizedType === "divider") {
                        return { id, type: "divider" };
                    }
                    if (normalizedType === "spacer") {
                        return { id, type: "spacer", height: 20 };
                    }

                    return { id, type: "text", html: "" };
                }

                function normalizeSection(section) {
                    if (!section || typeof section !== "object") {
                        return null;
                    }

                    const type = String(section.type || "").trim().toLowerCase();
                    if (!["image", "product", "button", "text", "divider", "heading", "spacer"].includes(type)) {
                        return null;
                    }

                    const normalized = createSection(type);
                    return { ...normalized, ...section, id: String(section.id || normalized.id) };
                }

                function persistEmailComposerState() {
                    try {
                        window.localStorage.setItem(
                            EMAIL_COMPOSER_STORAGE_KEY,
                            JSON.stringify({
                                mode: state.emailComposer.mode,
                                sections: state.emailComposer.sections,
                                selectedSectionId: state.emailComposer.selectedSectionId,
                                legacyPreset: String(emailTemplateKind?.value || "simple"),
                                legacyHtml: String(emailTemplateHtml?.value || ""),
                            })
                        );
                    } catch (error) {
                        // Storage is optional; keep runtime-only state when unavailable.
                    }
                }

                function hydrateEmailComposerState() {
                    try {
                        const raw = window.localStorage.getItem(EMAIL_COMPOSER_STORAGE_KEY);
                        if (!raw) {
                            return;
                        }

                        const parsed = JSON.parse(raw);
                        if (!parsed || typeof parsed !== "object") {
                            return;
                        }

                        const sections = Array.isArray(parsed.sections)
                            ? parsed.sections.map(normalizeSection).filter(Boolean)
                            : [];
                        if (sections.length > 0) {
                            state.emailComposer.sections = sections;
                        }

                        const mode = String(parsed.mode || "").trim().toLowerCase();
                        if (mode === "legacy_html" || mode === "sections") {
                            state.emailComposer.mode = mode;
                        }

                        const selectedSectionId = String(parsed.selectedSectionId || "").trim();
                        if (selectedSectionId !== "") {
                            state.emailComposer.selectedSectionId = selectedSectionId;
                        }

                        const legacyPreset = String(parsed.legacyPreset || "").trim();
                        if (legacyPreset !== "" && emailTemplateKind) {
                            emailTemplateKind.value = legacyPreset;
                        }

                        const legacyHtml = String(parsed.legacyHtml || "").trim();
                        if (legacyHtml !== "" && emailTemplateHtml) {
                            emailTemplateHtml.value = legacyHtml;
                        }
                    } catch (error) {
                        // Ignore bad persisted payloads and continue with defaults.
                    }
                }

                function ensureEmailComposerState() {
                    if (!Array.isArray(state.emailComposer.sections)) {
                        state.emailComposer.sections = [];
                    }

                    state.emailComposer.sections = state.emailComposer.sections
                        .map(normalizeSection)
                        .filter(Boolean);

                    if (state.emailComposer.sections.length === 0) {
                        state.emailComposer.sections = [createSection("text")];
                    }

                    const selectedId = String(state.emailComposer.selectedSectionId || "");
                    const hasSelected = state.emailComposer.sections.some((section) => section.id === selectedId);
                    if (!hasSelected) {
                        state.emailComposer.selectedSectionId = String(state.emailComposer.sections[0]?.id || "");
                    }

                    if (emailTemplateHtml && String(emailTemplateHtml.value || "").trim() === "") {
                        emailTemplateHtml.value = EMAIL_TEMPLATES.simple;
                    }
                }

                function selectedEmailSection() {
                    const selectedId = String(state.emailComposer.selectedSectionId || "");
                    return state.emailComposer.sections.find((section) => String(section.id) === selectedId) || null;
                }

                function moveEmailSection(sectionId, direction) {
                    const id = String(sectionId || "");
                    const index = state.emailComposer.sections.findIndex((section) => String(section.id) === id);
                    if (index < 0) {
                        return;
                    }

                    const targetIndex = direction === "up" ? index - 1 : index + 1;
                    if (targetIndex < 0 || targetIndex >= state.emailComposer.sections.length) {
                        return;
                    }

                    const copy = [...state.emailComposer.sections];
                    const [item] = copy.splice(index, 1);
                    copy.splice(targetIndex, 0, item);
                    state.emailComposer.sections = copy;
                    persistEmailComposerState();
                    renderEmailSectionsList();
                    renderEmailSectionSettings();
                    renderEmailLivePreview();
                    resetGroupPreviewState();
                }

                function removeEmailSection(sectionId) {
                    const id = String(sectionId || "");
                    const next = state.emailComposer.sections.filter((section) => String(section.id) !== id);
                    if (next.length === 0) {
                        state.emailComposer.sections = [createSection("text")];
                    } else {
                        state.emailComposer.sections = next;
                    }

                    state.emailComposer.selectedSectionId = String(state.emailComposer.sections[0]?.id || "");
                    persistEmailComposerState();
                    renderEmailSectionsList();
                    renderEmailSectionSettings();
                    renderEmailLivePreview();
                    resetGroupPreviewState();
                }

                function addEmailSection(type) {
                    const section = createSection(type);
                    state.emailComposer.sections = [...state.emailComposer.sections, section];
                    state.emailComposer.selectedSectionId = String(section.id);
                    persistEmailComposerState();
                    renderEmailSectionsList();
                    renderEmailSectionSettings();
                    renderEmailLivePreview();
                    resetGroupPreviewState();
                }

                function updateSelectedEmailSectionField(field, value) {
                    const selectedId = String(state.emailComposer.selectedSectionId || "");
                    state.emailComposer.sections = state.emailComposer.sections.map((section) => {
                        if (String(section.id) !== selectedId) {
                            return section;
                        }

                        if (field === "height") {
                            const parsed = Number.parseInt(value, 10);
                            return { ...section, [field]: Number.isFinite(parsed) ? Math.max(4, Math.min(parsed, 80)) : 20 };
                        }

                        return { ...section, [field]: value };
                    });

                    persistEmailComposerState();
                    renderEmailSectionsList();
                    renderEmailLivePreview();
                    resetGroupPreviewState();
                }

                function renderEmailSectionsList() {
                    if (!emailSectionsList) {
                        return;
                    }

                    emailSectionsList.innerHTML = "";
                    state.emailComposer.sections.forEach((section, index) => {
                        const li = document.createElement("li");
                        li.className = "messages-email-section-row";
                        const selected = String(section.id) === String(state.emailComposer.selectedSectionId || "");
                        li.setAttribute("data-selected", selected ? "true" : "false");

                        const subtitle = (() => {
                            if (section.type === "image") {
                                return section.imageUrl ? "Image selected" : "No image URL yet";
                            }
                            if (section.type === "product") {
                                return section.title || section.productId || "No product selected";
                            }
                            if (section.type === "button") {
                                return section.label || "Button";
                            }
                            if (section.type === "heading") {
                                return section.text || "Heading";
                            }
                            if (section.type === "text") {
                                return section.html ? "Rich text configured" : "No text yet";
                            }
                            if (section.type === "spacer") {
                                return `Height ${Number(section.height || 20)}px`;
                            }

                            return "Visual separator";
                        })();

                        li.innerHTML = `
                            <div class="messages-email-section-head">
                                <span class="messages-email-section-name">${index + 1}. ${escapeHtml(emailSectionTypeLabel(section.type))}</span>
                                <span class="messages-email-section-actions">
                                    <button type="button" class="messages-email-icon-button" data-email-action="select" data-email-id="${escapeHtml(section.id)}">${selected ? "Selected" : "Edit"}</button>
                                    <button type="button" class="messages-email-icon-button" data-email-action="up" data-email-id="${escapeHtml(section.id)}">Up</button>
                                    <button type="button" class="messages-email-icon-button" data-email-action="down" data-email-id="${escapeHtml(section.id)}">Down</button>
                                    <button type="button" class="messages-email-icon-button messages-email-icon-button--danger" data-email-action="remove" data-email-id="${escapeHtml(section.id)}">Remove</button>
                                </span>
                            </div>
                            <span class="messages-muted">${escapeHtml(String(subtitle || ""))}</span>
                        `;

                        li.addEventListener("click", (event) => {
                            const target = event.target;
                            if (!(target instanceof HTMLElement)) {
                                return;
                            }

                            const action = String(target.getAttribute("data-email-action") || "").trim();
                            const sectionId = String(target.getAttribute("data-email-id") || "").trim();
                            if (sectionId === "") {
                                return;
                            }

                            if (action === "select") {
                                state.emailComposer.selectedSectionId = sectionId;
                                persistEmailComposerState();
                                renderEmailSectionsList();
                                renderEmailSectionSettings();
                                return;
                            }
                            if (action === "up") {
                                moveEmailSection(sectionId, "up");
                                return;
                            }
                            if (action === "down") {
                                moveEmailSection(sectionId, "down");
                                return;
                            }
                            if (action === "remove") {
                                removeEmailSection(sectionId);
                            }
                        });

                        emailSectionsList.appendChild(li);
                    });
                }

                function renderProductSearchResults() {
                    const list = document.getElementById("messages-email-product-results");
                    if (!list) {
                        return;
                    }

                    const rows = Array.isArray(state.emailComposer.productSearchResults)
                        ? state.emailComposer.productSearchResults
                        : [];
                    if (rows.length === 0) {
                        list.hidden = true;
                        list.innerHTML = "";
                        return;
                    }

                    list.hidden = false;
                    list.innerHTML = "";
                    rows.forEach((row, index) => {
                        const button = document.createElement("button");
                        button.type = "button";
                        button.setAttribute("data-email-product-index", String(index));
                        button.innerHTML = `
                            <strong>${escapeHtml(String(row.title || "Product"))}</strong>
                            <span class="messages-muted">${escapeHtml(String(row.price || "Price unavailable"))}</span>
                            <span class="messages-muted">${escapeHtml(String(row.url || ""))}</span>
                        `;

                        const li = document.createElement("li");
                        li.appendChild(button);
                        list.appendChild(li);
                    });
                }

                function renderEmailSectionSettings() {
                    if (!emailSectionSettings) {
                        return;
                    }

                    const section = selectedEmailSection();
                    if (!section) {
                        emailSectionSettings.innerHTML = `<div class="messages-email-settings-empty">Select a section to edit.</div>`;
                        return;
                    }

                    if (section.type === "image") {
                        emailSectionSettings.innerHTML = `
                            <div class="messages-field">
                                <label>Image URL</label>
                                <input type="url" data-section-field="imageUrl" value="${escapeHtml(String(section.imageUrl || ""))}" placeholder="https://..." />
                            </div>
                            <div class="messages-field">
                                <label>Alt text</label>
                                <input type="text" data-section-field="alt" value="${escapeHtml(String(section.alt || ""))}" placeholder="Image description" />
                            </div>
                            <div class="messages-field">
                                <label>Optional link URL</label>
                                <input type="url" data-section-field="href" value="${escapeHtml(String(section.href || ""))}" placeholder="https://..." />
                            </div>
                            <div class="messages-field">
                                <label>Padding</label>
                                <input type="text" data-section-field="padding" value="${escapeHtml(String(section.padding || "12px 0"))}" placeholder="12px 0" />
                            </div>
                        `;
                        return;
                    }

                    if (section.type === "product") {
                        emailSectionSettings.innerHTML = `
                            <div class="messages-field">
                                <label>Find Shopify product</label>
                                <input type="search" id="messages-email-product-search" placeholder="Search by product name" autocomplete="off" />
                                <ul class="messages-email-product-results" id="messages-email-product-results" hidden></ul>
                            </div>
                            <div class="messages-field">
                                <label>Product ID</label>
                                <input type="text" data-section-field="productId" value="${escapeHtml(String(section.productId || ""))}" placeholder="Shopify product id" />
                            </div>
                            <div class="messages-field">
                                <label>Title</label>
                                <input type="text" data-section-field="title" value="${escapeHtml(String(section.title || ""))}" placeholder="Product title" />
                            </div>
                            <div class="messages-field">
                                <label>Image URL</label>
                                <input type="url" data-section-field="imageUrl" value="${escapeHtml(String(section.imageUrl || ""))}" placeholder="https://..." />
                            </div>
                            <div class="messages-field">
                                <label>Price text</label>
                                <input type="text" data-section-field="price" value="${escapeHtml(String(section.price || ""))}" placeholder="$29.00" />
                            </div>
                            <div class="messages-field">
                                <label>Product URL</label>
                                <input type="url" data-section-field="href" value="${escapeHtml(String(section.href || ""))}" placeholder="https://..." />
                            </div>
                            <div class="messages-field">
                                <label>Button label</label>
                                <input type="text" data-section-field="buttonLabel" value="${escapeHtml(String(section.buttonLabel || "View product"))}" placeholder="View product" />
                            </div>
                        `;
                        renderProductSearchResults();
                        return;
                    }

                    if (section.type === "button") {
                        emailSectionSettings.innerHTML = `
                            <div class="messages-field">
                                <label>Label</label>
                                <input type="text" data-section-field="label" value="${escapeHtml(String(section.label || ""))}" placeholder="Shop now" />
                            </div>
                            <div class="messages-field">
                                <label>URL</label>
                                <input type="url" data-section-field="href" value="${escapeHtml(String(section.href || ""))}" placeholder="https://..." />
                            </div>
                            <div class="messages-field">
                                <label>Alignment</label>
                                <select data-section-field="align">
                                    <option value="left" ${String(section.align || "center") === "left" ? "selected" : ""}>Left</option>
                                    <option value="center" ${String(section.align || "center") === "center" ? "selected" : ""}>Center</option>
                                    <option value="right" ${String(section.align || "center") === "right" ? "selected" : ""}>Right</option>
                                </select>
                            </div>
                        `;
                        return;
                    }

                    if (section.type === "heading") {
                        emailSectionSettings.innerHTML = `
                            <div class="messages-field">
                                <label>Heading text</label>
                                <input type="text" data-section-field="text" value="${escapeHtml(String(section.text || ""))}" placeholder="Heading" />
                            </div>
                            <div class="messages-field">
                                <label>Alignment</label>
                                <select data-section-field="align">
                                    <option value="left" ${String(section.align || "left") === "left" ? "selected" : ""}>Left</option>
                                    <option value="center" ${String(section.align || "left") === "center" ? "selected" : ""}>Center</option>
                                    <option value="right" ${String(section.align || "left") === "right" ? "selected" : ""}>Right</option>
                                </select>
                            </div>
                        `;
                        return;
                    }

                    if (section.type === "spacer") {
                        emailSectionSettings.innerHTML = `
                            <div class="messages-field">
                                <label>Height (px)</label>
                                <input type="number" min="4" max="80" data-section-field="height" value="${escapeHtml(String(section.height || 20))}" />
                            </div>
                        `;
                        return;
                    }

                    if (section.type === "divider") {
                        emailSectionSettings.innerHTML = `<div class="messages-email-settings-empty">Divider has no extra settings.</div>`;
                        return;
                    }

                    emailSectionSettings.innerHTML = `
                        <div class="messages-field">
                            <label>Rich text HTML</label>
                            <textarea data-section-field="html" placeholder="<p>Your message here</p>">${escapeHtml(String(section.html || ""))}</textarea>
                        </div>
                    `;
                }

                async function searchShopifyProducts(query) {
                    const q = String(query || "").trim();
                    if (q.length < 2 || !endpoints.search_products) {
                        state.emailComposer.productSearchResults = [];
                        renderProductSearchResults();
                        return;
                    }

                    try {
                        const url = new URL(endpoints.search_products, window.location.origin);
                        url.searchParams.set("q", q);
                        url.searchParams.set("limit", "10");
                        const payload = await fetchJson(url.toString(), { method: "GET" });
                        state.emailComposer.productSearchResults = Array.isArray(payload?.data) ? payload.data : [];
                        renderProductSearchResults();
                    } catch (error) {
                        state.emailComposer.productSearchResults = [];
                        renderProductSearchResults();
                        setAlert(error?.payload?.message || error?.message || "Product search failed.", "error");
                    }
                }

                function applyProductToSelectedSection(product) {
                    const selectedId = String(state.emailComposer.selectedSectionId || "");
                    state.emailComposer.sections = state.emailComposer.sections.map((section) => {
                        if (String(section.id) !== selectedId || String(section.type || "") !== "product") {
                            return section;
                        }

                        return {
                            ...section,
                            productId: String(product?.id || section.productId || ""),
                            title: String(product?.title || section.title || ""),
                            imageUrl: String(product?.image_url || section.imageUrl || ""),
                            price: String(product?.price || section.price || ""),
                            href: String(product?.url || section.href || ""),
                            buttonLabel: String(section.buttonLabel || "View product"),
                        };
                    });

                    state.emailComposer.productSearchResults = [];
                    persistEmailComposerState();
                    renderEmailSectionsList();
                    renderEmailSectionSettings();
                    renderEmailLivePreview();
                    resetGroupPreviewState();
                }

                function currentEmailTemplateHtml() {
                    const kind = String(emailTemplateKind?.value || "simple");
                    const fallback = EMAIL_TEMPLATES.simple;
                    return String(emailTemplateHtml?.value || EMAIL_TEMPLATES[kind] || fallback);
                }

                function currentEmailComposerPayload() {
                    const mode = String(state.emailComposer.mode || "sections") === "legacy_html"
                        ? "legacy_html"
                        : "sections";

                    return {
                        email_template_mode: mode,
                        email_sections: mode === "sections" ? state.emailComposer.sections : null,
                        email_advanced_html: mode === "legacy_html" ? currentEmailTemplateHtml() : null,
                    };
                }

                function emailPayloadHasContentForChannel(channel, messageBody) {
                    const body = String(messageBody || "").trim();
                    if (String(channel || "").toLowerCase() !== "email") {
                        return body !== "";
                    }

                    if (body !== "") {
                        return true;
                    }

                    if (String(state.emailComposer.mode || "sections") === "legacy_html") {
                        return String(currentEmailTemplateHtml() || "").trim() !== "";
                    }

                    return Array.isArray(state.emailComposer.sections) && state.emailComposer.sections.length > 0;
                }

                function safePreviewUrl(url) {
                    const value = String(url || "").trim();
                    if (/^(https?:|mailto:|tel:)/i.test(value)) {
                        return value;
                    }

                    return "";
                }

                function sanitizeRichTextForPreview(html) {
                    const source = String(html || "");
                    const noScripts = source.replace(/<\s*(script|style)[^>]*>.*?<\s*\/\s*\1\s*>/gis, "");
                    const noEvents = noScripts
                        .replace(/\s+on[a-z]+\s*=\s*"[^"]*"/gi, "")
                        .replace(/\s+on[a-z]+\s*=\s*'[^']*'/gi, "")
                        .replace(/\s+on[a-z]+\s*=\s*[^\s>]+/gi, "")
                        .replace(/javascript\s*:/gi, "");

                    return noEvents;
                }

                function renderEmailSectionHtml(section) {
                    if (!section || typeof section !== "object") {
                        return "";
                    }

                    const type = String(section.type || "").trim().toLowerCase();
                    if (type === "image") {
                        const imageUrl = safePreviewUrl(section.imageUrl);
                        if (!imageUrl) {
                            return "";
                        }
                        const alt = escapeHtml(section.alt || "Image");
                        const padding = escapeHtml(section.padding || "12px 0");
                        const href = safePreviewUrl(section.href);
                        const imageTag = `<img src="${escapeHtml(imageUrl)}" alt="${alt}" style="display:block;width:100%;max-width:100%;height:auto;border:0;border-radius:10px;" />`;
                        const wrappedImage = href
                            ? `<a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer" style="text-decoration:none;">${imageTag}</a>`
                            : imageTag;
                        return `<tr><td style="padding:${padding};">${wrappedImage}</td></tr>`;
                    }

                    if (type === "product") {
                        const title = escapeHtml(section.title || "Product");
                        const price = escapeHtml(section.price || "");
                        const href = safePreviewUrl(section.href);
                        const imageUrl = safePreviewUrl(section.imageUrl);
                        const buttonLabel = escapeHtml(section.buttonLabel || "View product");

                        const rows = [];
                        if (imageUrl) {
                            const image = `<img src="${escapeHtml(imageUrl)}" alt="${title}" style="display:block;width:100%;max-width:100%;height:auto;border:0;border-radius:10px;" />`;
                            rows.push(`<tr><td style="padding:0 0 10px 0;">${href ? `<a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer" style="text-decoration:none;">${image}</a>` : image}</td></tr>`);
                        }
                        rows.push(`<tr><td style="padding:0 0 4px 0;font-family:Arial,sans-serif;font-size:18px;font-weight:700;line-height:1.3;color:#0f172a;">${title}</td></tr>`);
                        if (price) {
                            rows.push(`<tr><td style="padding:0 0 10px 0;font-family:Arial,sans-serif;font-size:14px;line-height:1.4;color:#334155;">${price}</td></tr>`);
                        }
                        if (href) {
                            rows.push(`<tr><td><a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#10633f;color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:700;text-decoration:none;padding:10px 16px;border-radius:999px;">${buttonLabel}</a></td></tr>`);
                        }

                        return `<tr><td style="padding:12px 0;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e2e8f0;border-radius:12px;padding:14px;">${rows.join("")}</table></td></tr>`;
                    }

                    if (type === "button") {
                        const href = safePreviewUrl(section.href);
                        if (!href) {
                            return "";
                        }
                        const align = ["left", "center", "right"].includes(String(section.align || "").toLowerCase())
                            ? String(section.align || "").toLowerCase()
                            : "center";
                        const label = escapeHtml(section.label || "Learn more");
                        return `<tr><td style="padding:12px 0;text-align:${align};"><a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#10633f;color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:700;text-decoration:none;padding:10px 16px;border-radius:999px;">${label}</a></td></tr>`;
                    }

                    if (type === "text") {
                        const html = sanitizeRichTextForPreview(section.html || "");
                        if (!String(html || "").trim()) {
                            return "";
                        }
                        return `<tr><td style="padding:0 0 12px 0;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;">${html}</td></tr>`;
                    }

                    if (type === "heading") {
                        const text = escapeHtml(section.text || "Heading");
                        if (!text) {
                            return "";
                        }
                        const align = ["left", "center", "right"].includes(String(section.align || "").toLowerCase())
                            ? String(section.align || "").toLowerCase()
                            : "left";
                        return `<tr><td style="padding:0 0 10px 0;font-family:Arial,sans-serif;font-size:22px;font-weight:700;line-height:1.3;color:#0f172a;text-align:${align};">${text}</td></tr>`;
                    }

                    if (type === "spacer") {
                        const height = Number.parseInt(section.height, 10);
                        const resolvedHeight = Number.isFinite(height) ? Math.max(4, Math.min(height, 80)) : 20;
                        return `<tr><td aria-hidden="true" style="font-size:0;line-height:0;height:${resolvedHeight}px;">&nbsp;</td></tr>`;
                    }

                    if (type === "divider") {
                        return `<tr><td style="padding:10px 0;"><hr style="margin:0;border:0;border-top:1px solid #dbe2ea;" /></td></tr>`;
                    }

                    return "";
                }

                function buildEmailHtml(subject, message) {
                    const safeSubject = escapeHtml(subject || "Message from Backstage");

                    if (String(state.emailComposer.mode || "sections") === "legacy_html") {
                        const messageHtml = `<p>${renderedMessageBodyAsHtml(message)}</p>`;
                        return currentEmailTemplateHtml()
                            .replace(/@{{\s*subject\s*}}/gi, safeSubject)
                            .replace(/@{{\s*message_body\s*}}/gi, messageHtml);
                    }

                    const rows = [
                        `<tr><td style="padding:8px 0 18px 0;"><h1 style="margin:0;font-family:Arial,sans-serif;font-size:24px;line-height:1.25;color:#0f172a;">${safeSubject}</h1></td></tr>`,
                    ];
                    const sections = Array.isArray(state.emailComposer.sections) ? state.emailComposer.sections : [];
                    sections.forEach((section) => {
                        const row = renderEmailSectionHtml(section);
                        if (row) {
                            rows.push(row);
                        }
                    });

                    const hasBodySection = sections.some((section) => {
                        const type = String(section?.type || "").toLowerCase();
                        return type === "text" || type === "heading";
                    });
                    if (!hasBodySection && String(message || "").trim() !== "") {
                        rows.push(`<tr><td style="padding:0 0 12px 0;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;">${renderedMessageBodyAsHtml(message)}</td></tr>`);
                    }

                    if (rows.length === 1) {
                        rows.push(`<tr><td style="padding:0 0 12px 0;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;">Your email content will appear here.</td></tr>`);
                    }

                    return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#f3f4f6;padding:18px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #dbe2ea;border-radius:12px;padding:22px;">${rows.join("")}</table></td></tr></table></body></html>`;
                }

                function renderEmailLivePreview() {
                    if (!emailLivePreview) {
                        return;
                    }

                    const subject = String(groupSubject?.value || "Message from Backstage");
                    const message = String(groupBody?.value || "");
                    emailLivePreview.srcdoc = buildEmailHtml(subject, message);
                }

                function renderGroupPreview(payload) {
                    if (!groupPreviewWrap || !groupPreviewSummary || !groupPreviewBody) {
                        return;
                    }

                    const targetName = String(payload?.target?.name || state.selectedTarget?.name || "Selected group");
                    const channel = String(payload?.channel || state.groupChannel || "sms").toLowerCase();
                    const recipients = Number(payload?.estimated_recipients || 0);

                    groupPreviewWrap.hidden = false;
                    groupPreviewSummary.textContent = `${targetName} · ${channel.toUpperCase()} · ${recipients.toLocaleString()} recipients`;

                    if (channel === "email") {
                        const subject = String(payload?.subject || groupSubject?.value || "");
                        const message = String(payload?.body || groupBody?.value || "");
                        const html = String(payload?.email_html || "").trim() !== ""
                            ? String(payload.email_html)
                            : buildEmailHtml(subject, message);
                        groupPreviewBody.innerHTML = `
                            <div class="messages-muted">Subject: ${escapeHtml(subject || "(No subject)")}</div>
                            <iframe class="messages-email-preview-frame" sandbox="allow-same-origin" title="Email preview confirmation"></iframe>
                        `;
                        const frame = groupPreviewBody.querySelector("iframe");
                        if (frame) {
                            frame.srcdoc = html;
                        }
                        return;
                    }

                    const bodyText = String(payload?.body || groupBody?.value || "");
                    groupPreviewBody.innerHTML = `<div class="messages-sms-preview">${escapeHtml(bodyText)}</div>`;
                }

                function renderIndividualCustomer() {
                    if (!individualCustomer) {
                        return;
                    }

                    const customer = state.selectedCustomer;
                    if (!customer) {
                        individualCustomer.innerHTML = `
                            <h3>No customer selected</h3>
                            <p class="messages-muted">Select a customer to start a direct message.</p>
                        `;
                        return;
                    }

                    individualCustomer.innerHTML = `
                        <h3>${escapeHtml(customer.name || "Customer")}</h3>
                        <p class="messages-muted">Email: ${escapeHtml(customer.email || "Not set")}</p>
                        <p class="messages-muted">Phone: ${escapeHtml(customer.phone || "Not set")}</p>
                        <div class="messages-status-row">
                            <span class="messages-pill">SMS ${customer.sms_contactable ? "contactable" : "not contactable"}</span>
                            <span class="messages-pill">Email ${customer.email_contactable ? "contactable" : "not contactable"}</span>
                        </div>
                    `;
                }

                function renderIndividualPreview() {
                    if (!individualPreviewBox || !individualPreviewSummary || !individualPreviewBody) {
                        return;
                    }

                    if (!state.individualPreviewReady) {
                        individualPreviewBox.hidden = true;
                        individualPreviewSummary.textContent = "";
                        individualPreviewBody.innerHTML = "";
                        return;
                    }

                    const customer = state.selectedCustomer || {};
                    const channel = String(individualChannel?.value || "sms");
                    const body = String(individualBody?.value || "");
                    individualPreviewBox.hidden = false;
                    individualPreviewSummary.textContent = `${customer.name || "Customer"} · ${channel.toUpperCase()}`;

                    if (channel === "email") {
                        const subject = String(individualSubject?.value || "");
                        const html = buildEmailHtml(subject, body);
                        individualPreviewBody.innerHTML = `
                            <div class="messages-muted">Subject: ${escapeHtml(subject || "(No subject)")}</div>
                            <iframe class="messages-email-preview-frame" sandbox="allow-same-origin" title="Individual email preview"></iframe>
                        `;
                        const frame = individualPreviewBody.querySelector("iframe");
                        if (frame) {
                            frame.srcdoc = html;
                        }
                        return;
                    }

                    individualPreviewBody.innerHTML = `<div class="messages-sms-preview">${escapeHtml(body)}</div>`;
                }

                function renderHistory() {
                    if (!historyList) {
                        return;
                    }

                    historyList.innerHTML = "";
                    if (!Array.isArray(state.history) || state.history.length === 0) {
                        const li = document.createElement("li");
                        li.className = "messages-muted";
                        li.textContent = "No messaging history yet.";
                        historyList.appendChild(li);
                        return;
                    }

                    state.history.forEach((row) => {
                        const li = document.createElement("li");
                        li.innerHTML = `
                            <strong style="font-size:13px;color:#0f172a;">${escapeHtml(String(row.channel || "message").toUpperCase())} · ${escapeHtml(String(row.profile_name || "Customer"))}</strong>
                            <span class="messages-muted">${escapeHtml(String(row.recipient || ""))}</span>
                            <span class="messages-muted">Status: ${escapeHtml(String(row.status || "sent"))}</span>
                            <span class="messages-muted">${escapeHtml(String(row.message_preview || ""))}</span>
                            <span class="messages-muted">${escapeHtml(formatDate(row.sent_at))}</span>
                        `;
                        historyList.appendChild(li);
                    });
                }

                function formatDate(value) {
                    if (!value) {
                        return "Sent time unavailable";
                    }
                    const date = new Date(value);
                    if (Number.isNaN(date.getTime())) {
                        return String(value);
                    }
                    return date.toLocaleString();
                }

                async function refreshBootstrap() {
                    if (!endpoints.bootstrap) {
                        return;
                    }

                    const payload = await fetchJson(endpoints.bootstrap, { method: "GET" });
                    const data = typeof payload?.data === "object" && payload.data !== null
                        ? payload.data
                        : {};
                    state.groups = typeof data.groups === "object" && data.groups !== null
                        ? data.groups
                        : { saved: [], auto: [] };

                    renderAudienceList();
                    renderGroupEstimate();
                }

                async function refreshAudienceSummary() {
                    if (!endpoints.audience_summary) {
                        return;
                    }

                    const payload = await fetchJson(endpoints.audience_summary, { method: "GET" });
                    const data = typeof payload?.data === "object" && payload.data !== null
                        ? payload.data
                        : {};

                    state.audienceSummary = typeof data.all_subscribed_summary === "object" && data.all_subscribed_summary !== null
                        ? data.all_subscribed_summary
                        : { sms: 0, email: 0, overlap: 0, unique: 0 };
                    state.groupSummaries = typeof data.group_summaries === "object" && data.group_summaries !== null
                        ? data.group_summaries
                        : {};
                    state.audienceDiagnostics = typeof data.diagnostics === "object" && data.diagnostics !== null
                        ? data.diagnostics
                        : {};

                    renderAudiencePills();
                    renderAudienceDiagnostics();
                    renderAudienceList();
                    renderGroupEstimate();
                }

                async function refreshHistory() {
                    if (!endpoints.history) {
                        return;
                    }

                    try {
                        const payload = await fetchJson(`${endpoints.history}?limit=40`, { method: "GET" });
                        state.history = Array.isArray(payload?.data) ? payload.data : [];
                        state.historyLoaded = true;
                        renderHistory();
                    } catch (error) {
                        setAlert(error?.payload?.message || error?.message || "History could not be loaded.", "error");
                    }
                }

                async function searchCustomers(query) {
                    const q = String(query || "").trim();
                    if (q.length < 2 || !endpoints.search_customers) {
                        return [];
                    }

                    const url = new URL(endpoints.search_customers, window.location.origin);
                    url.searchParams.set("q", q);
                    url.searchParams.set("limit", "12");

                    const payload = await fetchJson(url.toString(), { method: "GET" });
                    return Array.isArray(payload?.data) ? payload.data : [];
                }

                function renderSearchResults(container, rows, onSelect) {
                    if (!container) {
                        return;
                    }

                    container.innerHTML = "";
                    if (!Array.isArray(rows) || rows.length === 0) {
                        container.hidden = true;
                        return;
                    }

                    rows.forEach((row) => {
                        const customer = normalizedCustomer(row);
                        if (!customer) {
                            return;
                        }

                        const li = document.createElement("li");
                        const button = document.createElement("button");
                        button.type = "button";
                        button.innerHTML = `
                            <strong>${escapeHtml(customer.name)}</strong>
                            <span>${escapeHtml(customer.email || customer.phone || "No contact details")}</span>
                        `;
                        button.addEventListener("click", () => onSelect(customer));
                        li.appendChild(button);
                        container.appendChild(li);
                    });

                    container.hidden = container.children.length === 0;
                }

                function renderGroupMembers() {
                    if (!groupMembersWrap) {
                        return;
                    }

                    groupMembersWrap.innerHTML = "";
                    if (state.groupMembers.size === 0) {
                        const span = document.createElement("span");
                        span.className = "messages-muted";
                        span.textContent = "No members selected.";
                        groupMembersWrap.appendChild(span);
                        return;
                    }

                    state.groupMembers.forEach((customer, id) => {
                        const chip = document.createElement("span");
                        chip.className = "messages-member-chip";
                        chip.innerHTML = `${escapeHtml(customer.name || `Customer #${id}`)} <button type="button" aria-label="Remove">Remove</button>`;
                        const remove = chip.querySelector("button");
                        if (remove) {
                            remove.addEventListener("click", () => {
                                state.groupMembers.delete(id);
                                renderGroupMembers();
                            });
                        }
                        groupMembersWrap.appendChild(chip);
                    });
                }

                function resetGroupEditor() {
                    state.editingGroupId = null;
                    state.groupMembers.clear();
                    if (groupName) {
                        groupName.value = "";
                    }
                    if (groupDescription) {
                        groupDescription.value = "";
                    }
                    renderGroupMembers();
                    setInlineStatus(groupStatus, "");
                }

                async function openSavedGroupForEditing(groupId) {
                    if (!endpoints.group_detail_base) {
                        return;
                    }

                    try {
                        setInlineStatus(groupStatus, "Loading group...");
                        const endpoint = replaceGroupEndpoint(endpoints.group_detail_base, groupId);
                        const payload = await fetchJson(endpoint, { method: "GET" });
                        const group = payload?.data || {};

                        state.groupMembers.clear();
                        const members = Array.isArray(group.members) ? group.members : [];
                        members.forEach((row) => {
                            const customer = normalizedCustomer(row);
                            if (customer) {
                                state.groupMembers.set(customer.id, customer);
                            }
                        });

                        state.editingGroupId = Number(group.id || 0) || null;
                        if (groupName) {
                            groupName.value = String(group.name || "");
                        }
                        if (groupDescription) {
                            groupDescription.value = String(group.description || "");
                        }

                        renderGroupMembers();
                        setInlineStatus(groupStatus, "Group loaded.");
                    } catch (error) {
                        setInlineStatus(groupStatus, error?.payload?.message || error?.message || "Could not load group.");
                    }
                }

                async function saveGroup() {
                    const name = String(groupName?.value || "").trim();
                    const description = String(groupDescription?.value || "").trim();
                    const memberIds = Array.from(state.groupMembers.keys());

                    if (name === "") {
                        setInlineStatus(groupStatus, "Group name is required.");
                        return;
                    }

                    if (memberIds.length === 0) {
                        setInlineStatus(groupStatus, "Choose at least one member.");
                        return;
                    }

                    const isUpdate = Number.isFinite(state.editingGroupId) && state.editingGroupId > 0;
                    const endpoint = isUpdate
                        ? replaceGroupEndpoint(endpoints.update_group_base, state.editingGroupId)
                        : endpoints.create_group;

                    if (!endpoint) {
                        setInlineStatus(groupStatus, "Group endpoint is unavailable.");
                        return;
                    }

                    try {
                        groupSave.disabled = true;
                        setInlineStatus(groupStatus, "Saving group...");
                        const response = await fetchJson(endpoint, {
                            method: isUpdate ? "PATCH" : "POST",
                            body: JSON.stringify({
                                name,
                                description: description || null,
                                member_profile_ids: memberIds,
                            }),
                        });

                        setInlineStatus(groupStatus, response?.message || "Group saved.");
                        await refreshBootstrap();
                    } catch (error) {
                        setInlineStatus(groupStatus, error?.payload?.message || error?.message || "Group save failed.");
                    } finally {
                        groupSave.disabled = false;
                    }
                }

                function openGroupEditor() {
                    if (!groupEditor) {
                        return;
                    }
                    groupEditor.hidden = false;
                    if (state.selectedTarget?.type === "saved" && Number(state.selectedTarget.id || 0) > 0) {
                        openSavedGroupForEditing(Number(state.selectedTarget.id));
                    }
                }

                function closeGroupEditor() {
                    if (!groupEditor) {
                        return;
                    }
                    groupEditor.hidden = true;
                    setInlineStatus(groupStatus, "");
                }

                async function previewGroupSend() {
                    if (!state.selectedTarget) {
                        setInlineStatus(groupSendStatus, "Choose a group before preview.");
                        return;
                    }

                    const body = String(groupBody?.value || "").trim();
                    if (state.groupChannel === "sms" && body === "") {
                        setInlineStatus(groupSendStatus, "Message body is required.");
                        return;
                    }

                    const subject = String(groupSubject?.value || "").trim();
                    if (state.groupChannel === "email" && subject === "") {
                        setInlineStatus(groupSendStatus, "Email subject is required.");
                        return;
                    }
                    if (state.groupChannel === "email" && !emailPayloadHasContentForChannel("email", body)) {
                        setInlineStatus(groupSendStatus, "Add message text, sections, or advanced HTML before preview.");
                        return;
                    }

                    if (!endpoints.preview_group) {
                        setInlineStatus(groupSendStatus, "Preview endpoint is unavailable.");
                        return;
                    }

                    const composerPayload = currentEmailComposerPayload();
                    const payload = {
                        target_type: state.selectedTarget.type,
                        group_id: state.selectedTarget.type === "saved" ? state.selectedTarget.id : null,
                        group_key: state.selectedTarget.type === "auto" ? state.selectedTarget.key : null,
                        channel: state.groupChannel,
                        subject: state.groupChannel === "email" ? subject : null,
                        body,
                        email_template_mode: state.groupChannel === "email" ? composerPayload.email_template_mode : null,
                        email_sections: state.groupChannel === "email" ? composerPayload.email_sections : null,
                        email_advanced_html: state.groupChannel === "email" ? composerPayload.email_advanced_html : null,
                    };

                    try {
                        groupPreviewButton.disabled = true;
                        setInlineStatus(groupSendStatus, "Building preview...");
                        const response = await fetchJson(endpoints.preview_group, {
                            method: "POST",
                            body: JSON.stringify(payload),
                        });

                        state.groupPreviewPayload = response?.data || null;
                        state.previewReady = true;

                        renderGroupPreview(state.groupPreviewPayload || payload);
                        renderGroupEstimate();
                        setInlineStatus(groupSendStatus, "Preview ready. Confirm to send.");

                        if (groupSendButton) {
                            groupSendButton.hidden = false;
                        }
                        if (groupPreviewBack) {
                            groupPreviewBack.hidden = false;
                        }
                        if (groupPreviewButton) {
                            groupPreviewButton.hidden = true;
                        }
                    } catch (error) {
                        setInlineStatus(groupSendStatus, error?.payload?.message || error?.message || "Preview failed.");
                    } finally {
                        groupPreviewButton.disabled = false;
                    }
                }

                async function confirmGroupSend() {
                    if (!state.selectedTarget || !state.previewReady) {
                        setInlineStatus(groupSendStatus, "Run preview before sending.");
                        return;
                    }

                    if (!endpoints.send_group) {
                        setInlineStatus(groupSendStatus, "Send endpoint is unavailable.");
                        return;
                    }

                    const body = String(groupBody?.value || "").trim();
                    const subject = String(groupSubject?.value || "").trim();
                    const senderKey = String(groupSender?.value || "").trim();
                    if (state.groupChannel === "email" && !emailPayloadHasContentForChannel("email", body)) {
                        setInlineStatus(groupSendStatus, "Add message text, sections, or advanced HTML before sending.");
                        return;
                    }

                    const composerPayload = currentEmailComposerPayload();
                    const payload = {
                        target_type: state.selectedTarget.type,
                        group_id: state.selectedTarget.type === "saved" ? state.selectedTarget.id : null,
                        group_key: state.selectedTarget.type === "auto" ? state.selectedTarget.key : null,
                        channel: state.groupChannel,
                        subject: state.groupChannel === "email" ? subject : null,
                        body,
                        sender_key: state.groupChannel === "sms" ? (senderKey || null) : null,
                        email_template_mode: state.groupChannel === "email" ? composerPayload.email_template_mode : null,
                        email_sections: state.groupChannel === "email" ? composerPayload.email_sections : null,
                        email_advanced_html: state.groupChannel === "email" ? composerPayload.email_advanced_html : null,
                    };

                    try {
                        groupSendButton.disabled = true;
                        setInlineStatus(groupSendStatus, "Sending message...");
                        const response = await fetchJson(endpoints.send_group, {
                            method: "POST",
                            body: JSON.stringify(payload),
                        });

                        setInlineStatus(groupSendStatus, response?.message || "Message sent.");
                        setAlert("Group message sent.", "success");

                        if (groupBody) {
                            groupBody.value = "";
                        }
                        if (groupSubject) {
                            groupSubject.value = "";
                        }

                        resetGroupPreviewState();
                        await refreshAudienceSummary();
                        if (state.tab === "history") {
                            await refreshHistory();
                        }
                    } catch (error) {
                        setInlineStatus(groupSendStatus, error?.payload?.message || error?.message || "Send failed.");
                    } finally {
                        groupSendButton.disabled = false;
                    }
                }

                function resetIndividualPreviewState() {
                    state.individualPreviewReady = false;
                    if (individualSend) {
                        individualSend.hidden = true;
                    }
                    if (individualPreviewBack) {
                        individualPreviewBack.hidden = true;
                    }
                    if (individualPreview) {
                        individualPreview.hidden = false;
                    }
                    renderIndividualPreview();
                }

                function validateIndividualPayload() {
                    const customer = state.selectedCustomer;
                    const channel = String(individualChannel?.value || "sms").toLowerCase();
                    const subject = String(individualSubject?.value || "").trim();
                    const body = String(individualBody?.value || "").trim();

                    if (!customer || !Number.isFinite(customer.id)) {
                        return { ok: false, message: "Select a customer first." };
                    }

                    if (channel === "sms" && !customer.sms_contactable) {
                        return { ok: false, message: "Selected customer is not SMS contactable." };
                    }

                    if (channel === "email" && !customer.email_contactable) {
                        return { ok: false, message: "Selected customer is not email contactable." };
                    }

                    if (channel === "email" && subject === "") {
                        return { ok: false, message: "Email subject is required." };
                    }

                    if (!emailPayloadHasContentForChannel(channel, body)) {
                        return channel === "email"
                            ? { ok: false, message: "Add message text, sections, or advanced HTML." }
                            : { ok: false, message: "Message body is required." };
                    }

                    const composerPayload = currentEmailComposerPayload();

                    return {
                        ok: true,
                        payload: {
                            profile_id: customer.id,
                            channel,
                            subject: channel === "email" ? subject : null,
                            body,
                            sender_key: String(individualSender?.value || "").trim() || null,
                            email_template_mode: channel === "email" ? composerPayload.email_template_mode : null,
                            email_sections: channel === "email" ? composerPayload.email_sections : null,
                            email_advanced_html: channel === "email" ? composerPayload.email_advanced_html : null,
                        },
                    };
                }

                function previewIndividualMessage() {
                    const validation = validateIndividualPayload();
                    if (!validation.ok) {
                        setInlineStatus(individualStatus, validation.message || "Preview failed.");
                        return;
                    }

                    state.individualPreviewReady = true;
                    renderIndividualPreview();
                    setInlineStatus(individualStatus, "Preview ready. Confirm to send.");

                    if (individualSend) {
                        individualSend.hidden = false;
                    }
                    if (individualPreviewBack) {
                        individualPreviewBack.hidden = false;
                    }
                    if (individualPreview) {
                        individualPreview.hidden = true;
                    }
                }

                async function confirmIndividualSend() {
                    const validation = validateIndividualPayload();
                    if (!validation.ok) {
                        setInlineStatus(individualStatus, validation.message || "Send failed.");
                        return;
                    }

                    if (!state.individualPreviewReady) {
                        setInlineStatus(individualStatus, "Run preview before sending.");
                        return;
                    }

                    if (!endpoints.send_individual) {
                        setInlineStatus(individualStatus, "Send endpoint is unavailable.");
                        return;
                    }

                    try {
                        individualSend.disabled = true;
                        setInlineStatus(individualStatus, "Sending message...");
                        const response = await fetchJson(endpoints.send_individual, {
                            method: "POST",
                            body: JSON.stringify(validation.payload),
                        });

                        setInlineStatus(individualStatus, response?.message || "Message sent.");
                        setAlert("Individual message sent.", "success");
                        resetIndividualPreviewState();
                        if (individualBody) {
                            individualBody.value = "";
                        }
                        if (individualSubject) {
                            individualSubject.value = "";
                        }
                        if (state.tab === "history") {
                            await refreshHistory();
                        }
                    } catch (error) {
                        setInlineStatus(individualStatus, error?.payload?.message || error?.message || "Send failed.");
                    } finally {
                        individualSend.disabled = false;
                    }
                }

                function bindEvents() {
                    tabButtons.forEach((button) => {
                        button.addEventListener("click", () => switchTab(String(button.dataset.tabButton || "groups")));
                    });

                    const groupButtons = Array.from(groupChannelToggle?.querySelectorAll("button[data-group-channel]") || []);
                    groupButtons.forEach((button) => {
                        button.addEventListener("click", () => {
                            state.groupChannel = String(button.dataset.groupChannel || "sms");
                            updateGroupChannelUi();
                            renderEmailLivePreview();
                        });
                    });

                    if (groupBody) {
                        groupBody.addEventListener("input", debounce(() => {
                            resetGroupPreviewState();
                            renderEmailLivePreview();
                        }, 180));
                    }
                    if (groupSubject) {
                        groupSubject.addEventListener("input", debounce(() => {
                            resetGroupPreviewState();
                            renderEmailLivePreview();
                        }, 180));
                    }

                    emailAddSectionButtons.forEach((button) => {
                        button.addEventListener("click", () => {
                            const type = String(button.getAttribute("data-email-add-section") || "").trim().toLowerCase();
                            addEmailSection(type || "text");
                        });
                    });

                    if (emailAdvancedToggle) {
                        emailAdvancedToggle.addEventListener("click", () => {
                            const nextIsLegacy = String(state.emailComposer.mode || "sections") !== "legacy_html";
                            state.emailComposer.mode = nextIsLegacy ? "legacy_html" : "sections";
                            if (emailAdvancedPanel) {
                                emailAdvancedPanel.hidden = !nextIsLegacy;
                            }
                            persistEmailComposerState();
                            renderEmailLivePreview();
                            resetGroupPreviewState();
                        });
                    }

                    if (emailUseSections) {
                        emailUseSections.addEventListener("click", () => {
                            state.emailComposer.mode = "sections";
                            if (emailAdvancedPanel) {
                                emailAdvancedPanel.hidden = true;
                            }
                            persistEmailComposerState();
                            renderEmailLivePreview();
                            resetGroupPreviewState();
                        });
                    }

                    if (emailTemplateKind) {
                        emailTemplateKind.addEventListener("change", () => {
                            const preset = EMAIL_TEMPLATES[String(emailTemplateKind.value || "simple")] || EMAIL_TEMPLATES.simple;
                            if (emailTemplateHtml) {
                                emailTemplateHtml.value = preset;
                            }
                            state.emailComposer.mode = "legacy_html";
                            if (emailAdvancedPanel) {
                                emailAdvancedPanel.hidden = false;
                            }
                            persistEmailComposerState();
                            renderEmailLivePreview();
                            resetGroupPreviewState();
                        });
                    }

                    if (emailTemplateHtml) {
                        emailTemplateHtml.addEventListener("input", debounce(() => {
                            persistEmailComposerState();
                            renderEmailLivePreview();
                            resetGroupPreviewState();
                        }, 180));
                    }

                    const debouncedProductSearch = debounce((query) => {
                        searchShopifyProducts(query);
                    }, 260);

                    if (emailSectionSettings) {
                        emailSectionSettings.addEventListener("input", (event) => {
                            const target = event.target;
                            if (!(target instanceof HTMLElement)) {
                                return;
                            }

                            if (target.id === "messages-email-product-search" && target instanceof HTMLInputElement) {
                                debouncedProductSearch(target.value);
                                return;
                            }

                            const field = String(target.getAttribute("data-section-field") || "").trim();
                            if (field === "") {
                                return;
                            }

                            if (
                                target instanceof HTMLInputElement
                                || target instanceof HTMLTextAreaElement
                                || target instanceof HTMLSelectElement
                            ) {
                                updateSelectedEmailSectionField(field, target.value);
                            }
                        });

                        emailSectionSettings.addEventListener("change", (event) => {
                            const target = event.target;
                            if (!(target instanceof HTMLElement)) {
                                return;
                            }

                            const field = String(target.getAttribute("data-section-field") || "").trim();
                            if (field === "") {
                                return;
                            }

                            if (
                                target instanceof HTMLInputElement
                                || target instanceof HTMLTextAreaElement
                                || target instanceof HTMLSelectElement
                            ) {
                                updateSelectedEmailSectionField(field, target.value);
                            }
                        });

                        emailSectionSettings.addEventListener("click", (event) => {
                            const target = event.target;
                            if (!(target instanceof HTMLElement)) {
                                return;
                            }

                            const button = target.closest("button[data-email-product-index]");
                            if (!(button instanceof HTMLElement)) {
                                return;
                            }

                            const index = Number.parseInt(String(button.getAttribute("data-email-product-index") || "-1"), 10);
                            if (!Number.isFinite(index) || index < 0) {
                                return;
                            }

                            const product = state.emailComposer.productSearchResults[index];
                            if (product) {
                                applyProductToSelectedSection(product);
                            }
                        });
                    }

                    if (groupPreviewButton) {
                        groupPreviewButton.addEventListener("click", previewGroupSend);
                    }
                    if (groupPreviewBack) {
                        groupPreviewBack.addEventListener("click", () => {
                            resetGroupPreviewState();
                            setInlineStatus(groupSendStatus, "Returned to edit mode.");
                        });
                    }
                    if (groupSendButton) {
                        groupSendButton.addEventListener("click", confirmGroupSend);
                    }

                    if (groupEditorOpen) {
                        groupEditorOpen.addEventListener("click", openGroupEditor);
                    }
                    if (groupEditorClose) {
                        groupEditorClose.addEventListener("click", closeGroupEditor);
                    }
                    if (groupReset) {
                        groupReset.addEventListener("click", resetGroupEditor);
                    }
                    if (groupSave) {
                        groupSave.addEventListener("click", saveGroup);
                    }

                    if (groupMemberSearch) {
                        groupMemberSearch.addEventListener("input", debounce(async () => {
                            try {
                                const rows = await searchCustomers(groupMemberSearch.value);
                                renderSearchResults(groupMemberResults, rows, (customer) => {
                                    state.groupMembers.set(customer.id, customer);
                                    renderGroupMembers();
                                    groupMemberSearch.value = "";
                                    groupMemberResults.hidden = true;
                                });
                            } catch (error) {
                                groupMemberResults.hidden = true;
                            }
                        }, 240));
                    }

                    if (individualSearch) {
                        individualSearch.addEventListener("input", debounce(async () => {
                            try {
                                const rows = await searchCustomers(individualSearch.value);
                                renderSearchResults(individualResults, rows, (customer) => {
                                    state.selectedCustomer = customer;
                                    individualSearch.value = customer.name || "";
                                    individualResults.hidden = true;
                                    renderIndividualCustomer();
                                    resetIndividualPreviewState();
                                });
                            } catch (error) {
                                individualResults.hidden = true;
                            }
                        }, 240));
                    }

                    if (individualChannel) {
                        individualChannel.addEventListener("change", () => {
                            individualSubjectWrap.hidden = individualChannel.value !== "email";
                            resetIndividualPreviewState();
                        });
                    }

                    if (individualBody) {
                        individualBody.addEventListener("input", () => resetIndividualPreviewState());
                    }
                    if (individualSubject) {
                        individualSubject.addEventListener("input", () => resetIndividualPreviewState());
                    }

                    if (individualPreview) {
                        individualPreview.addEventListener("click", previewIndividualMessage);
                    }
                    if (individualPreviewBack) {
                        individualPreviewBack.addEventListener("click", () => {
                            resetIndividualPreviewState();
                            setInlineStatus(individualStatus, "Returned to edit mode.");
                        });
                    }
                    if (individualSend) {
                        individualSend.addEventListener("click", confirmIndividualSend);
                    }
                }

                async function initialize() {
                    hydrateEmailComposerState();
                    ensureEmailComposerState();
                    bindEvents();

                    if (emailTemplateHtml && emailTemplateHtml.value.trim() === "") {
                        emailTemplateHtml.value = EMAIL_TEMPLATES.simple;
                    }
                    if (emailAdvancedPanel) {
                        emailAdvancedPanel.hidden = String(state.emailComposer.mode || "sections") !== "legacy_html";
                    }
                    if (emailEditor) {
                        emailEditor.hidden = false;
                    }

                    renderAudiencePills();
                    renderAudienceDiagnostics();
                    renderAudienceList();
                    renderSelectedTargetSummary();
                    renderGroupEstimate();
                    renderGroupMembers();
                    renderEmailSectionsList();
                    renderEmailSectionSettings();
                    renderIndividualCustomer();
                    resetGroupPreviewState();
                    resetIndividualPreviewState();
                    updateGroupChannelUi();
                    renderEmailLivePreview();
                    switchTab("groups");

                    try {
                        await refreshBootstrap();
                        await refreshAudienceSummary();
                    } catch (error) {
                        setAlert(error?.payload?.message || error?.message || "Unable to load messages workspace.", "error");
                    }
                }

                initialize();
            })();
        </script>
    @endif
</x-shopify-embedded-shell>
