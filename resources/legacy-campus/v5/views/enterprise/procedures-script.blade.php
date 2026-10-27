// Shared procedures use their explicit transport: authenticated server or fictional browser sample. No network fallback.
const procedureEndpoint = (path, query = {}) =>
    `${urls.enterprise}${path}?${new URLSearchParams({ tenant: tenantSlug, ...query })}`;
let procedureGeneration = 0,
    procedureContext = "",
    certificateRows = [],
    certificateContacts = [],
    certificateSelected = "",
    certificateForm = null,
    certificateWritable = false,
    reportGeneration = 0,
    quarterlyDownloadQuery = null;
const certificatePending = new Map();
const certificateReceiptKey = (scope) => "certificate-operation:" + scope;
function certificateStore(scope, pending) {
    writeDraft(
        certificateReceiptKey(scope),
        pending ? { pending: { body: pending.body } } : {},
    );
}
function certificateRestore(scope) {
    if (certificatePending.has(scope)) return;
    const body = readDraft(certificateReceiptKey(scope)).pending?.body;
    if (!body) return;
    const uuid = (value) =>
        typeof value === "string" &&
        /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
            value,
        );
    if (
        body.area_id !== scope ||
        !uuid(body.id) ||
        !uuid(body.request_id) ||
        !Number.isInteger(body.expected_version) ||
        body.expected_version < 0 ||
        ![
            "certificate.propose",
            "certificate.metadata",
            "certificate.review",
            "certificate.archive",
        ].includes(body.action)
    )
        return;
    certificatePending.set(scope, { body, busy: false });
}
const procedurePrivate = () => ["private", "sample"].includes(transport.mode);
const procedureCurrent = (token) =>
    token.generation === procedureGeneration &&
    token.context === procedureContext &&
    section === "people";
const procedureToken = () => ({
    generation: procedureGeneration,
    context: procedureContext,
});
const certificateNotice = (text, error = false) => {
    $("certificate-status").textContent = text;
    $("certificate-status").classList.toggle("notice-error", error);
};
const quarterlyNotice = (text, error = false) => {
    $("quarterly-status").textContent = text;
    $("quarterly-status").classList.toggle("notice-error", error);
};
const procedureBlank = (value) =>
    value === null || value === undefined || value === ""
        ? "Not recorded"
        : String(value);
const certificateReasons = (row) =>
    (row.reminder_reasons || [])
        .map(
            (value) =>
                ({
                    expiry_unknown: "Accepted expiry unknown",
                    document_reference_missing:
                        "No accepted document reference",
                    unreviewed: "No accepted certificate review",
                    awaiting_replacement_review:
                        "Replacement awaiting manager review",
                })[value] || String(value).replaceAll("_", " "),
        )
        .join(" · ") || "No recorded attention reason";
