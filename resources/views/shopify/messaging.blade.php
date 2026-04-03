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
        .messaging-root {
            display: grid;
            gap: 14px;
            max-width: 1240px;
            width: 100%;
            margin: 0 auto;
        }

        .messaging-card {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.05);
            padding: 16px;
            display: grid;
            gap: 12px;
        }

        .messaging-card h2 {
            margin: 0;
            font-size: 1.03rem;
            font-weight: 700;
            color: #0f172a;
        }

        .messaging-card p {
            margin: 0;
            font-size: 13px;
            line-height: 1.55;
            color: rgba(15, 23, 42, 0.66);
        }

        .messaging-card[data-tone="error"] {
            border-color: rgba(180, 35, 24, 0.22);
            background: rgba(180, 35, 24, 0.06);
        }

        .messaging-card[data-tone="success"] {
            border-color: rgba(15, 118, 110, 0.22);
            background: rgba(15, 118, 110, 0.08);
        }

        .messaging-segmented {
            display: inline-flex;
            gap: 8px;
            flex-wrap: wrap;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.94);
            padding: 6px;
            align-self: flex-start;
        }

        .messaging-segmented button {
            border: 0;
            background: transparent;
            color: rgba(15, 23, 42, 0.72);
            border-radius: 999px;
            min-height: 34px;
            padding: 0 12px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.03em;
            cursor: pointer;
        }

        .messaging-segmented button[aria-selected="true"] {
            background: rgba(15, 143, 97, 0.14);
            color: #0f6f4c;
        }

        .messaging-grid {
            display: grid;
            gap: 12px;
        }

        .messaging-grid--individuals {
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr);
        }

        .messaging-grid--groups {
            grid-template-columns: minmax(260px, 0.8fr) minmax(0, 1.3fr);
        }

        .messaging-field {
            display: grid;
            gap: 6px;
            position: relative;
        }

        .messaging-field label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.5);
        }

        .messaging-field input,
        .messaging-field select,
        .messaging-field textarea {
            width: 100%;
            box-sizing: border-box;
            min-height: 42px;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.13);
            background: #fff;
            color: rgba(15, 23, 42, 0.9);
            padding: 10px 12px;
            font-size: 14px;
            transition: border-color 0.16s ease, box-shadow 0.16s ease;
        }

        .messaging-field textarea {
            min-height: 124px;
            resize: vertical;
            line-height: 1.5;
        }

        .messaging-field input:focus,
        .messaging-field select:focus,
        .messaging-field textarea:focus {
            outline: none;
            border-color: rgba(15, 143, 97, 0.38);
            box-shadow: 0 0 0 4px rgba(15, 143, 97, 0.1);
        }

        .messaging-helper {
            font-size: 12px;
            color: rgba(15, 23, 42, 0.58);
            line-height: 1.5;
        }

        .messaging-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .messaging-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #fff;
            color: rgba(15, 23, 42, 0.82);
            min-height: 38px;
            padding: 0 14px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.03em;
            cursor: pointer;
            transition: border-color 0.16s ease, background 0.16s ease, color 0.16s ease;
        }

        .messaging-button:hover:not(:disabled) {
            border-color: rgba(15, 23, 42, 0.22);
            color: rgba(15, 23, 42, 0.96);
        }

        .messaging-button:disabled {
            opacity: 0.62;
            cursor: not-allowed;
        }

        .messaging-button--primary {
            border-color: rgba(15, 143, 97, 0.34);
            background: rgba(15, 143, 97, 0.12);
            color: #0f6f4c;
        }

        .messaging-results {
            position: absolute;
            z-index: 8;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            max-height: 260px;
            overflow: auto;
            list-style: none;
            margin: 0;
            padding: 6px;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #fff;
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.12);
            display: grid;
            gap: 4px;
        }

        .messaging-results[hidden] {
            display: none;
        }

        .messaging-result-button {
            width: 100%;
            text-align: left;
            border: 0;
            border-radius: 10px;
            background: rgba(248, 250, 252, 0.78);
            color: rgba(15, 23, 42, 0.86);
            padding: 9px 10px;
            display: grid;
            gap: 3px;
            cursor: pointer;
        }

        .messaging-result-button strong {
            font-size: 13px;
            font-weight: 700;
        }

        .messaging-result-button span {
            font-size: 12px;
            color: rgba(15, 23, 42, 0.62);
        }

        .messaging-result-button:hover {
            background: rgba(15, 143, 97, 0.12);
            color: #0f6f4c;
        }

        .messaging-customer-card {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.92);
            padding: 12px;
            display: grid;
            gap: 6px;
        }

        .messaging-customer-card h3 {
            margin: 0;
            font-size: 15px;
            color: #0f172a;
        }

        .messaging-customer-meta {
            display: grid;
            gap: 4px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.66);
        }

        .messaging-status-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .messaging-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #fff;
            color: rgba(15, 23, 42, 0.74);
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 650;
        }

        .messaging-pill[data-tone="ok"] {
            border-color: rgba(15, 118, 110, 0.24);
            background: rgba(15, 118, 110, 0.1);
            color: #0f766e;
        }

        .messaging-pill[data-tone="warn"] {
            border-color: rgba(146, 64, 14, 0.25);
            background: rgba(217, 119, 6, 0.12);
            color: #92400e;
        }

        .messaging-list {
            display: grid;
            gap: 8px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .messaging-list button {
            width: 100%;
            text-align: left;
            border-radius: 10px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.92);
            color: rgba(15, 23, 42, 0.84);
            padding: 10px;
            display: grid;
            gap: 3px;
            cursor: pointer;
        }

        .messaging-list button[aria-current="true"] {
            border-color: rgba(15, 143, 97, 0.35);
            background: rgba(15, 143, 97, 0.13);
            color: #0f6f4c;
        }

        .messaging-members {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .messaging-member-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(248, 250, 252, 0.95);
            padding: 5px 8px;
            font-size: 11px;
            color: rgba(15, 23, 42, 0.8);
        }

        .messaging-member-chip button {
            border: 0;
            background: transparent;
            color: rgba(15, 23, 42, 0.56);
            cursor: pointer;
            font-size: 11px;
            padding: 0;
        }

        .messaging-history {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }

        .messaging-history li {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(255, 255, 255, 0.95);
            padding: 12px;
            display: grid;
            gap: 5px;
        }

        .messaging-history strong {
            font-size: 13px;
            color: #0f172a;
        }

        .messaging-history small {
            font-size: 12px;
            color: rgba(15, 23, 42, 0.62);
        }

        .messaging-muted {
            color: rgba(15, 23, 42, 0.56);
            font-size: 12px;
            line-height: 1.45;
        }

        [hidden] {
            display: none !important;
        }

        @media (max-width: 980px) {
            .messaging-grid--individuals,
            .messaging-grid--groups {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>

    <section class="messaging-root" id="messaging-root">
        @if(is_array($messagingModuleState))
            <x-tenancy.module-state-card
                :module-state="$messagingModuleState"
                title="Messaging module state"
                description="Visibility and access follow tenant entitlement + module-state conventions."
            />
        @endif

        <article class="messaging-card" id="messaging-global-alert" hidden></article>

        @if(! $authorized)
            <article class="messaging-card">
                <h2>Messaging requires Shopify context</h2>
                <p>Open this page from Shopify Admin so Backstage can verify the store session and tenant scope.</p>
            </article>
        @elseif(! $messagingEnabled)
            <article class="messaging-card" data-tone="error">
                <h2>Messaging is locked</h2>
                <p>{{ $messagingMessage !== '' ? $messagingMessage : 'Messaging is not enabled for this tenant.' }}</p>
                @if($messagingStatus !== '')
                    <p class="messaging-muted">Status: {{ $messagingStatus }}</p>
                @endif
            </article>
        @else
            <nav class="messaging-segmented" aria-label="Messaging workspace tabs">
                <button type="button" data-tab-button="individuals" aria-selected="true">Individuals</button>
                <button type="button" data-tab-button="groups" aria-selected="false">Groups</button>
                <button type="button" data-tab-button="history" aria-selected="false">History</button>
            </nav>

            <section class="messaging-grid messaging-grid--individuals" data-tab-panel="individuals">
                <article class="messaging-card">
                    <h2>Select Customer</h2>
                    <p class="messaging-helper">Search by name, email, or phone using the same customer lookup behavior as Customers.</p>

                    <div class="messaging-field">
                        <label for="messaging-individual-search">Customer search</label>
                        <input
                            id="messaging-individual-search"
                            type="search"
                            autocomplete="off"
                            placeholder="Search customer"
                        />
                        <ul class="messaging-results" id="messaging-individual-results" hidden></ul>
                    </div>

                    <div class="messaging-customer-card" id="messaging-selected-customer">
                        <h3>No customer selected</h3>
                        <p class="messaging-muted">Select a customer to start a direct message.</p>
                    </div>
                </article>

                <article class="messaging-card">
                    <h2>Compose Message</h2>
                    <div class="messaging-field">
                        <label for="messaging-individual-channel">Channel</label>
                        <select id="messaging-individual-channel">
                            <option value="sms">SMS</option>
                            <option value="email">Email</option>
                        </select>
                    </div>

                    <div class="messaging-field" id="messaging-individual-subject-wrap" hidden>
                        <label for="messaging-individual-subject">Email subject</label>
                        <input id="messaging-individual-subject" type="text" maxlength="200" placeholder="Subject" />
                    </div>

                    <div class="messaging-field">
                        <label for="messaging-individual-body">Message body</label>
                        <textarea id="messaging-individual-body" maxlength="5000" placeholder="Write your message"></textarea>
                    </div>

                    <div class="messaging-field">
                        <label for="messaging-individual-sender">SMS sender key (optional)</label>
                        <input id="messaging-individual-sender" type="text" maxlength="80" placeholder="default sender" />
                    </div>

                    <div class="messaging-actions">
                        <button class="messaging-button messaging-button--primary" id="messaging-individual-send" type="button">
                            Send Message
                        </button>
                    </div>
                    <p class="messaging-muted" id="messaging-individual-status"></p>
                </article>
            </section>

            <section class="messaging-grid messaging-grid--groups" data-tab-panel="groups" hidden>
                <article class="messaging-card">
                    <h2>Groups</h2>
                    <p class="messaging-helper">Open a saved group or use the auto audience below.</p>

                    <div>
                        <strong class="messaging-muted">Saved groups</strong>
                        <ul class="messaging-list" id="messaging-saved-groups"></ul>
                    </div>

                    <div>
                        <strong class="messaging-muted">Automatic audiences</strong>
                        <ul class="messaging-list" id="messaging-auto-groups"></ul>
                    </div>
                </article>

                <article class="messaging-card">
                    <h2>Create or Edit Group</h2>
                    <div class="messaging-field">
                        <label for="messaging-group-name">Group name</label>
                        <input id="messaging-group-name" type="text" maxlength="120" placeholder="VIP customers" />
                    </div>
                    <div class="messaging-field">
                        <label for="messaging-group-description">Description (optional)</label>
                        <input id="messaging-group-description" type="text" maxlength="500" placeholder="Internal notes" />
                    </div>
                    <div class="messaging-field">
                        <label for="messaging-group-member-search">Add members</label>
                        <input id="messaging-group-member-search" type="search" autocomplete="off" placeholder="Search customer" />
                        <ul class="messaging-results" id="messaging-group-member-results" hidden></ul>
                    </div>
                    <div class="messaging-members" id="messaging-group-members"></div>
                    <div class="messaging-actions">
                        <button class="messaging-button messaging-button--primary" id="messaging-group-save" type="button">Save Group</button>
                        <button class="messaging-button" id="messaging-group-reset" type="button">New Group</button>
                    </div>
                    <p class="messaging-muted" id="messaging-group-status"></p>

                    <hr style="border:0;height:1px;background:rgba(15,23,42,0.08);margin:2px 0;">

                    <h2>Send to Group</h2>
                    <p class="messaging-muted" id="messaging-target-summary">Select a saved or automatic group target.</p>
                    <div class="messaging-field">
                        <label for="messaging-group-channel">Channel</label>
                        <select id="messaging-group-channel">
                            <option value="sms">SMS</option>
                            <option value="email">Email</option>
                        </select>
                    </div>
                    <div class="messaging-field" id="messaging-group-subject-wrap" hidden>
                        <label for="messaging-group-subject">Email subject</label>
                        <input id="messaging-group-subject" type="text" maxlength="200" placeholder="Subject" />
                    </div>
                    <div class="messaging-field">
                        <label for="messaging-group-body">Message body</label>
                        <textarea id="messaging-group-body" maxlength="5000" placeholder="Write your message"></textarea>
                    </div>
                    <div class="messaging-field">
                        <label for="messaging-group-sender">SMS sender key (optional)</label>
                        <input id="messaging-group-sender" type="text" maxlength="80" placeholder="default sender" />
                    </div>
                    <div class="messaging-actions">
                        <button class="messaging-button messaging-button--primary" id="messaging-group-send" type="button">Send Group Message</button>
                    </div>
                    <p class="messaging-muted" id="messaging-group-send-status"></p>
                </article>
            </section>

            <section data-tab-panel="history" hidden>
                <article class="messaging-card">
                    <h2>Recent Messaging</h2>
                    <p class="messaging-helper">Recent sends from this embedded messaging workspace.</p>
                    <ul class="messaging-history" id="messaging-history-list"></ul>
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

                const state = {
                    tab: "individuals",
                    groups: typeof initialData.groups === "object" && initialData.groups !== null
                        ? initialData.groups
                        : { saved: [], auto: [] },
                    history: Array.isArray(initialData.history) ? initialData.history : [],
                    selectedCustomer: null,
                    groupMembers: new Map(),
                    editingGroupId: null,
                    selectedTarget: null,
                };

                const alertCard = document.getElementById("messaging-global-alert");
                const tabButtons = Array.from(document.querySelectorAll("[data-tab-button]"));
                const tabPanels = Array.from(document.querySelectorAll("[data-tab-panel]"));

                const individualSearchInput = document.getElementById("messaging-individual-search");
                const individualResults = document.getElementById("messaging-individual-results");
                const selectedCustomerCard = document.getElementById("messaging-selected-customer");
                const individualChannel = document.getElementById("messaging-individual-channel");
                const individualSubjectWrap = document.getElementById("messaging-individual-subject-wrap");
                const individualSubject = document.getElementById("messaging-individual-subject");
                const individualBody = document.getElementById("messaging-individual-body");
                const individualSender = document.getElementById("messaging-individual-sender");
                const individualSend = document.getElementById("messaging-individual-send");
                const individualStatus = document.getElementById("messaging-individual-status");

                const savedGroupsList = document.getElementById("messaging-saved-groups");
                const autoGroupsList = document.getElementById("messaging-auto-groups");
                const groupNameInput = document.getElementById("messaging-group-name");
                const groupDescriptionInput = document.getElementById("messaging-group-description");
                const groupMemberSearchInput = document.getElementById("messaging-group-member-search");
                const groupMemberResults = document.getElementById("messaging-group-member-results");
                const groupMembersWrap = document.getElementById("messaging-group-members");
                const groupSaveButton = document.getElementById("messaging-group-save");
                const groupResetButton = document.getElementById("messaging-group-reset");
                const groupStatus = document.getElementById("messaging-group-status");

                const targetSummary = document.getElementById("messaging-target-summary");
                const groupChannel = document.getElementById("messaging-group-channel");
                const groupSubjectWrap = document.getElementById("messaging-group-subject-wrap");
                const groupSubject = document.getElementById("messaging-group-subject");
                const groupBody = document.getElementById("messaging-group-body");
                const groupSender = document.getElementById("messaging-group-sender");
                const groupSendButton = document.getElementById("messaging-group-send");
                const groupSendStatus = document.getElementById("messaging-group-send-status");

                const historyList = document.getElementById("messaging-history-list");

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

                function setInlineStatus(element, message) {
                    if (!element) {
                        return;
                    }
                    element.textContent = typeof message === "string" ? message : "";
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
                }

                function syncSubjectVisibility(channelSelect, subjectWrap) {
                    if (!channelSelect || !subjectWrap) {
                        return;
                    }
                    subjectWrap.hidden = channelSelect.value !== "email";
                }

                function renderCustomerCard() {
                    if (!selectedCustomerCard) {
                        return;
                    }
                    const customer = state.selectedCustomer;
                    if (!customer) {
                        selectedCustomerCard.innerHTML = `
                            <h3>No customer selected</h3>
                            <p class="messaging-muted">Select a customer to start a direct message.</p>
                        `;
                        return;
                    }

                    const smsTone = customer.sms_contactable ? "ok" : "warn";
                    const emailTone = customer.email_contactable ? "ok" : "warn";
                    selectedCustomerCard.innerHTML = `
                        <h3>${escapeHtml(customer.name || "Customer")}</h3>
                        <div class="messaging-customer-meta">
                            <span>Email: ${escapeHtml(customer.email || "Not set")}</span>
                            <span>Phone: ${escapeHtml(customer.phone || "Not set")}</span>
                        </div>
                        <div class="messaging-status-pills">
                            <span class="messaging-pill" data-tone="${smsTone}">SMS ${customer.sms_contactable ? "contactable" : "not contactable"}</span>
                            <span class="messaging-pill" data-tone="${emailTone}">Email ${customer.email_contactable ? "contactable" : "not contactable"}</span>
                        </div>
                    `;
                }

                function normalizeCustomer(row) {
                    if (!row || typeof row !== "object") {
                        return null;
                    }

                    const id = Number.parseInt(row.id, 10);
                    if (!Number.isFinite(id) || id <= 0) {
                        return null;
                    }

                    return {
                        id,
                        name: (row.name || `Customer #${id}`).toString(),
                        email: row.email ? row.email.toString() : null,
                        phone: row.phone ? row.phone.toString() : null,
                        accepts_sms_marketing: Boolean(row.accepts_sms_marketing),
                        accepts_email_marketing: Boolean(row.accepts_email_marketing),
                        sms_contactable: Boolean(row.sms_contactable),
                        email_contactable: Boolean(row.email_contactable),
                    };
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
                        const customer = normalizeCustomer(row);
                        if (!customer) {
                            return;
                        }

                        const li = document.createElement("li");
                        const button = document.createElement("button");
                        button.type = "button";
                        button.className = "messaging-result-button";
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

                function escapeHtml(value) {
                    return String(value ?? "")
                        .replace(/&/g, "&amp;")
                        .replace(/</g, "&lt;")
                        .replace(/>/g, "&gt;")
                        .replace(/"/g, "&quot;")
                        .replace(/'/g, "&#039;");
                }

                function debounce(fn, wait = 220) {
                    let timer = null;
                    return (...args) => {
                        if (timer) {
                            window.clearTimeout(timer);
                        }
                        timer = window.setTimeout(() => fn(...args), wait);
                    };
                }

                async function searchCustomers(query) {
                    const q = (query || "").trim();
                    if (q.length < 2 || !endpoints.search_customers) {
                        return [];
                    }

                    const url = new URL(endpoints.search_customers, window.location.origin);
                    url.searchParams.set("q", q);
                    url.searchParams.set("limit", "12");

                    const payload = await fetchJson(url.toString(), { method: "GET" });
                    return Array.isArray(payload?.data) ? payload.data : [];
                }

                function replaceGroupEndpoint(base, groupId) {
                    return String(base || "").replace("__GROUP__", encodeURIComponent(String(groupId)));
                }

                async function refreshBootstrap() {
                    if (!endpoints.bootstrap) {
                        return;
                    }
                    const payload = await fetchJson(endpoints.bootstrap, { method: "GET" });
                    const data = typeof payload?.data === "object" && payload.data !== null ? payload.data : {};
                    state.groups = typeof data.groups === "object" && data.groups !== null
                        ? data.groups
                        : { saved: [], auto: [] };
                    state.history = Array.isArray(data.history) ? data.history : [];
                    renderGroups();
                    renderHistory();
                }

                async function refreshHistory() {
                    if (!endpoints.history) {
                        return;
                    }
                    const payload = await fetchJson(`${endpoints.history}?limit=40`, { method: "GET" });
                    state.history = Array.isArray(payload?.data) ? payload.data : [];
                    renderHistory();
                }

                function renderGroups() {
                    if (savedGroupsList) {
                        savedGroupsList.innerHTML = "";
                        const savedRows = Array.isArray(state.groups?.saved) ? state.groups.saved : [];
                        if (savedRows.length === 0) {
                            const li = document.createElement("li");
                            li.className = "messaging-muted";
                            li.textContent = "No saved groups yet.";
                            savedGroupsList.appendChild(li);
                        } else {
                            savedRows.forEach((group) => {
                                const li = document.createElement("li");
                                const button = document.createElement("button");
                                button.type = "button";
                                button.setAttribute("aria-current", state.selectedTarget?.type === "saved" && state.selectedTarget?.id === group.id ? "true" : "false");
                                button.innerHTML = `
                                    <strong>${escapeHtml(group.name || "Group")}</strong>
                                    <span class="messaging-muted">${Number(group.members_count || 0)} members</span>
                                `;
                                button.addEventListener("click", () => openSavedGroup(group.id));
                                li.appendChild(button);
                                savedGroupsList.appendChild(li);
                            });
                        }
                    }

                    if (autoGroupsList) {
                        autoGroupsList.innerHTML = "";
                        const autoRows = Array.isArray(state.groups?.auto) ? state.groups.auto : [];
                        autoRows.forEach((group) => {
                            const counts = group?.counts || {};
                            const li = document.createElement("li");
                            const button = document.createElement("button");
                            const active = state.selectedTarget?.type === "auto" && state.selectedTarget?.key === group.key;
                            button.type = "button";
                            button.setAttribute("aria-current", active ? "true" : "false");
                            button.innerHTML = `
                                <strong>${escapeHtml(group.name || "Automatic group")}</strong>
                                <span class="messaging-muted">SMS: ${Number(counts.sms || 0)} · Email: ${Number(counts.email || 0)} · Unique: ${Number(counts.unique || 0)}</span>
                            `;
                            button.addEventListener("click", () => {
                                state.selectedTarget = {
                                    type: "auto",
                                    key: String(group.key || "all_subscribed"),
                                    name: String(group.name || "All Subscribed"),
                                };
                                renderGroups();
                                renderTargetSummary();
                            });
                            li.appendChild(button);
                            autoGroupsList.appendChild(li);
                        });
                    }

                    renderTargetSummary();
                }

                function renderGroupMembers() {
                    if (!groupMembersWrap) {
                        return;
                    }

                    groupMembersWrap.innerHTML = "";
                    if (state.groupMembers.size === 0) {
                        const empty = document.createElement("span");
                        empty.className = "messaging-muted";
                        empty.textContent = "No members selected.";
                        groupMembersWrap.appendChild(empty);
                        return;
                    }

                    state.groupMembers.forEach((customer, id) => {
                        const chip = document.createElement("span");
                        chip.className = "messaging-member-chip";
                        chip.innerHTML = `
                            ${escapeHtml(customer.name || `Customer #${id}`)}
                            <button type="button" aria-label="Remove ${escapeHtml(customer.name || `Customer ${id}`)}">Remove</button>
                        `;
                        const removeButton = chip.querySelector("button");
                        if (removeButton) {
                            removeButton.addEventListener("click", () => {
                                state.groupMembers.delete(id);
                                renderGroupMembers();
                            });
                        }
                        groupMembersWrap.appendChild(chip);
                    });
                }

                function renderTargetSummary() {
                    if (!targetSummary) {
                        return;
                    }
                    if (!state.selectedTarget) {
                        targetSummary.textContent = "Select a saved or automatic group target.";
                        return;
                    }
                    if (state.selectedTarget.type === "saved") {
                        targetSummary.textContent = `Target: ${state.selectedTarget.name || "Saved group"} (saved group)`;
                        return;
                    }
                    targetSummary.textContent = `Target: ${state.selectedTarget.name || "All Subscribed"} (automatic audience)`;
                }

                function renderHistory() {
                    if (!historyList) {
                        return;
                    }
                    historyList.innerHTML = "";
                    if (!Array.isArray(state.history) || state.history.length === 0) {
                        const row = document.createElement("li");
                        row.className = "messaging-muted";
                        row.textContent = "No messaging history yet.";
                        historyList.appendChild(row);
                        return;
                    }

                    state.history.forEach((row) => {
                        const item = document.createElement("li");
                        const channel = String(row.channel || "message").toUpperCase();
                        const recipient = String(row.recipient || "Recipient");
                        const name = String(row.profile_name || "Customer");
                        const preview = String(row.message_preview || "");
                        const sentAt = String(row.sent_at || "");
                        const status = String(row.status || "sent");
                        item.innerHTML = `
                            <strong>${escapeHtml(channel)} · ${escapeHtml(name)}</strong>
                            <small>${escapeHtml(recipient)}</small>
                            <small>Status: ${escapeHtml(status)}</small>
                            <small>${escapeHtml(preview)}</small>
                            <small>${escapeHtml(formatDate(sentAt))}</small>
                        `;
                        historyList.appendChild(item);
                    });
                }

                function formatDate(value) {
                    if (!value) {
                        return "Sent time unavailable";
                    }
                    const date = new Date(value);
                    if (Number.isNaN(date.getTime())) {
                        return value;
                    }
                    return date.toLocaleString();
                }

                function resetGroupEditor() {
                    state.editingGroupId = null;
                    state.groupMembers.clear();
                    if (groupNameInput) {
                        groupNameInput.value = "";
                    }
                    if (groupDescriptionInput) {
                        groupDescriptionInput.value = "";
                    }
                    renderGroupMembers();
                    setInlineStatus(groupStatus, "");
                }

                async function openSavedGroup(groupId) {
                    if (!endpoints.group_detail_base) {
                        return;
                    }
                    try {
                        setInlineStatus(groupStatus, "Loading group…");
                        const endpoint = replaceGroupEndpoint(endpoints.group_detail_base, groupId);
                        const payload = await fetchJson(endpoint, { method: "GET" });
                        const group = payload?.data || {};
                        const members = Array.isArray(group.members) ? group.members : [];
                        state.groupMembers.clear();
                        members.forEach((row) => {
                            const customer = normalizeCustomer(row);
                            if (customer) {
                                state.groupMembers.set(customer.id, customer);
                            }
                        });

                        state.editingGroupId = Number.parseInt(group.id, 10) || null;
                        if (groupNameInput) {
                            groupNameInput.value = String(group.name || "");
                        }
                        if (groupDescriptionInput) {
                            groupDescriptionInput.value = String(group.description || "");
                        }
                        state.selectedTarget = {
                            type: "saved",
                            id: state.editingGroupId,
                            name: String(group.name || "Saved group"),
                        };
                        renderGroupMembers();
                        renderGroups();
                        setInlineStatus(groupStatus, "Group loaded.");
                    } catch (error) {
                        const payload = error?.payload || null;
                        setInlineStatus(groupStatus, payload?.message || error?.message || "Failed to load group.");
                    }
                }

                async function saveGroup() {
                    const name = (groupNameInput?.value || "").trim();
                    const description = (groupDescriptionInput?.value || "").trim();
                    const memberIds = Array.from(state.groupMembers.keys());

                    if (name === "") {
                        setInlineStatus(groupStatus, "Group name is required.");
                        return;
                    }
                    if (memberIds.length === 0) {
                        setInlineStatus(groupStatus, "Choose at least one group member.");
                        return;
                    }

                    const payload = {
                        name,
                        description: description || null,
                        member_profile_ids: memberIds,
                    };

                    const isUpdate = Number.isFinite(state.editingGroupId) && state.editingGroupId > 0;
                    const endpoint = isUpdate
                        ? replaceGroupEndpoint(endpoints.update_group_base, state.editingGroupId)
                        : endpoints.create_group;

                    if (!endpoint) {
                        setInlineStatus(groupStatus, "Group endpoint is unavailable.");
                        return;
                    }

                    groupSaveButton.disabled = true;
                    setInlineStatus(groupStatus, "Saving group…");
                    try {
                        const response = await fetchJson(endpoint, {
                            method: isUpdate ? "PATCH" : "POST",
                            body: JSON.stringify(payload),
                        });
                        const group = response?.data || {};
                        const groupId = Number.parseInt(group.id, 10);
                        if (Number.isFinite(groupId) && groupId > 0) {
                            state.editingGroupId = groupId;
                            state.selectedTarget = {
                                type: "saved",
                                id: groupId,
                                name: String(group.name || name),
                            };
                        }
                        await refreshBootstrap();
                        setInlineStatus(groupStatus, response?.message || "Group saved.");
                    } catch (error) {
                        const payloadData = error?.payload || null;
                        setInlineStatus(groupStatus, payloadData?.message || error?.message || "Failed to save group.");
                    } finally {
                        groupSaveButton.disabled = false;
                    }
                }

                async function sendIndividualMessage() {
                    const customer = state.selectedCustomer;
                    const channel = (individualChannel?.value || "sms").toLowerCase();
                    const subject = (individualSubject?.value || "").trim();
                    const body = (individualBody?.value || "").trim();
                    const senderKey = (individualSender?.value || "").trim();

                    if (!customer || !Number.isFinite(customer.id)) {
                        setInlineStatus(individualStatus, "Select a customer first.");
                        return;
                    }
                    if (channel === "sms" && !customer.sms_contactable) {
                        setInlineStatus(individualStatus, "Selected customer is not SMS contactable.");
                        return;
                    }
                    if (channel === "email" && !customer.email_contactable) {
                        setInlineStatus(individualStatus, "Selected customer is not email contactable.");
                        return;
                    }
                    if (channel === "email" && subject === "") {
                        setInlineStatus(individualStatus, "Email subject is required.");
                        return;
                    }
                    if (body === "") {
                        setInlineStatus(individualStatus, "Message body is required.");
                        return;
                    }
                    if (!endpoints.send_individual) {
                        setInlineStatus(individualStatus, "Send endpoint is unavailable.");
                        return;
                    }

                    individualSend.disabled = true;
                    setInlineStatus(individualStatus, "Sending message…");
                    try {
                        const response = await fetchJson(endpoints.send_individual, {
                            method: "POST",
                            body: JSON.stringify({
                                profile_id: customer.id,
                                channel,
                                subject: channel === "email" ? subject : null,
                                body,
                                sender_key: senderKey || null,
                            }),
                        });
                        setInlineStatus(individualStatus, response?.message || "Message sent.");
                        individualBody.value = "";
                        if (individualSubject) {
                            individualSubject.value = "";
                        }
                        await refreshHistory();
                        setAlert("Individual message sent.", "success");
                    } catch (error) {
                        const payload = error?.payload || null;
                        setInlineStatus(individualStatus, payload?.message || error?.message || "Failed to send message.");
                    } finally {
                        individualSend.disabled = false;
                    }
                }

                async function sendGroupMessage() {
                    if (!state.selectedTarget) {
                        setInlineStatus(groupSendStatus, "Choose a target group first.");
                        return;
                    }

                    const channel = (groupChannel?.value || "sms").toLowerCase();
                    const subject = (groupSubject?.value || "").trim();
                    const body = (groupBody?.value || "").trim();
                    const senderKey = (groupSender?.value || "").trim();

                    if (channel === "email" && subject === "") {
                        setInlineStatus(groupSendStatus, "Email subject is required.");
                        return;
                    }
                    if (body === "") {
                        setInlineStatus(groupSendStatus, "Message body is required.");
                        return;
                    }
                    if (!endpoints.send_group) {
                        setInlineStatus(groupSendStatus, "Send endpoint is unavailable.");
                        return;
                    }

                    const payload = {
                        target_type: state.selectedTarget.type,
                        group_id: state.selectedTarget.type === "saved" ? state.selectedTarget.id : null,
                        group_key: state.selectedTarget.type === "auto" ? state.selectedTarget.key : null,
                        channel,
                        subject: channel === "email" ? subject : null,
                        body,
                        sender_key: senderKey || null,
                    };

                    groupSendButton.disabled = true;
                    setInlineStatus(groupSendStatus, "Sending group message…");
                    try {
                        const response = await fetchJson(endpoints.send_group, {
                            method: "POST",
                            body: JSON.stringify(payload),
                        });
                        setInlineStatus(groupSendStatus, response?.message || "Group message sent.");
                        if (groupBody) {
                            groupBody.value = "";
                        }
                        if (groupSubject) {
                            groupSubject.value = "";
                        }
                        await refreshBootstrap();
                        setAlert("Group message sent.", "success");
                    } catch (error) {
                        const payloadData = error?.payload || null;
                        setInlineStatus(groupSendStatus, payloadData?.message || error?.message || "Failed to send message.");
                    } finally {
                        groupSendButton.disabled = false;
                    }
                }

                function bindEvents() {
                    tabButtons.forEach((button) => {
                        button.addEventListener("click", () => switchTab(button.dataset.tabButton));
                    });

                    if (individualChannel) {
                        individualChannel.addEventListener("change", () => syncSubjectVisibility(individualChannel, individualSubjectWrap));
                    }
                    if (groupChannel) {
                        groupChannel.addEventListener("change", () => syncSubjectVisibility(groupChannel, groupSubjectWrap));
                    }

                    if (individualSearchInput) {
                        individualSearchInput.addEventListener("input", debounce(async () => {
                            try {
                                const rows = await searchCustomers(individualSearchInput.value);
                                renderSearchResults(individualResults, rows, (customer) => {
                                    state.selectedCustomer = customer;
                                    renderCustomerCard();
                                    individualSearchInput.value = customer.name || "";
                                    individualResults.hidden = true;
                                });
                            } catch (error) {
                                individualResults.hidden = true;
                            }
                        }, 240));
                    }

                    if (groupMemberSearchInput) {
                        groupMemberSearchInput.addEventListener("input", debounce(async () => {
                            try {
                                const rows = await searchCustomers(groupMemberSearchInput.value);
                                renderSearchResults(groupMemberResults, rows, (customer) => {
                                    state.groupMembers.set(customer.id, customer);
                                    renderGroupMembers();
                                    groupMemberSearchInput.value = "";
                                    groupMemberResults.hidden = true;
                                });
                            } catch (error) {
                                groupMemberResults.hidden = true;
                            }
                        }, 240));
                    }

                    if (individualSend) {
                        individualSend.addEventListener("click", sendIndividualMessage);
                    }
                    if (groupSaveButton) {
                        groupSaveButton.addEventListener("click", saveGroup);
                    }
                    if (groupResetButton) {
                        groupResetButton.addEventListener("click", resetGroupEditor);
                    }
                    if (groupSendButton) {
                        groupSendButton.addEventListener("click", sendGroupMessage);
                    }
                }

                function initializeDefaultTarget() {
                    const autoRows = Array.isArray(state.groups?.auto) ? state.groups.auto : [];
                    if (autoRows.length > 0) {
                        const group = autoRows[0];
                        state.selectedTarget = {
                            type: "auto",
                            key: String(group.key || "all_subscribed"),
                            name: String(group.name || "All Subscribed"),
                        };
                    }
                }

                async function initialize() {
                    bindEvents();
                    syncSubjectVisibility(individualChannel, individualSubjectWrap);
                    syncSubjectVisibility(groupChannel, groupSubjectWrap);
                    renderCustomerCard();
                    renderGroupMembers();
                    initializeDefaultTarget();
                    renderGroups();
                    renderHistory();
                    switchTab("individuals");

                    try {
                        await refreshBootstrap();
                    } catch (error) {
                        const payload = error?.payload || null;
                        setAlert(payload?.message || error?.message || "Unable to load messaging workspace.", "error");
                    }
                }

                initialize();
            })();
        </script>
    @endif
</x-shopify-embedded-shell>
