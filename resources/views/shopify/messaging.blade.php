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
                            <div class="messages-inline-actions">
                                <button type="button" class="messages-button" id="messages-email-editor-toggle">Open email template editor</button>
                            </div>
                            <section class="messages-card" id="messages-email-editor" hidden>
                                <div class="messages-field">
                                    <label for="messages-email-template-kind">Template preset</label>
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
                    emailEditorOpen: false,
                    editingGroupId: null,
                    groupMembers: new Map(),
                    selectedCustomer: null,
                    individualPreviewReady: false,
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
                const emailEditorToggle = document.getElementById("messages-email-editor-toggle");
                const emailEditor = document.getElementById("messages-email-editor");
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
                        state.emailEditorOpen = false;
                        if (emailEditor) {
                            emailEditor.hidden = true;
                        }
                        if (emailEditorToggle) {
                            emailEditorToggle.textContent = "Open email template editor";
                        }
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

                function currentEmailTemplateHtml() {
                    const kind = String(emailTemplateKind?.value || "simple");
                    const fallback = EMAIL_TEMPLATES.simple;
                    return String(emailTemplateHtml?.value || EMAIL_TEMPLATES[kind] || fallback);
                }

                function buildEmailHtml(subject, message) {
                    const safeSubject = escapeHtml(subject || "Message from Backstage");
                    const messageHtml = `<p>${renderedMessageBodyAsHtml(message)}</p>`;
                    return currentEmailTemplateHtml()
                        .replace(/@{{\s*subject\s*}}/gi, safeSubject)
                        .replace(/@{{\s*message_body\s*}}/gi, messageHtml);
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
                        const html = buildEmailHtml(subject, message);
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
                        individualPreviewBody.innerHTML = `
                            <div class="messages-muted">Subject: ${escapeHtml(subject || "(No subject)")}</div>
                            <div class="messages-sms-preview">${escapeHtml(body)}</div>
                        `;
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
                    if (body === "") {
                        setInlineStatus(groupSendStatus, "Message body is required.");
                        return;
                    }

                    const subject = String(groupSubject?.value || "").trim();
                    if (state.groupChannel === "email" && subject === "") {
                        setInlineStatus(groupSendStatus, "Email subject is required.");
                        return;
                    }

                    if (!endpoints.preview_group) {
                        setInlineStatus(groupSendStatus, "Preview endpoint is unavailable.");
                        return;
                    }

                    const payload = {
                        target_type: state.selectedTarget.type,
                        group_id: state.selectedTarget.type === "saved" ? state.selectedTarget.id : null,
                        group_key: state.selectedTarget.type === "auto" ? state.selectedTarget.key : null,
                        channel: state.groupChannel,
                        subject: state.groupChannel === "email" ? subject : null,
                        body,
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

                    const payload = {
                        target_type: state.selectedTarget.type,
                        group_id: state.selectedTarget.type === "saved" ? state.selectedTarget.id : null,
                        group_key: state.selectedTarget.type === "auto" ? state.selectedTarget.key : null,
                        channel: state.groupChannel,
                        subject: state.groupChannel === "email" ? subject : null,
                        body,
                        sender_key: state.groupChannel === "sms" ? (senderKey || null) : null,
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

                    if (body === "") {
                        return { ok: false, message: "Message body is required." };
                    }

                    return {
                        ok: true,
                        payload: {
                            profile_id: customer.id,
                            channel,
                            subject: channel === "email" ? subject : null,
                            body,
                            sender_key: String(individualSender?.value || "").trim() || null,
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

                    if (emailEditorToggle) {
                        emailEditorToggle.addEventListener("click", () => {
                            state.emailEditorOpen = !state.emailEditorOpen;
                            if (emailEditor) {
                                emailEditor.hidden = !state.emailEditorOpen;
                            }
                            emailEditorToggle.textContent = state.emailEditorOpen
                                ? "Hide email template editor"
                                : "Open email template editor";
                            if (state.emailEditorOpen) {
                                renderEmailLivePreview();
                            }
                        });
                    }

                    if (emailTemplateKind) {
                        emailTemplateKind.addEventListener("change", () => {
                            const preset = EMAIL_TEMPLATES[String(emailTemplateKind.value || "simple")] || EMAIL_TEMPLATES.simple;
                            if (emailTemplateHtml) {
                                emailTemplateHtml.value = preset;
                            }
                            renderEmailLivePreview();
                            resetGroupPreviewState();
                        });
                    }

                    if (emailTemplateHtml) {
                        emailTemplateHtml.addEventListener("input", debounce(() => {
                            renderEmailLivePreview();
                            resetGroupPreviewState();
                        }, 180));
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
                    bindEvents();

                    if (emailTemplateHtml && emailTemplateHtml.value.trim() === "") {
                        emailTemplateHtml.value = EMAIL_TEMPLATES.simple;
                    }

                    renderAudiencePills();
                    renderAudienceDiagnostics();
                    renderAudienceList();
                    renderSelectedTargetSummary();
                    renderGroupEstimate();
                    renderGroupMembers();
                    renderIndividualCustomer();
                    resetGroupPreviewState();
                    resetIndividualPreviewState();
                    updateGroupChannelUi();
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