const certificateDate = (value) => value || "Unknown";
function clearProcedureData() {
    procedureGeneration++;
    reportGeneration++;
    quarterlyDownloadQuery = null;
    procedureContext = "";
    certificateRows = [];
    certificateContacts = [];
    certificateSelected = "";
    certificateForm = null;
    certificateWritable = false;
    for (const id of [
        "certificate-list",
        "certificate-reminders",
        "certificate-detail",
        "quarterly-preview-body",
    ])
        $(id).replaceChildren();
    $("certificate-form").reset();
    $("certificate-form").hidden = true;
    $("certificate-body").hidden = true;
    $("certificate-retained").hidden = true;
    $("certificate-retained-body").replaceChildren();
    $("quarterly-period").hidden = true;
    $("quarterly-csv").hidden = true;
    $("quarterly-json").hidden = true;
    certificateNotice(
        "Current access must be checked before certificate records are displayed.",
    );
    quarterlyNotice(
        "Current access must be checked before report data is displayed.",
    );
}
function procedureDenied(error) {
    if (![401, 403, 404].includes(error.status)) return false;
    document.dispatchEvent(new CustomEvent("enterprise:reset"));
    certificateNotice(
        "Certificate access is unavailable. Reload the authorized workspace to check current permissions.",
        true,
    );
    quarterlyNotice(
        "Report access is unavailable. Reload the authorized workspace to check current permissions.",
        true,
    );
    return true;
}
function certificateLock() {
    const pending = certificatePending.get(area),
        locked = Boolean(pending),
        busy = pending?.busy === true;
    $("certificate-retry").hidden = !pending;
    $("certificate-retry").disabled = busy || !certificateWritable;
    $("certificate-retained").hidden = !pending;
    $("certificate-retained-body").textContent = pending
        ? JSON.stringify(pending.body, null, 2)
        : "";
    $("certificate-new").hidden = !certificateWritable;
    $("certificate-new").disabled = locked;
    for (const element of $("certificate-workspace").querySelectorAll(
        "form input,form select,form textarea,form button",
    ))
        element.disabled = locked || !certificateWritable;
    if (certificateForm?.kind === "metadata")
        $("certificate-contractor").disabled = true;
}
function certificateCard(row) {
    return `<button type="button" class="procedure-record" data-certificate="${esc(row.id)}" aria-pressed="${row.id === certificateSelected}"><strong>${esc(row.title)}</strong><small>${esc(row.contractor_title)} · ${esc(row.status)} · v${esc(row.version)}</small><small>Accepted expiry: ${esc(certificateDate(row.accepted?.expires_on))} · ${esc(row.expiry_state || "unknown")}${row.pending ? " · Replacement awaiting review" : ""}</small></button>`;
}
function certificateLists(reminders) {
    $("certificate-list").innerHTML =
        certificateRows.map(certificateCard).join("") ||
        '<p class="caption">No certificate records in the returned authorized result.</p>';
    $("certificate-reminders").innerHTML =
        reminders
            .map(
                (row) =>
                    `<div>${certificateCard(row)}<p class="caption">${esc(certificateReasons(row))}</p></div>`,
            )
            .join("") ||
        '<p class="caption">No attention records were returned. This is a bounded queue, not proof that every contractor record is complete.</p>';
    for (const button of $("certificate-workspace").querySelectorAll(
        "[data-certificate]",
    ))
        button.onclick = () => certificateSelect(button.dataset.certificate);
}
const certificateFact = (label, value) =>
    `<dt>${esc(label)}</dt><dd>${esc(procedureBlank(value))}</dd>`;
