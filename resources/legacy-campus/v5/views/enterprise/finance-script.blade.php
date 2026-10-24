const financeEndpoint = (path = "", query = {}) =>
    `${urls.enterprise}/finance${path}?${new URLSearchParams({ tenant: tenantSlug, ...query })}`;
let financeGeneration = 0,
    financeContext = "",
    financeRows = [],
    financeChoices = {},
    financeSelected = "",
    financeForm = null,
    financeWritable = false;
const financePending = new Map();
const financeKey = (scope) => "finance-packet:" + scope;
const financeCurrent = (token) =>
    token.generation === financeGeneration &&
    token.context === financeContext &&
    section === "finance";
const financeToken = () => ({
    generation: financeGeneration,
    context: financeContext,
});
const financeMessage = (text, failed = false) => {
    $("finance-status").textContent = text;
    $("finance-status").classList.toggle("notice-error", failed);
};
const financeValues = (row) => ({ ...(row.data || {}), ...row });
const financeFact = (label, value) =>
    `<dt>${esc(label)}</dt><dd>${esc(value === null || value === undefined || value === "" ? "Not recorded" : value)}</dd>`;
function financeRemember() {
    if (!financeForm || !financeWritable || !area || $("finance-form").hidden)
        return;
    const values = Object.fromEntries(
        [
            "title",
            "project",
            "contractor",
            "amount",
            "currency",
            "document",
            "fund",
            "restriction",
            "source",
            "owner",
        ].map((key) => [key, $("finance-" + key).value]),
    );
    writeDraft(financeKey(area), {
        ...readDraft(financeKey(area)),
        form: financeForm,
        values,
    });
}
function financeClear() {
    financeRemember();
    financeGeneration++;
    financeContext = "";
    financeRows = [];
    financeChoices = {};
    financeSelected = "";
    financeForm = null;
    financeWritable = false;
    for (const id of [
        "finance-list",
        "finance-detail",
        "finance-retained-body",
    ])
        $(id).replaceChildren();
    $("finance-form").reset();
    $("finance-form").hidden = true;
    $("finance-body").hidden = true;
    $("finance-retained").hidden = true;
    financeMessage(
        "Current financial access must be checked before packets are displayed.",
    );
}
function financeDenied(error) {
    if (![401, 403, 404].includes(error.status)) return false;
    // Never retain visible financial metadata after a current-role denial.
    financeForm = null;
    financeWritable = false;
    financeClear();
    document.dispatchEvent(new CustomEvent("enterprise:reset"));
    financeMessage(
        "Financial access is unavailable. Reload the authorized workspace to check current permissions.",
        true,
    );
    return true;
}
function financeLock() {
    const pending = financePending.get(area),
        locked = Boolean(pending);
    $("finance-new").disabled = locked || !financeWritable;
    $("finance-new").hidden = !financeWritable;
    $("finance-retry").hidden = !pending;
    $("finance-retry").disabled = pending?.busy || !financeWritable;
    $("finance-retained").hidden = !pending;
    $("finance-retained-body").textContent = pending
        ? JSON.stringify(pending.body, null, 2)
        : "";
    for (const element of $("finance-workspace").querySelectorAll(
        "form input,form select,form textarea,form button,[data-finance-edit]",
    ))
        element.disabled = locked || !financeWritable;
}
function financeRestore() {
    const held = readDraft(financeKey(area));
    const body = held.pending;
    if (
        body &&
        body.area_id === area &&
        typeof body.id === "string" &&
        typeof body.request_id === "string" &&
        Number.isInteger(body.expected_version) &&
        [
            "packet.save",
            "packet.submit",
            "packet.review",
            "packet.handoff",
            "packet.reopen",
            "packet.external_complete",
        ].includes(body.action)
    )
        financePending.set(area, { body, busy: false });
    if (!body && held.form && held.values) financeOpen(null, held);
}
function financeList() {
    $("finance-list").innerHTML =
        financeRows
            .map((row) => {
                const r = financeValues(row),
                    owner = (financeChoices.approvers || []).find(
                        (p) => String(p.id) === String(r.next_owner_id),
                    );
                return `<button type="button" class="finance-record" data-finance-record="${esc(r.id)}" aria-pressed="${r.id === financeSelected}"><strong>${esc(r.title)}</strong><small>${esc(r.status)} · v${esc(r.version)} · ${esc(r.currency)} ${esc(r.amount_minor)} minor units</small><small>Next reviewer: ${esc(owner?.name || r.next_owner_id || "Unassigned")} · Fund reference: ${esc(r.fund_code || "Not recorded")}</small></button>`;
            })
            .join("") ||
        '<p class="caption">No packets in this authorized bounded result.</p>';
    for (const button of $("finance-list").querySelectorAll(
        "[data-finance-record]",
    ))
        button.onclick = () => financeSelect(button.dataset.financeRecord);
}
function financeTransition(row, action, label, extra = "") {
    const form = document.createElement("form");
    form.className = "stack finance-action";
    form.setAttribute("aria-label", label);
    form.innerHTML = `<h4>${esc(label)}</h4>${extra}<label>Reason / handoff note<textarea name="reason" required maxlength="1000" rows="2"></textarea></label><label class="finance-confirm"><input type="checkbox" name="confirmed" required> I reviewed this exact packet version. This records a handoff or procedural decision, not a payment.</label><button class="button" type="submit">${esc(label)}</button>`;
    form.onsubmit = (event) => {
        event.preventDefault();
        const f = event.currentTarget,
            command = {
                action,
                id: row.id,
                expected_version: row.version,
                reason: f.elements.reason.value.trim(),
                confirmed: f.elements.confirmed.checked,
            };
        for (const key of ["decision", "next_owner_id", "external_reference"])
            if (f.elements[key])
                command[key] =
                    key === "next_owner_id"
                        ? Number(f.elements[key].value)
                        : f.elements[key].value.trim();
        financeSend(command);
    };
    return form;
}
function financeDetail(row, history) {
    const r = financeValues(row),
        host = $("finance-detail");
    host.innerHTML = `<h3>${esc(r.title)}</h3><p class="caption">${esc(r.status)} · Exact packet v${esc(r.version)}</p><dl class="finance-facts">${financeFact("Project", r.project_title || r.project_id)}${financeFact("Contractor", r.contractor_title || r.contractor_id)}${financeFact("Amount", `${r.currency} ${r.amount_minor} minor units`)}${financeFact("Document reference", r.document_reference)}${financeFact("Fund / budget reference", r.fund_code)}${financeFact("Restriction source", r.restriction_reference)}${financeFact("Existing source reference", r.source_reference)}${financeFact("Next reviewer ID", r.next_owner_id)}${financeFact("External reference", r.external_completion?.reference)}</dl><p class="procedure-notice">Manual fund and restriction references need Finance verification. Internal packet approval does not authorize or execute payment and does not confirm an available fund balance.</p><div id="finance-actions"></div><h4>Packet history</h4><div class="finance-history" id="finance-history"></div>`;
    const actions = $("finance-actions"),
        decision = Boolean(r.can_decide);
    if (financeWritable && r.status !== "externally_completed") {
        if (["draft", "needs_changes"].includes(r.status)) {
            const edit = document.createElement("button");
            edit.type = "button";
            edit.className = "button";
            edit.dataset.financeEdit = "";
            edit.textContent = "Edit packet draft";
            edit.onclick = () => financeOpen(r);
            actions.append(edit);
        }
        if (["submitted", "approved", "needs_changes"].includes(r.status))
            actions.append(
                financeTransition(
                    r,
                    "packet.reopen",
                    "Reopen packet and invalidate prior review",
                ),
            );
        if (["draft", "needs_changes"].includes(r.status))
            actions.append(
                financeTransition(
                    r,
                    "packet.submit",
                    "Submit exact packet for review",
                ),
            );
        if (decision && r.status === "submitted")
            actions.append(
                financeTransition(
                    r,
                    "packet.review",
                    "Confirm internal packet review",
                    '<label>Decision<select name="decision" required><option value="">Choose decision</option><option value="approved">Packet approved for existing Finance process</option><option value="needs_changes">Return for changes</option></select></label>',
                ),
            );
        actions.append(
            financeTransition(
                r,
                "packet.handoff",
                "Confirm reviewer handoff",
                `<label>Next authorized reviewer<select name="next_owner_id" required><option value="">Choose reviewer</option>${(financeChoices.approvers || []).map((p) => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join("")}</select></label>`,
            ),
        );
        if (
            String(r.next_owner_id) === String(data.viewer_id || viewer) &&
            r.status === "approved"
        )
            actions.append(
                financeTransition(
                    r,
                    "packet.external_complete",
                    "Record existing Finance completion",
                    '<label>Existing external approval / completion reference<input name="external_reference" maxlength="500" required></label><p class="caption">This records a reference supplied by Finance. It does not verify payment or change accounting records.</p>',
                ),
            );
    }
    $("finance-history").innerHTML =
        (history?.items || [])
            .map(
                (h) =>
                    `<article><strong>Version ${esc(h.version)}</strong><p>${esc(h.at)} · actor ${esc(h.actor_id)}</p><pre>${esc(JSON.stringify(h.event || h, null, 2))}</pre></article>`,
            )
            .join("") || '<p class="caption">No revision history returned.</p>';
    financeLock();
}
async function financeSelect(id) {
    if (
        !financeRows.some((r) => r.id === id) ||
        !data.features?.finance ||
        section !== "finance"
    )
        return;
    financeRemember();
    financeSelected = id;
    financeForm = null;
    $("finance-form").hidden = true;
    const token = financeToken();
    try {
        const [row, history] = await Promise.all([
            api(financeEndpoint("/" + encodeURIComponent(id))),
            api(financeEndpoint("/" + encodeURIComponent(id) + "/history")),
        ]);
        if (!financeCurrent(token) || financeSelected !== id) return;
        financeDetail(row, history);
        financeList();
    } catch (error) {
        if (financeCurrent(token) && !financeDenied(error))
            financeMessage(error.message, true);
    }
}
async function financeLoad() {
    if (!area || section !== "finance" || !data.features?.finance) return;
    const token = financeToken();
    financeMessage("Checking current financial access and packet ownership.");
    $("finance-body").hidden = true;
    try {
        const result = await api(financeEndpoint("", { area_id: area }));
        if (!financeCurrent(token)) return;
        financeRows = result.items || [];
        financeChoices = result;
        financeWritable = Boolean(result.can_manage && data.can_coordinate);
        $("finance-body").hidden = false;
        $("finance-coverage").textContent =
            `As of ${result.as_of || "current load"}. ${result.coverage || "Bounded authorized packets; no authoritative fund balance or accounting synchronization."}`;
        financeList();
        financeRestore();
        financeLock();
        financeMessage(
            transport.mode === "sample"
                ? "Fictional browser-only simulation. No college finance records or payment provider is connected."
                : "Current financial access checked. Each handoff and decision requires its own explicit confirmation.",
        );
        if (
            financeSelected &&
            financeRows.some((r) => r.id === financeSelected)
        )
            await financeSelect(financeSelected);
    } catch (error) {
        if (financeCurrent(token) && !financeDenied(error))
            financeMessage(error.message, true);
    }
}
function financeOpen(row = null, retained = null) {
    if (
        !financeWritable ||
        financePending.has(area) ||
        (row && !["draft", "needs_changes"].includes(row.status))
    )
        return;
    financeRemember();
    const r = financeValues(row || {});
    financeForm = retained?.form || {
        id: r.id || crypto.randomUUID(),
        version: r.version || 0,
    };
    $("finance-form").reset();
    $("finance-project").innerHTML =
        '<option value="">Choose authorized project</option>' +
        (financeChoices.projects || data.projects || [])
            .map(
                (p) =>
                    `<option value="${esc(p.id)}">${esc(p.title || p.name)}</option>`,
            )
            .join("");
    $("finance-contractor").innerHTML =
        '<option value="">No contractor reference</option>' +
        (financeChoices.contractors || [])
            .map(
                (p) =>
                    `<option value="${esc(p.id)}">${esc(p.title || p.name)}</option>`,
            )
            .join("");
    $("finance-owner").innerHTML =
        '<option value="">Choose authorized reviewer</option>' +
        (financeChoices.approvers || [])
            .map((p) => `<option value="${esc(p.id)}">${esc(p.name)}</option>`)
            .join("");
    const values = retained?.values || {
        title: r.title || "",
        project: r.project_id || "",
        contractor: r.contractor_id || "",
        amount: r.amount_minor ?? "",
        currency: r.currency || "USD",
        document: r.document_reference || "",
        fund: r.fund_code || "",
        restriction: r.restriction_reference || "",
        source: r.source_reference || "",
        owner: r.next_owner_id || "",
    };
    for (const [key, value] of Object.entries(values))
        if ($("finance-" + key)) $("finance-" + key).value = value;
    $("finance-form-heading").textContent = financeForm.version
        ? "Edit packet; prior review must be renewed"
        : "New review packet";
    $("finance-form").hidden = false;
    financeLock();
    $("finance-title").focus();
}
async function financeSend(command) {
    if (!financeWritable || section !== "finance" || !data.features?.finance)
        return;
    const scope = area;
    let pending = financePending.get(scope);
    if (!pending) {
        pending = {
            body: {
                ...command,
                area_id: scope,
                request_id: crypto.randomUUID(),
            },
            busy: false,
        };
        financePending.set(scope, pending);
        writeDraft(financeKey(scope), {
            ...readDraft(financeKey(scope)),
            pending: pending.body,
        });
    }
    if (pending.busy) return;
    pending.busy = true;
    const token = financeToken();
    financeLock();
    financeMessage("Recording this exact packet version and action.");
    try {
        const result = await api(financeEndpoint("/commands"), pending.body);
        financePending.delete(scope);
        writeDraft(financeKey(scope), {});
        if (!financeCurrent(token)) return;
        financeSelected = pending.body.id;
        financeForm = null;
        $("finance-form").hidden = true;
        await financeLoad();
        if (financeCurrent(token))
            financeMessage(
                result.replayed
                    ? "Existing packet action recovered without a second change."
                    : "Packet action recorded. No payment or external notification was sent.",
            );
    } catch (error) {
        if ([401, 403, 404, 409, 422].includes(error.status)) {
            financePending.delete(scope);
            writeDraft(financeKey(scope), {});
        }
        if (!financeCurrent(token) || financeDenied(error)) return;
        financeMessage(
            error.message +
                (financePending.has(scope)
                    ? " Outcome unconfirmed. Retry this exact action; editing remains locked."
                    : " Refresh current packets before preparing another action."),
            true,
        );
    } finally {
        pending.busy = false;
        if (financeCurrent(token)) financeLock();
    }
}
$("finance-new").onclick = () => financeOpen();
$("finance-refresh").onclick = () => {
    financeGeneration++;
    financeLoad();
};
$("finance-close").onclick = () => {
    financeRemember();
    $("finance-form").hidden = true;
    financeForm = null;
};
$("finance-retry").onclick = () => {
    const held = financePending.get(area);
    if (held) financeSend(held.body);
};
for (const field of $("finance-fields").querySelectorAll(
    "input,select,textarea",
))
    field.addEventListener("input", financeRemember);
