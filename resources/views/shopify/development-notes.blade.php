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
    <style>
        .devnotes-shell {
            width: 100%;
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            gap: 14px;
        }

        .devnotes-alert {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(248, 250, 252, 0.94);
            color: rgba(15, 23, 42, 0.82);
            padding: 12px 14px;
            font-size: 13px;
            line-height: 1.55;
        }

        .devnotes-alert[data-tone="error"] {
            border-color: rgba(180, 35, 24, 0.24);
            background: rgba(180, 35, 24, 0.08);
            color: #b42318;
        }

        .devnotes-alert[data-tone="success"] {
            border-color: rgba(15, 118, 110, 0.24);
            background: rgba(15, 118, 110, 0.1);
            color: #0f766e;
        }

        .devnotes-alert[hidden] {
            display: none;
        }

        .devnotes-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: start;
        }

        .devnotes-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
            padding: 16px;
            display: grid;
            gap: 12px;
        }

        .devnotes-card h2,
        .devnotes-card p {
            margin: 0;
        }

        .devnotes-card p {
            color: rgba(15, 23, 42, 0.7);
            font-size: 13px;
            line-height: 1.6;
        }

        .devnotes-field {
            display: grid;
            gap: 6px;
        }

        .devnotes-field label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(15, 23, 42, 0.54);
            font-weight: 700;
        }

        .devnotes-field input,
        .devnotes-field textarea {
            width: 100%;
            box-sizing: border-box;
            min-height: 42px;
            border-radius: 10px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: #fff;
            color: rgba(15, 23, 42, 0.94);
            padding: 10px 12px;
            font-size: 14px;
            line-height: 1.45;
        }

        .devnotes-field textarea {
            min-height: 110px;
            resize: vertical;
        }

        .devnotes-field input:focus,
        .devnotes-field textarea:focus {
            outline: none;
            border-color: rgba(15, 143, 97, 0.36);
            box-shadow: 0 0 0 4px rgba(15, 143, 97, 0.1);
        }

        .devnotes-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .devnotes-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #fff;
            color: rgba(15, 23, 42, 0.84);
            padding: 0 14px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .devnotes-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .devnotes-button--primary {
            border-color: rgba(15, 143, 97, 0.32);
            background: rgba(15, 143, 97, 0.12);
            color: #0f6f4c;
        }

        .devnotes-button--danger {
            border-color: rgba(180, 35, 24, 0.24);
            background: rgba(180, 35, 24, 0.08);
            color: #b42318;
        }

        .devnotes-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #0f6f4c;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .devnotes-list {
            display: grid;
            gap: 10px;
        }

        .devnotes-item {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.74);
            padding: 12px;
            display: grid;
            gap: 10px;
        }

        .devnotes-meta {
            color: rgba(15, 23, 42, 0.55);
            font-size: 12px;
        }

        .devnotes-empty {
            border-radius: 12px;
            border: 1px dashed rgba(15, 23, 42, 0.2);
            color: rgba(15, 23, 42, 0.6);
            background: rgba(248, 250, 252, 0.66);
            padding: 12px;
            font-size: 13px;
        }

        [hidden] {
            display: none !important;
        }

        @media (max-width: 980px) {
            .devnotes-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>

    <section class="devnotes-shell">
        @if(! $authorized)
            <article class="devnotes-card">
                <h2>Development Notes requires Shopify context</h2>
                <p>Open this page from Shopify Admin so Backstage can verify store access.</p>
            </article>
        @else
            <div class="devnotes-alert" id="devnotes-alert" hidden></div>

            <article class="devnotes-card">
                <h2>Internal Workspace</h2>
                <p>This is an internal admin-only workspace for implementation context and decision history.</p>
                <a class="devnotes-link" href="{{ $developmentNotesBootstrap['settingsHref'] ?? route('shopify.app.settings', [], false) }}">
                    Back to Settings
                </a>
            </article>

            <div id="devnotes-content" class="devnotes-grid" hidden>
                <article class="devnotes-card">
                    <h2>Project Notes</h2>
                    <p>Editable internal notes for architecture decisions, open questions, and implementation context.</p>

                    <form id="devnotes-note-create" class="devnotes-list">
                        <div class="devnotes-field">
                            <label for="devnotes-note-title">Title (optional)</label>
                            <input id="devnotes-note-title" name="title" maxlength="180" type="text" placeholder="Example: Tableview migration assumptions">
                        </div>
                        <div class="devnotes-field">
                            <label for="devnotes-note-body">Note</label>
                            <textarea id="devnotes-note-body" name="body" required placeholder="Write internal project context here."></textarea>
                        </div>
                        <div class="devnotes-actions">
                            <button type="submit" class="devnotes-button devnotes-button--primary" id="devnotes-note-create-submit">Add Note</button>
                        </div>
                    </form>

                    <div id="devnotes-notes-empty" class="devnotes-empty" hidden>No project notes yet.</div>
                    <div id="devnotes-notes-list" class="devnotes-list"></div>
                </article>

                <article class="devnotes-card">
                    <h2>Change Log</h2>
                    <p>Structured running history of updates made in this app. Newest entries appear first.</p>

                    <form id="devnotes-changelog-create" class="devnotes-list">
                        <div class="devnotes-field">
                            <label for="devnotes-log-title">Title</label>
                            <input id="devnotes-log-title" name="title" maxlength="180" required type="text" placeholder="Example: Added embedded development notes workspace">
                        </div>
                        <div class="devnotes-field">
                            <label for="devnotes-log-area">Area/Component (optional)</label>
                            <input id="devnotes-log-area" name="area" maxlength="120" type="text" placeholder="Example: Shopify Embedded / Settings">
                        </div>
                        <div class="devnotes-field">
                            <label for="devnotes-log-summary">Summary</label>
                            <textarea id="devnotes-log-summary" name="summary" required placeholder="What changed and why?"></textarea>
                        </div>
                        <div class="devnotes-actions">
                            <button type="submit" class="devnotes-button devnotes-button--primary" id="devnotes-log-create-submit">Add Change Log Entry</button>
                        </div>
                    </form>

                    <div id="devnotes-logs-empty" class="devnotes-empty" hidden>No change log entries yet.</div>
                    <div id="devnotes-logs-list" class="devnotes-list"></div>
                </article>
            </div>
        @endif
    </section>

    <script id="shopify-development-notes-bootstrap" type="application/json">
        {!! json_encode($developmentNotesBootstrap ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
    </script>

    <script>
        (() => {
            const bootstrap = JSON.parse(
                document.getElementById("shopify-development-notes-bootstrap")?.textContent || "{}"
            );

            const alertEl = document.getElementById("devnotes-alert");
            const contentEl = document.getElementById("devnotes-content");
            const notesListEl = document.getElementById("devnotes-notes-list");
            const notesEmptyEl = document.getElementById("devnotes-notes-empty");
            const logsListEl = document.getElementById("devnotes-logs-list");
            const logsEmptyEl = document.getElementById("devnotes-logs-empty");
            const noteCreateForm = document.getElementById("devnotes-note-create");
            const noteCreateButton = document.getElementById("devnotes-note-create-submit");
            const logCreateForm = document.getElementById("devnotes-changelog-create");
            const logCreateButton = document.getElementById("devnotes-log-create-submit");

            const state = {
                notes: [],
                changeLogs: [],
                initialized: false,
            };

            function setAlert(message, tone = "neutral") {
                if (!alertEl) {
                    return;
                }
                const text = typeof message === "string" ? message.trim() : "";
                if (text === "") {
                    alertEl.hidden = true;
                    alertEl.textContent = "";
                    alertEl.removeAttribute("data-tone");
                    return;
                }
                alertEl.hidden = false;
                alertEl.textContent = text;
                if (tone === "neutral") {
                    alertEl.removeAttribute("data-tone");
                } else {
                    alertEl.setAttribute("data-tone", tone);
                }
            }

            function authFailureMessage(status, fallbackMessage) {
                const messages = {
                    missing_api_auth: "Shopify Admin verification is unavailable. Reload this page from Shopify Admin and try again.",
                    invalid_session_token: "Shopify Admin verification failed. Reload this page from Shopify Admin and try again.",
                    expired_session_token: "Your Shopify Admin session expired. Reload this page from Shopify Admin and try again.",
                    forbidden: "You are not allowlisted for Development Notes.",
                };

                return messages[status] || fallbackMessage || null;
            }

            async function resolveEmbeddedAuthHeaders() {
                const resolver = window.ForestryEmbeddedApp?.resolveEmbeddedAuthHeaders;
                if (typeof resolver !== "function") {
                    throw new Error(
                        authFailureMessage("missing_api_auth", "Shopify Admin verification is unavailable. Reload from Shopify Admin and try again."),
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
                    credentials: "same-origin",
                    headers: {
                        ...authHeaders,
                        ...(options.headers || {}),
                    },
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

            function formatTimestamp(isoString) {
                if (typeof isoString !== "string" || isoString.trim() === "") {
                    return "Unknown time";
                }

                const parsed = new Date(isoString);
                if (Number.isNaN(parsed.getTime())) {
                    return "Unknown time";
                }

                return parsed.toLocaleString();
            }

            function metaLabel(entry, kind = "updated") {
                const actorName = entry?.updater?.name || entry?.creator?.name || null;
                const actorEmail = entry?.shopify_admin_email || entry?.updater?.email || entry?.creator?.email || null;
                const actor = actorName || actorEmail || "Unknown actor";
                const timestamp = kind === "created"
                    ? formatTimestamp(entry?.created_at)
                    : formatTimestamp(entry?.updated_at || entry?.created_at);

                return `${kind === "created" ? "Created" : "Updated"} ${timestamp} by ${actor}`;
            }

            function renderNotes() {
                if (!notesListEl || !notesEmptyEl) {
                    return;
                }

                notesListEl.innerHTML = "";

                if (!Array.isArray(state.notes) || state.notes.length === 0) {
                    notesEmptyEl.hidden = false;
                    return;
                }

                notesEmptyEl.hidden = true;

                for (const note of state.notes) {
                    const item = document.createElement("article");
                    item.className = "devnotes-item";
                    item.dataset.noteId = String(note.id || "");
                    item.innerHTML = `
                        <div class="devnotes-field">
                            <label>Title (optional)</label>
                            <input type="text" maxlength="180" data-role="note-title" value="${escapeHtml(note.title || "")}" placeholder="Untitled note">
                        </div>
                        <div class="devnotes-field">
                            <label>Note</label>
                            <textarea data-role="note-body">${escapeHtml(note.body || "")}</textarea>
                        </div>
                        <div class="devnotes-meta">${escapeHtml(metaLabel(note, "updated"))}</div>
                        <div class="devnotes-actions">
                            <button type="button" class="devnotes-button devnotes-button--primary" data-action="save-note">Save</button>
                            <button type="button" class="devnotes-button devnotes-button--danger" data-action="delete-note">Delete</button>
                        </div>
                    `;
                    notesListEl.appendChild(item);
                }
            }

            function renderChangeLogs() {
                if (!logsListEl || !logsEmptyEl) {
                    return;
                }

                logsListEl.innerHTML = "";

                if (!Array.isArray(state.changeLogs) || state.changeLogs.length === 0) {
                    logsEmptyEl.hidden = false;
                    return;
                }

                logsEmptyEl.hidden = true;

                for (const entry of state.changeLogs) {
                    const item = document.createElement("article");
                    item.className = "devnotes-item";
                    const area = entry?.area ? `<div class="devnotes-meta">Area: ${escapeHtml(entry.area)}</div>` : "";
                    item.innerHTML = `
                        <h3 style="margin: 0; font-size: 15px; color: #0f172a;">${escapeHtml(entry.title || "Untitled change")}</h3>
                        ${area}
                        <p style="margin: 0; color: rgba(15, 23, 42, 0.78); font-size: 13px; line-height: 1.6;">${escapeHtml(entry.summary || "")}</p>
                        <div class="devnotes-meta">${escapeHtml(metaLabel(entry, "created"))}</div>
                    `;
                    logsListEl.appendChild(item);
                }
            }

            function setCreateButtonsBusy(isBusy) {
                if (noteCreateButton) {
                    noteCreateButton.disabled = Boolean(isBusy);
                }
                if (logCreateButton) {
                    logCreateButton.disabled = Boolean(isBusy);
                }
            }

            function noteUpdateUrl(id) {
                const template = String(bootstrap?.endpoints?.updateNote || "");
                return template.replace("__NOTE_ID__", encodeURIComponent(String(id)));
            }

            function noteDeleteUrl(id) {
                const template = String(bootstrap?.endpoints?.deleteNote || "");
                return template.replace("__NOTE_ID__", encodeURIComponent(String(id)));
            }

            async function loadWorkspace() {
                setCreateButtonsBusy(true);
                setAlert("Verifying admin access...", "neutral");

                try {
                    await fetchJson(String(bootstrap?.endpoints?.access || ""), { method: "GET" });
                    const response = await fetchJson(String(bootstrap?.endpoints?.bootstrap || ""), { method: "GET" });
                    const data = response?.data || {};
                    state.notes = Array.isArray(data.notes) ? data.notes : [];
                    state.changeLogs = Array.isArray(data.change_logs) ? data.change_logs : [];
                    renderNotes();
                    renderChangeLogs();
                    if (contentEl) {
                        contentEl.hidden = false;
                    }
                    setAlert("", "neutral");
                    state.initialized = true;
                } catch (error) {
                    const payload = error?.payload || {};
                    if (contentEl) {
                        contentEl.hidden = true;
                    }
                    setAlert(
                        payload?.message || error?.message || "Unable to load Development Notes.",
                        "error"
                    );
                } finally {
                    setCreateButtonsBusy(false);
                }
            }

            noteCreateForm?.addEventListener("submit", async (event) => {
                event.preventDefault();
                if (!state.initialized) {
                    return;
                }

                const formData = new FormData(noteCreateForm);
                const payload = {
                    title: String(formData.get("title") || "").trim() || null,
                    body: String(formData.get("body") || "").trim(),
                };

                if (payload.body === "") {
                    setAlert("Project note body is required.", "error");
                    return;
                }

                setCreateButtonsBusy(true);
                setAlert("Adding project note...", "neutral");

                try {
                    await fetchJson(String(bootstrap?.endpoints?.storeNote || ""), {
                        method: "POST",
                        body: JSON.stringify(payload),
                    });
                    noteCreateForm.reset();
                    await loadWorkspace();
                    setAlert("Project note added.", "success");
                } catch (error) {
                    const payloadError = error?.payload || {};
                    setAlert(payloadError?.message || error?.message || "Failed to add project note.", "error");
                } finally {
                    setCreateButtonsBusy(false);
                }
            });

            logCreateForm?.addEventListener("submit", async (event) => {
                event.preventDefault();
                if (!state.initialized) {
                    return;
                }

                const formData = new FormData(logCreateForm);
                const payload = {
                    title: String(formData.get("title") || "").trim(),
                    area: String(formData.get("area") || "").trim() || null,
                    summary: String(formData.get("summary") || "").trim(),
                };

                if (payload.title === "" || payload.summary === "") {
                    setAlert("Change log title and summary are required.", "error");
                    return;
                }

                setCreateButtonsBusy(true);
                setAlert("Adding change log entry...", "neutral");

                try {
                    await fetchJson(String(bootstrap?.endpoints?.storeChangeLog || ""), {
                        method: "POST",
                        body: JSON.stringify(payload),
                    });
                    logCreateForm.reset();
                    await loadWorkspace();
                    setAlert("Change log entry added.", "success");
                } catch (error) {
                    const payloadError = error?.payload || {};
                    setAlert(payloadError?.message || error?.message || "Failed to add change log entry.", "error");
                } finally {
                    setCreateButtonsBusy(false);
                }
            });

            notesListEl?.addEventListener("click", async (event) => {
                const button = event.target instanceof HTMLElement
                    ? event.target.closest("button[data-action]")
                    : null;
                if (!button) {
                    return;
                }

                const item = button.closest("[data-note-id]");
                const noteId = item?.getAttribute("data-note-id") || "";
                if (noteId === "") {
                    return;
                }

                const action = button.getAttribute("data-action");
                const titleInput = item.querySelector('[data-role="note-title"]');
                const bodyInput = item.querySelector('[data-role="note-body"]');

                if (!(titleInput instanceof HTMLInputElement) || !(bodyInput instanceof HTMLTextAreaElement)) {
                    return;
                }

                if (action === "delete-note") {
                    if (!window.confirm("Delete this project note?")) {
                        return;
                    }

                    setCreateButtonsBusy(true);
                    setAlert("Deleting project note...", "neutral");
                    try {
                        await fetchJson(noteDeleteUrl(noteId), {
                            method: "DELETE",
                        });
                        await loadWorkspace();
                        setAlert("Project note deleted.", "success");
                    } catch (error) {
                        const payloadError = error?.payload || {};
                        setAlert(payloadError?.message || error?.message || "Failed to delete project note.", "error");
                    } finally {
                        setCreateButtonsBusy(false);
                    }

                    return;
                }

                if (action === "save-note") {
                    const payload = {
                        title: titleInput.value.trim() || null,
                        body: bodyInput.value.trim(),
                    };

                    if (payload.body === "") {
                        setAlert("Project note body is required.", "error");
                        return;
                    }

                    setCreateButtonsBusy(true);
                    setAlert("Saving project note...", "neutral");
                    try {
                        await fetchJson(noteUpdateUrl(noteId), {
                            method: "PATCH",
                            body: JSON.stringify(payload),
                        });
                        await loadWorkspace();
                        setAlert("Project note saved.", "success");
                    } catch (error) {
                        const payloadError = error?.payload || {};
                        setAlert(payloadError?.message || error?.message || "Failed to save project note.", "error");
                    } finally {
                        setCreateButtonsBusy(false);
                    }
                }
            });

            function escapeHtml(value) {
                return String(value ?? "")
                    .replaceAll("&", "&amp;")
                    .replaceAll("<", "&lt;")
                    .replaceAll(">", "&gt;")
                    .replaceAll('"', "&quot;")
                    .replaceAll("'", "&#039;");
            }

            loadWorkspace();
        })();
    </script>
</x-shopify-embedded-shell>