function certificateDetail(row) {
    const accepted = row.accepted,
        pending = row.pending;
    $("certificate-detail").innerHTML =
        `<h3>${esc(row.title)}</h3><p class="caption">${esc(row.contractor_title)} · ${esc(row.status)} · Exact record v${esc(row.version)}</p><dl class="procedure-facts">${certificateFact("Renewal contact", row.renewal_contact)}${certificateFact("Warning lead days", row.warning_lead_days)}${certificateFact("Manager review due", row.review_due_on)}${certificateFact("Expiry state", row.expiry_state)}${certificateFact("Attention", certificateReasons(row))}</dl><h4>Accepted certificate record</h4>${accepted ? `<dl class="procedure-facts">${certificateFact("Document reference", accepted.document_reference)}${certificateFact("Expiry", accepted.expires_on)}${certificateFact("Reviewed by", accepted.reviewed_by)}${certificateFact("Reviewed at", accepted.reviewed_at)}${certificateFact("Review reason", accepted.review_reason)}</dl>` : '<p class="caption">No certificate replacement has been accepted.</p>'}<h4>Pending replacement</h4>${pending ? `<dl class="procedure-facts">${certificateFact("Document reference", pending.document_reference)}${certificateFact("Proposed expiry", pending.expires_on)}${certificateFact("Proposed at", pending.proposed_at)}</dl><p class="procedure-notice">This proposal does not replace the accepted record or remove its expiry/reminder state until an authorized manager explicitly accepts it.</p>` : '<p class="caption">No replacement awaits review.</p>'}<div class="actions" id="certificate-detail-actions"></div><div id="certificate-review-actions"></div><h4>Revision history</h4><div class="procedure-history" id="certificate-history">Checking current authorized history.</div>`;
    const host = $("certificate-detail-actions");
    if (certificateWritable && row.status === "active") {
        const metadata = document.createElement("button");
        metadata.type = "button";
        metadata.className = "button";
        metadata.textContent = "Edit certificate metadata";
        metadata.onclick = () => certificateOpen(row);
        host.append(metadata);
        if (!pending) {
            const form = document.createElement("form");
            form.className = "stack procedure-form";
            form.setAttribute("aria-label", "Propose certificate replacement");
            form.innerHTML =
                '<h4>Propose a replacement</h4><label>Replacement document reference<textarea name="document_reference" maxlength="500" rows="2"></textarea></label><label>Replacement expiry, if known<input name="expires_on" type="date"></label><button class="button" type="submit">Save replacement proposal</button>';
            form.onsubmit = (event) => {
                event.preventDefault();
                certificateSend({
                    action: "certificate.propose",
                    id: row.id,
                    expected_version: row.version,
                    replacement_id: crypto.randomUUID(),
                    document_reference:
                        form.elements.document_reference.value.trim() || null,
                    expires_on: form.elements.expires_on.value || null,
                });
            };
            $("certificate-review-actions").append(form);
        }
        if (pending) {
            const form = document.createElement("form");
            form.className = "stack procedure-form";
            form.setAttribute(
                "aria-label",
                "Review exact certificate replacement",
            );
            form.innerHTML =
                '<h4>Review this exact replacement</h4><label>Decision<select name="decision"><option value="">Choose a decision</option><option value="accept">Accept replacement record</option><option value="reject">Reject replacement record</option></select></label><label>Recorded review reason<textarea name="reason" required maxlength="1000" rows="3"></textarea></label><label class="procedure-confirm"><input type="checkbox" name="confirmed" required> I reviewed the exact reference and expiry shown above. This records a procedural review, not a coverage determination.</label><button class="button primary" type="submit">Confirm replacement review</button>';
            form.elements.decision.required = true;
            form.elements.decision.querySelector('[value="accept"]').disabled =
                !pending.document_reference || !pending.expires_on;
            form.onsubmit = (event) => {
                event.preventDefault();
                certificateSend({
                    action: "certificate.review",
                    id: row.id,
                    expected_version: row.version,
                    replacement_id: pending.replacement_id,
                    decision: form.elements.decision.value,
                    reason: form.elements.reason.value.trim(),
                    confirmed: form.elements.confirmed.checked,
                });
            };
            $("certificate-review-actions").append(form);
        }
        const archive = document.createElement("details");
        archive.className = "procedure-form";
        archive.innerHTML =
            '<summary>Archive this register record</summary><form class="stack" aria-label="Archive certificate record"><label>Archive reason<textarea name="reason" required maxlength="1000" rows="2"></textarea></label><label class="procedure-confirm"><input type="checkbox" name="confirmed" required> I reviewed this exact record and want to archive it.</label><button class="button" type="submit">Confirm archive</button></form>';
        archive.querySelector("form").onsubmit = (event) => {
            event.preventDefault();
            const f = event.currentTarget;
            certificateSend({
                action: "certificate.archive",
                id: row.id,
                expected_version: row.version,
                reason: f.elements.reason.value.trim(),
                confirmed: f.elements.confirmed.checked,
            });
        };
        $("certificate-review-actions").append(archive);
    }
    certificateLock();
}
async function certificateSelect(id) {
    if (
        !procedurePrivate() ||
        !data.features?.certificates ||
        !data.can_review ||
        section !== "people"
    )
        return;
    if (!certificateRows.some((row) => row.id === id)) return;
    certificateSelected = id;
    $("certificate-form").hidden = true;
    certificateForm = null;
    const token = procedureToken();
    try {
        const [row, history] = await Promise.all([
            api(procedureEndpoint("/certificates/" + encodeURIComponent(id))),
            api(
                procedureEndpoint(
                    "/certificates/" + encodeURIComponent(id) + "/history",
                ),
            ),
        ]);
        if (!procedureCurrent(token) || certificateSelected !== id) return;
        certificateDetail(row);
        $("certificate-history").innerHTML =
            history.items
                .map(
                    (item) =>
                        `<article><strong>Version ${esc(item.version)}</strong><p>${esc(item.at)} · actor ${esc(item.actor_id)}</p><pre>${esc(JSON.stringify(item.event, null, 2))}</pre></article>`,
                )
                .join("") || '<p class="caption">No history was returned.</p>';
        const coverage = document.createElement("p");
        coverage.className = "caption";
        coverage.textContent = history.coverage || "";
        $("certificate-history").append(coverage);
    } catch (error) {
        if (procedureCurrent(token) && certificateSelected === id) {
            if (procedureDenied(error)) return;
            $("certificate-detail").textContent = error.message;
        }
    }
}
async function certificateLoad() {
    if (
        !procedurePrivate() ||
        !data.features?.certificates ||
        !data.can_review ||
        section !== "people" ||
        !area
    )
        return;
    const token = procedureToken();
    certificateNotice("Checking current manager access and register records.");
    $("certificate-body").hidden = true;
    try {
        const [result, reminders] = await Promise.all([
            api(procedureEndpoint("/certificates", { area_id: area })),
            api(
                procedureEndpoint("/certificates/reminders", { area_id: area }),
            ),
        ]);
        if (!procedureCurrent(token)) return;
        certificateRows = result.items || [];
        certificateContacts = result.contractors || [];
        certificateWritable = Boolean(data.can_coordinate && result.can_manage);
        certificateRestore(area);
        $("certificate-body").hidden = false;
        $("certificate-coverage").textContent =
            `As of ${result.as_of_on} · ${result.timezone}. ${result.coverage || ""}${result.has_more_contractors ? " More contractor contacts exist beyond this selector." : ""}`;
        certificateLists(reminders.items || []);
        certificateLock();
        certificateNotice(
            transport.mode === "sample"
                ? "Fictional browser-only certificate simulation. No college records, document review or external reminders are connected."
                : certificateWritable
                ? "Current manager records checked. Changes require their own explicit action."
                : "Current manager records checked. Writes are paused or unavailable; this view is read only.",
        );
        if (
            certificateSelected &&
            certificateRows.some((row) => row.id === certificateSelected)
        )
            await certificateSelect(certificateSelected);
    } catch (error) {
        if (!procedureCurrent(token)) return;
        if (procedureDenied(error)) return;
        certificateRows = [];
        certificateContacts = [];
        $("certificate-body").hidden = true;
        certificateNotice(error.message, true);
    }
}
function certificateOpen(row = null) {
    if (!certificateWritable || certificatePending.has(area)) return;
    certificateForm = {
        kind: row ? "metadata" : "new",
        id: row?.id || crypto.randomUUID(),
        version: row?.version || 0,
    };
    $("certificate-form").reset();
    $("certificate-contractor").innerHTML =
        '<option value="">Choose an existing active contractor</option>' +
        certificateContacts
            .filter(
                (contact) =>
                    contact.status === "active" ||
                    contact.id === row?.contractor_id,
            )
            .map(
                (contact) =>
                    `<option value="${esc(contact.id)}">${esc(contact.title)}${contact.status === "active" ? "" : " · inactive contact"}</option>`,
            )
            .join("");
    $("certificate-contractor").value = row?.contractor_id || "";
    $("certificate-title").value = row?.title || "";
    $("certificate-contact").value = row?.renewal_contact || "";
    $("certificate-warning").value = row?.warning_lead_days ?? "";
    $("certificate-review-date").value = row?.review_due_on || "";
    $("certificate-initial-proposal").hidden = Boolean(row);
    $("certificate-form-heading").textContent = row
        ? "Edit certificate metadata"
        : "New certificate record";
    $("certificate-save").textContent = row
        ? "Save certificate metadata"
        : "Save proposed certificate record";
    $("certificate-form").hidden = false;
    certificateLock();
    $("certificate-title").focus();
}
async function certificateSend(command) {
    if (
        !certificateWritable ||
        !procedurePrivate() ||
        !data.features?.certificates ||
        !data.can_review ||
        section !== "people"
    )
        return;
    const scope = area;
    let pending = certificatePending.get(scope);
    if (!pending) {
        pending = {
            body: {
                ...command,
                area_id: scope,
                request_id: crypto.randomUUID(),
            },
            busy: false,
        };
        certificatePending.set(scope, pending);
        certificateStore(scope, pending);
    }
    if (pending.busy) return;
    pending.busy = true;
    const token = procedureToken();
    certificateLock();
    certificateNotice("Saving this exact versioned certificate action.");
    try {
        const result = await api(
            procedureEndpoint("/certificates/commands"),
            pending.body,
        );
        certificatePending.delete(scope);
        certificateStore(scope, null);
        if (!procedureCurrent(token)) return;
        certificateSelected = pending.body.id;
        $("certificate-form").hidden = true;
        certificateForm = null;
        await certificateLoad();
        if (procedureCurrent(token))
            certificateNotice(
                result.replayed
                    ? "Existing certificate action recovered without repeating the change."
                    : "Certificate action recorded. No reminder was sent.",
            );
    } catch (error) {
        if ([401, 403, 404, 409, 422].includes(error.status)) {
            certificatePending.delete(scope);
            certificateStore(scope, null);
        }
        if (!procedureCurrent(token)) return;
        if (procedureDenied(error)) return;
        certificateNotice(
            error.message +
                (certificatePending.has(scope)
                    ? " The outcome is unconfirmed. Retry this exact action; editing is locked."
                    : " Refresh current records before preparing another action."),
            true,
        );
    } finally {
        pending.busy = false;
        if (procedureCurrent(token)) certificateLock();
    }
}
$("certificate-new").onclick = () => certificateOpen();
$("certificate-refresh").onclick = () => {
    procedureGeneration++;
    certificateLoad();
};
$("certificate-form-close").onclick = () => {
    $("certificate-form").hidden = true;
    certificateForm = null;
};
$("certificate-retry").onclick = () => {
    const held = certificatePending.get(area);
    return held ? certificateSend(held.body) : undefined;
};
$("certificate-form").onsubmit = (event) => {
    event.preventDefault();
    if (!certificateForm) return;
    const body = {
        action:
            certificateForm.kind === "new"
                ? "certificate.propose"
                : "certificate.metadata",
        id: certificateForm.id,
        expected_version: certificateForm.version,
        title: $("certificate-title").value.trim(),
        renewal_contact: $("certificate-contact").value.trim() || null,
        warning_lead_days:
            $("certificate-warning").value === ""
                ? null
                : Number($("certificate-warning").value),
        review_due_on: $("certificate-review-date").value || null,
    };
    if (certificateForm.kind === "new")
        Object.assign(body, {
            contractor_id: $("certificate-contractor").value,
            replacement_id: crypto.randomUUID(),
            document_reference: $("certificate-document").value.trim() || null,
            expires_on: $("certificate-expiry").value || null,
        });
    certificateSend(body);
};