$("finance-form").onsubmit = (event) => {
    event.preventDefault();
    if (!financeForm) return;
    financeSend({
        action: "packet.save",
        id: financeForm.id,
        expected_version: financeForm.version,
        title: $("finance-title").value.trim(),
        project_id: $("finance-project").value,
        contractor_id: $("finance-contractor").value || null,
        amount_minor: Number($("finance-amount").value),
        currency: $("finance-currency").value,
        document_reference: $("finance-document").value.trim(),
        fund_code: $("finance-fund").value.trim() || null,
        restriction_reference: $("finance-restriction").value.trim() || null,
        source_reference: $("finance-source").value.trim(),
        next_owner_id: Number($("finance-owner").value),
    });
};
document.addEventListener("enterprise:reset", financeClear);
document.addEventListener("enterprise:tour-context", (event) => {
    if (!event.detail) financeClear();
});
document.addEventListener("enterprise:render", () => {
    const enabled = Boolean(data.features?.finance);
    $("finance-nav").hidden = !enabled;
    $("finance-workspace").hidden = section !== "finance";
    if (section !== "finance") {
        if (financeContext) financeClear();
        return;
    }
    for (const id of [
        "work",
        "campus-home",
        "ask",
        "contractors",
        "operations-people",
        "roll-call-panel",
        "certificate-workspace",
        "quarterly-workspace",
        "resource-workspace",
    ])
        if ($(id)) $(id).hidden = true;
    $("heading").textContent = "Keep financial handoffs accountable.";
    $("breadcrumb").textContent = "Invoice & fund packets";
    const context = area + ":" + String(data.viewer_id || viewer);
    if (context === financeContext) return;
    financeClear();
    financeContext = context;
    if (!enabled) {
        financeMessage(
            "Invoice packets are disabled or unavailable to your current financial role.",
        );
        return;
    }
    financeLoad();
});