function quarterlyInvalidate() {
    reportGeneration++;
    quarterlyDownloadQuery = null;
    $("quarterly-preview-body").replaceChildren();
    $("quarterly-csv").hidden = true;
    $("quarterly-json").hidden = true;
    $("quarterly-preview").disabled = false;
}
function quarterlyQuery() {
    const query = {
        area_id: area,
        mode: $("quarterly-mode").value,
        timezone: $("quarterly-timezone").value.trim(),
    };
    if ($("quarterly-project").value)
        query.project_id = $("quarterly-project").value;
    if (query.mode === "calendar_quarter")
        Object.assign(query, {
            year: $("quarterly-year").value,
            quarter: $("quarterly-quarter").value,
        });
    else
        Object.assign(query, {
            start_date: $("quarterly-start").value,
            end_date: $("quarterly-end").value,
        });
    return query;
}
function quarterlyMode() {
    const ranged = $("quarterly-mode").value === "date_range";
    $("quarterly-range").hidden = !ranged;
    $("quarterly-calendar").hidden = ranged;
    $("quarterly-start").required = ranged;
    $("quarterly-end").required = ranged;
    $("quarterly-year").required = !ranged;
    quarterlyInvalidate();
}
const procedureSource = (url) => {
    try {
        const target = new URL(transport.href(url), location.href);
        return target.origin === location.origin &&
            ["http:", "https:"].includes(target.protocol)
            ? target.href
            : null;
    } catch {
        return null;
    }
};
function quarterlyPaint(report, query) {
    const host = $("quarterly-preview-body"),
        window = report.window;
    host.replaceChildren();
    const intro = document.createElement("article");
    intro.innerHTML = `<h3>${esc(report.label)}</h3><p>Generated ${esc(report.generated_at_utc)} · ${esc(window.timezone)}</p><p>${esc(window.start_local)} through ${esc(window.end_local_inclusive)} inclusive · ${window.period_state === "future" ? "Period has not started" : window.period_in_progress ? "Period still in progress" : "Period window ended"}</p><p class="caption">UTC interval: ${esc(window.start_utc)} to ${esc(window.end_utc_exclusive)} exclusive. Observed through ${esc(window.observed_through_utc)}.</p><h4>How to read this report</h4><p>Records show their current status and dates at generation time. Recorded activity lists checked actions within the selected period for currently included records.</p><p class="procedure-notice">Current record state is now. Events do not reconstruct historical status at the end of the period.</p>`;
    host.append(intro);
    for (const [title, rows, event] of [
        ["Current authorized records", report.current_records || [], false],
        [
            "Recorded activity within the period",
            report.recorded_events || [],
            true,
        ],
    ]) {
        const section = document.createElement("section");
        const heading = document.createElement("h3");
        heading.textContent = title;
        const list = document.createElement("div");
        list.className = "procedure-scroll";
        for (const row of rows.slice(0, 100)) {
            const item = document.createElement("article"),
                h = document.createElement("h4"),
                link = procedureSource(row.source_url);
            if (link) {
                const anchor = document.createElement("a");
                anchor.href = link;
                anchor.textContent = row.title || row.current_title || row.id;
                h.append(anchor);
            } else h.textContent = row.title || row.current_title || row.id;
            const facts = document.createElement("p");
            facts.textContent = event
                ? `${row.entity_type} · ${row.action} · Recorded ${row.recorded_at} · v${row.version ?? "not recorded"}`
                : `${row.entity_type} · Current status: ${row.status} · Owner ID: ${row.owner_id ?? "unassigned"} · Due: ${row.due_on || "unknown"} · Last recorded ${row.updated_at || "not recorded"}`;
            item.append(h, facts);
            list.append(item);
        }
        if (!rows.length) {
            const empty = document.createElement("p");
            empty.className = "caption";
            empty.textContent =
                "No rows were returned for this checked selection.";
            list.append(empty);
        }
        if (rows.length > 100) {
            const note = document.createElement("p");
            note.className = "caption";
            note.textContent = `Showing 100 of ${rows.length} returned rows in this screen. Exports contain the complete bounded response.`;
            section.append(note);
        }
        section.append(heading, list);
        host.append(section);
    }
    const coverage = document.createElement("article");
    const currentCoverage = report.coverage?.current_records || {},
        eventCoverage = report.coverage?.recorded_events || {};
    coverage.innerHTML = `<h4>Report coverage</h4><dl class="procedure-facts">${certificateFact("Current records returned", currentCoverage.returned)}${certificateFact("Recorded events returned", eventCoverage.returned)}${certificateFact("Additional current records omitted", currentCoverage.omitted_visible_candidates)}${certificateFact("Additional matching events omitted", eventCoverage.omitted_matching_events)}</dl><p class="caption">Checked-source limits: ${esc(currentCoverage.limits?.project ?? "not provided")} projects, ${esc(currentCoverage.limits?.task ?? "not provided")} tasks and ${esc(currentCoverage.limits?.request ?? "not provided")} requests. Up to ${esc(eventCoverage.limit ?? "not provided")} recorded events are returned.</p><p class="caption">This selection includes only currently visible records. Deleted, inaccessible and omitted records are not reconstructed. Counts describe this bounded report, not all organization activity.</p><h4>Excluded information</h4><ul>${(report.exclusions || []).map((value) => `<li>${esc(value)}</li>`).join("")}</ul><p>Download again for a fresh snapshot. Saved files do not update.</p>`;
    host.append(coverage);
    quarterlyDownloadQuery = { ...query };
    for (const format of ["csv", "json"]) {
        const anchor = $("quarterly-" + format);
        anchor.href = transport.href(
            procedureEndpoint("/reports/quarterly." + format, query),
        );
        anchor.hidden = false;
    }
}
async function quarterlyDownload(event, format) {
    event.preventDefault();
    if (
        !quarterlyDownloadQuery ||
        !procedurePrivate() ||
        !data.features?.reports ||
        !data.can_review ||
        section !== "roll_call"
    )
        return;
    const generation = reportGeneration,
        scope = area;
    let objectUrl = null;
    try {
        if (transport.mode === "sample") {
            const result = await api(procedureEndpoint("/reports/quarterly." + format, quarterlyDownloadQuery));
            if (generation !== reportGeneration || area !== scope || section !== "roll_call") return;
            if (result.sample_export !== true || result.mime !== (format === "csv" ? "text/csv" : "application/json") || typeof result.content !== "string") throw new Error("The fictional sample export is unavailable.");
            objectUrl = URL.createObjectURL(new Blob([result.content], {type: result.mime}));
            const anchor = document.createElement("a"); anchor.href=objectUrl; anchor.download="fictional-sample-period-activity." + format; document.body.append(anchor); anchor.click(); anchor.remove();
            quarterlyNotice("Fictional browser-only " + format.toUpperCase() + " snapshot generated. No real college records or server export were requested.");
            return;
        }
        const target = new URL(
            transport.href(
                procedureEndpoint(
                    "/reports/quarterly." + format,
                    quarterlyDownloadQuery,
                ),
            ),
            location.href,
        );
        if (
            target.origin !== location.origin ||
            target.username ||
            target.password
        )
            throw new Error(
                "Report downloads must use the current application.",
            );
        quarterlyNotice(
            "Regenerating a fresh authorized " +
                format.toUpperCase() +
                " snapshot.",
        );
        const mime = format === "csv" ? "text/csv" : "application/json";
        const response = await fetch(target.href, {
            credentials: "same-origin",
            headers: {
                Accept: mime,
                "X-Team-Viewer": String(viewer),
                "X-Team-Workspace": String(workspace),
            },
        });
        if (!response.ok) {
            const result = await response.json().catch(() => ({}));
            const error = new Error(
                result.message ||
                    result.error ||
                    "The current report export is unavailable.",
            );
            error.status = response.status;
            throw error;
        }
        if (!response.headers.get("Content-Type")?.startsWith(mime))
            throw new Error(
                "The server did not return the expected report format.",
            );
        const bytes = await response.arrayBuffer();
        if (
            generation !== reportGeneration ||
            area !== scope ||
            section !== "roll_call"
        )
            return;
        if (format === "json") {
            const report = JSON.parse(new TextDecoder().decode(bytes));
            if (
                report.schema_version !== 1 ||
                report.report_type !== "period_activity"
            )
                throw new Error(
                    "The server did not return the expected period activity report.",
                );
        }
        objectUrl = URL.createObjectURL(new Blob([bytes], { type: mime }));
        const anchor = document.createElement("a");
        anchor.href = objectUrl;
        anchor.download = "everbranch-period-activity." + format;
        document.body.append(anchor);
        anchor.click();
        anchor.remove();
        quarterlyNotice(
            "Fresh " +
                format.toUpperCase() +
                " snapshot generated. It does not update after download.",
        );
    } catch (error) {
        if (
            generation !== reportGeneration ||
            area !== scope ||
            section !== "roll_call"
        )
            return;
        if (procedureDenied(error)) return;
        quarterlyNotice(error.message, true);
    } finally {
        if (objectUrl) {
            const release = objectUrl;
            setTimeout(() => URL.revokeObjectURL(release), 30000);
        }
    }
}
$("quarterly-csv").onclick = (event) => quarterlyDownload(event, "csv");
$("quarterly-json").onclick = (event) => quarterlyDownload(event, "json");
$("quarterly-period").onsubmit = async (event) => {
    event.preventDefault();
    if (
        !procedurePrivate() ||
        !data.features?.reports ||
        !data.can_review ||
        !area ||
        section !== "roll_call"
    )
        return;
    quarterlyInvalidate();
    const generation = reportGeneration,
        scope = area,
        query = quarterlyQuery();
    $("quarterly-preview").disabled = true;
    quarterlyNotice("Checking current permissions and selected period.");
    try {
        const report = await api(
            procedureEndpoint("/reports/quarterly", query),
        );
        if (
            generation !== reportGeneration ||
            area !== scope ||
            section !== "roll_call"
        )
            return;
        quarterlyPaint(report, query);
        quarterlyNotice(
            "Current preview checked. Downloads regenerate this exact selection with a fresh access check.",
        );
    } catch (error) {
        if (
            generation !== reportGeneration ||
            area !== scope ||
            section !== "roll_call"
        )
            return;
        if (procedureDenied(error)) return;
        quarterlyNotice(error.message, true);
    } finally {
        if (generation === reportGeneration)
            $("quarterly-preview").disabled = false;
    }
};
$("quarterly-mode").onchange = quarterlyMode;
for (const input of $("quarterly-period").querySelectorAll("input,select"))
    if (input.id !== "quarterly-mode")
        input.addEventListener("change", () => {
            quarterlyInvalidate();
            quarterlyNotice(
                "The period selection changed. Preview it again before downloading.",
            );
        });
$("quarterly-year").value = new Date().getUTCFullYear();
$("quarterly-quarter").value = String(
    Math.floor(new Date().getUTCMonth() / 3) + 1,
);
$("operations-people").after($("certificate-workspace"));
$("roll-call-panel").after($("quarterly-workspace"));
document.addEventListener("enterprise:reset", clearProcedureData);
document.addEventListener("enterprise:tour-context", (event) => {
    if (!event.detail) clearProcedureData();
});
document.addEventListener("enterprise:render", () => {
    const certificates = section === "people",
        reports = section === "roll_call";
    $("certificate-workspace").hidden = !certificates;
    $("quarterly-workspace").hidden = !reports;
    if (procedurePrivate() && data.can_review && data.features?.certificates)
        $("people-nav").hidden = false;
    if (procedurePrivate() && data.can_review && data.features?.reports)
        $("roll-call-nav").hidden = false;
    if (certificates && !data.features?.operations)
        $("operations-people").hidden = true;
    if (reports && !data.features?.roll_call)
        $("roll-call-panel").hidden = true;
    if (certificates || reports)
        for (const id of ["work", "campus-home", "ask", "contractors"])
            $(id).hidden = true;
    const context = area + ":" + section;
    if (context === procedureContext) return;
    clearProcedureData();
    procedureContext = context;
    if (certificates) {
        if (!procedurePrivate()) {
            certificateNotice(
                "Certificate records and reminder queues are unavailable in the isolated public sample. No simulated certificate acceptance is supplied.",
            );
            return;
        }
        if (!data.features?.certificates || !data.can_review) {
            certificateNotice(
                "Certificate register is disabled or unavailable to your current role. No records are loaded.",
            );
            return;
        }
        if (area) certificateLoad();
    }
    if (reports) {
        if (!procedurePrivate()) {
            quarterlyNotice(
                "Quarterly exports are unavailable in the isolated public sample. No report data is fabricated.",
            );
            return;
        }
        if (!data.features?.reports || !data.can_review) {
            quarterlyNotice(
                "Period reports are disabled or unavailable to your current role. No report data is loaded.",
            );
            return;
        }
        $("quarterly-project").innerHTML =
            '<option value="">All currently authorized projects in this workspace</option>' +
            data.projects
                .map(
                    (row) =>
                        `<option value="${esc(row.id)}">${esc(row.name)}</option>`,
                )
                .join("");
        $("quarterly-period").hidden = !area;
        $("quarterly-preview").disabled = false;
        quarterlyMode();
        quarterlyNotice(transport.mode === "sample" ? "Fictional browser-only reporting simulation. Choose a window to preview sample records and export a labeled snapshot." : "Choose and explicitly preview a reporting window.");
    }
});
