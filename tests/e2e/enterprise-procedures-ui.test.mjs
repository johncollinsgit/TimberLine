import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { webcrypto } from "node:crypto";
import vm from "node:vm";
const source = await readFile(
    new URL(
        "../../resources/views/enterprise/procedures-script.blade.php",
        import.meta.url,
    ),
    "utf8",
);
const scope = "10000000-0000-4000-8000-000000000001";
const id = "20000000-0000-4000-8000-000000000001";
class Node {
    constructor() {
        this.value = "";
        this.hidden = false;
        this.disabled = false;
        this.children = [];
        this.classList = { toggle() {} };
        this.elements = {};
    }
    append(...nodes) {
        this.children.push(...nodes);
    }
    after(...nodes) {
        this.afterChildren = nodes;
    }
    replaceChildren(...nodes) {
        this.children = [...nodes];
        this.innerHTML = "";
        this.textContent = "";
    }
    querySelectorAll() {
        return [];
    }
    setAttribute() {}
    addEventListener() {}
    reset() {}
    focus() {}
    click() {}
    remove() {}
}
function fixture({
    mode = "private",
    review = true,
    features = { certificates: true, reports: true },
    drafts = new Map(),
    handler,
} = {}) {
    const nodes = new Map(),
        listeners = new Map(),
        calls = [],
        fetchCalls = [];
    const get = (name) => {
        if (!nodes.has(name)) nodes.set(name, new Node());
        return nodes.get(name);
    };
    const result = {
        items: [],
        contractors: [],
        can_manage: true,
        as_of_on: "2026-10-22",
        timezone: "America/New_York",
        coverage: "Bounded synthetic test response",
    };
    const context = vm.createContext({
        URL,
        URLSearchParams,
        Blob,
        TextDecoder,
        crypto: webcrypto,
        Date,
        Map,
        console,
        area: scope,
        section: "people",
        viewer: "manager",
        workspace: "tenant",
        tenantSlug: "synthetic",
        data: {
            can_coordinate: true,
            can_review: review,
            features,
            projects: [],
        },
        transport: { mode, href: (value) => value },
        urls: { enterprise: "/enterprise" },
        $: get,
        esc: (value) =>
            String(value ?? "").replace(
                /[&<>"']/g,
                (char) =>
                    ({
                        "&": "&amp;",
                        "<": "&lt;",
                        ">": "&gt;",
                        '"': "&quot;",
                        "'": "&#39;",
                    })[char],
            ),
        readDraft: (key) => drafts.get(key) || {},
        writeDraft: (key, value) => drafts.set(key, structuredClone(value)),
        api: async (url, body) => {
            calls.push({ url, body: body ? structuredClone(body) : undefined });
            return handler ? handler(url, body) : result;
        },
        fetch(...args) {
            fetchCalls.push(args);
            throw new Error(
                "No browser download or external call belongs in this focused fixture",
            );
        },
        location: {
            href: "https://workspace.example/enterprise",
            origin: "https://workspace.example",
        },
        document: {
            body: new Node(),
            createElement: () => new Node(),
            addEventListener: (name, fn) => {
                if (!listeners.has(name)) listeners.set(name, []);
                listeners.get(name).push(fn);
            },
            dispatchEvent: (event) => {
                for (const fn of listeners.get(event.type) || []) fn(event);
            },
        },
        CustomEvent: class {
            constructor(type, init = {}) {
                this.type = type;
                this.detail = init.detail;
            }
        },
        setTimeout() {},
    });
    vm.runInContext(source, context);
    const dispatch = (type, detail) =>
        context.document.dispatchEvent({ type, detail });
    const settle = () => new Promise((resolve) => setImmediate(resolve));
    return {
        context,
        nodes,
        get,
        calls,
        fetchCalls,
        drafts,
        result,
        dispatch,
        settle,
    };
}
test("disabled flags, nonmanager roles and unsupported transports never call certificate or report APIs", async () => {
    for (const setup of [
        { mode: "unsupported" },
        { review: false },
        { features: { certificates: false, reports: false } },
        { mode: "sample", review: false },
        { mode: "sample", features: { certificates: false, reports: false } },
    ]) {
        const f = fixture(setup);
        f.dispatch("enterprise:render");
        await f.settle();
        await vm.runInContext("certificateLoad()", f.context);
        f.context.section = "roll_call";
        f.dispatch("enterprise:render");
        await f.get("quarterly-period").onsubmit({ preventDefault() {} });
        assert.deepEqual(f.calls, []);
        assert.equal(f.get("certificate-body").hidden, true);
        assert.equal(f.get("quarterly-period").hidden, true);
        assert.deepEqual(f.fetchCalls, []);
    }
});
test("authorized sample procedures use only the explicit sample transport and clear records on role loss", async () => {
    const report = {
        schema_version: 1,
        report_type: "period_activity",
        sample_data: true,
        label: "FICTIONAL BROWSER SAMPLE — operational period export",
        generated_at_utc: "2026-10-22T10:00:00Z",
        window: {
            timezone: "America/New_York",
            start_local: "2026-10-01",
            end_local_inclusive: "2026-10-22",
            start_utc: "2026-10-01T04:00:00Z",
            end_utc_exclusive: "2026-10-23T04:00:00Z",
            observed_through_utc: "2026-10-22T10:00:00Z",
            period_state: "in_progress",
            period_in_progress: true,
        },
        current_records: [],
        recorded_events: [],
        coverage: {},
        exclusions: [],
    };
    const f = fixture({
        mode: "sample",
        handler: async (url) => {
            if (url.includes("/reports/quarterly.json"))
                return {
                    sample_export: true,
                    mime: "application/json",
                    content: JSON.stringify(report),
                };
            if (url.includes("/reports/quarterly")) return report;
            return {
                ...f.result,
                items: [
                    {
                        id,
                        title: "Fictional sample certificate",
                        contractor_title: "Fictional contractor",
                        status: "active",
                        version: 1,
                        accepted: null,
                        pending: null,
                        reminder_reasons: ["unreviewed"],
                    },
                ],
            };
        },
    });
    f.dispatch("enterprise:render");
    await f.settle();
    assert.equal(f.calls.length, 2);
    assert.ok(
        f.calls.every((call) =>
            call.url.startsWith("/enterprise/certificates"),
        ),
    );
    assert.equal(f.get("certificate-body").hidden, false);
    assert.match(
        f.get("certificate-status").textContent,
        /Fictional browser-only/,
    );
    assert.match(
        f.get("certificate-list").innerHTML,
        /Fictional sample certificate/,
    );
    f.context.section = "roll_call";
    f.dispatch("enterprise:render");
    assert.equal(f.get("quarterly-period").hidden, false);
    assert.equal(
        f.calls.length,
        2,
        "Opening a sample report must not automatically generate or download it",
    );
    await f.get("quarterly-period").onsubmit({ preventDefault() {} });
    assert.equal(f.calls.length, 3);
    assert.ok(f.calls[2].url.startsWith("/enterprise/reports/quarterly?"));
    await vm.runInContext(
        "quarterlyDownload({preventDefault(){}},'json')",
        f.context,
    );
    assert.equal(f.calls.length, 4);
    assert.ok(f.calls[3].url.startsWith("/enterprise/reports/quarterly.json?"));
    assert.match(
        f.get("quarterly-status").textContent,
        /Fictional browser-only JSON snapshot/,
    );
    assert.deepEqual(
        f.fetchCalls,
        [],
        "Authorized sample export must never fall through to browser/server fetch",
    );
    f.context.data.can_review = false;
    f.dispatch("enterprise:tour-context", null);
    f.dispatch("enterprise:render");
    await f.settle();
    assert.equal(f.calls.length, 4);
    assert.equal(f.get("certificate-body").hidden, true);
    assert.equal(f.get("certificate-list").innerHTML, "");
    assert.equal(f.get("quarterly-preview-body").children.length, 0);
    assert.equal(f.get("quarterly-period").hidden, true);
    assert.equal(f.get("quarterly-json").hidden, true);
});

test("a private authorization denial clears procedures and never substitutes fictional sample records", async () => {
    const f = fixture({
        handler: async () => {
            const error = new Error("Current access denied");
            error.status = 403;
            throw error;
        },
    });
    f.dispatch("enterprise:render");
    await f.settle();
    assert.equal(f.calls.length, 2);
    assert.equal(f.get("certificate-body").hidden, true);
    assert.equal(f.get("certificate-list").innerHTML, "");
    assert.equal(f.get("certificate-retained-body").textContent, "");
    assert.equal(f.get("quarterly-period").hidden, true);
    assert.deepEqual(f.fetchCalls, []);
    assert.match(
        f.get("certificate-status").textContent,
        /access is unavailable/,
    );
});

test("a reset blocks a delayed certificate result from repopulating former manager records", async () => {
    let release;
    const held = new Promise((resolve) => {
        release = resolve;
    });
    const f = fixture({ handler: () => held });
    f.dispatch("enterprise:render");
    assert.equal(f.calls.length, 2);
    f.dispatch("enterprise:tour-context", null);
    release({
        ...f.result,
        items: [{ id, title: "Former restricted certificate" }],
    });
    await f.settle();
    assert.equal(f.get("certificate-body").hidden, true);
    assert.ok(
        !String(f.get("certificate-list").innerHTML).includes(
            "Former restricted",
        ),
    );
});

test("new manager features remain reachable independently of Operations and Roll Call flags", async () => {
    const f = fixture({
        features: {
            certificates: true,
            reports: true,
            operations: false,
            roll_call: false,
        },
    });
    f.get("people-nav").hidden = true;
    f.get("roll-call-nav").hidden = true;
    f.dispatch("enterprise:render");
    await f.settle();
    assert.equal(f.get("people-nav").hidden, false);
    assert.equal(f.get("operations-people").hidden, true);
    assert.equal(f.get("certificate-workspace").hidden, false);
    assert.equal(f.get("certificate-body").hidden, false);
    assert.ok(f.calls.every((row) => row.url.includes("/certificates")));
    f.context.section = "roll_call";
    f.dispatch("enterprise:render");
    assert.equal(f.get("roll-call-nav").hidden, false);
    assert.equal(f.get("roll-call-panel").hidden, true);
    assert.equal(f.get("quarterly-workspace").hidden, false);
    assert.equal(f.get("quarterly-period").hidden, false);
    assert.equal(
        f.calls.length,
        2,
        "Report screen must not call disabled Roll Call or generate automatic reports",
    );
});
test("an uncertain certificate action survives reload and retries the exact version and request IDs only after manager read", async () => {
    const drafts = new Map();
    let first = true;
    const f = fixture({
        drafts,
        handler: async (url, body) => {
            if (body) {
                if (first) {
                    first = false;
                    throw new Error("Response interrupted");
                }
                return { replayed: true };
            }
            return f.result;
        },
    });
    f.dispatch("enterprise:render");
    await f.settle();
    await vm.runInContext(
        `certificateSend({action:'certificate.metadata',id:'${id}',expected_version:7,title:'Unchanged reviewed record',renewal_contact:null,warning_lead_days:null,review_due_on:null})`,
        f.context,
    );
    const exact = f.calls.find((row) => row.body).body;
    assert.equal(f.get("certificate-retry").hidden, false);
    assert.equal(
        drafts.get("certificate-operation:" + scope).pending.body.request_id,
        exact.request_id,
    );
    const next = fixture({
        drafts,
        handler: async (_url, body) =>
            body ? { replayed: true } : next.result,
    });
    next.dispatch("enterprise:render");
    await next.settle();
    assert.equal(next.get("certificate-retry").hidden, false);
    await next.get("certificate-retry").onclick();
    assert.deepEqual(next.calls.find((row) => row.body).body, exact);
    assert.deepEqual(drafts.get("certificate-operation:" + scope), {});
    const denied = fixture({
        drafts: new Map([
            ["certificate-operation:" + scope, { pending: { body: exact } }],
        ]),
        review: false,
    });
    denied.dispatch("enterprise:render");
    await denied.settle();
    assert.equal(denied.get("certificate-body").hidden, true);
    assert.equal(denied.get("certificate-retained").hidden, true);
    assert.equal(denied.get("certificate-retained-body").textContent, "");
    assert.equal(denied.calls.length, 0);
});

test("future report windows are labeled not started and unsafe record links stay unavailable", () => {
    const f = fixture();
    const report = {
        schema_version: 1,
        label: "Authorized test report",
        generated_at_utc: "2026-10-22T10:00:00Z",
        window: {
            period_state: "future",
            period_in_progress: false,
            timezone: "America/New_York",
            start_local: "2027-01-01",
            end_local_inclusive: "2027-03-31",
        },
        basis: { current_state: "now", recorded_events: "selected period" },
        current_records: [],
        recorded_events: [],
        coverage: { partial: true },
        exclusions: [],
        snapshot_notice: "Dated snapshot",
    };
    f.context.testReport = report;
    vm.runInContext("quarterlyPaint(testReport,{area_id:area})", f.context);
    assert.match(
        f.get("quarterly-preview-body").children[0].innerHTML,
        /Period has not started/,
    );
    assert.doesNotMatch(
        f.get("quarterly-preview-body").children[0].innerHTML,
        /Period window ended/,
    );
    assert.equal(
        vm.runInContext("procedureSource('javascript:alert(1)')", f.context),
        null,
    );
    assert.equal(
        vm.runInContext(
            "procedureSource('https://other.example/secret')",
            f.context,
        ),
        null,
    );
});
test("quarterly preview sends the explicit period/timezone and invalidates downloads after input changes", async () => {
    const f = fixture();
    f.context.section = "roll_call";
    f.dispatch("enterprise:render");
    f.get("quarterly-mode").value = "date_range";
    f.get("quarterly-timezone").value = "America/New_York";
    f.get("quarterly-start").value = "2026-10-01";
    f.get("quarterly-end").value = "2026-10-22";
    const query = vm.runInContext("quarterlyQuery()", f.context);
    assert.deepEqual(JSON.parse(JSON.stringify(query)), {
        area_id: scope,
        mode: "date_range",
        timezone: "America/New_York",
        start_date: "2026-10-01",
        end_date: "2026-10-22",
    });
    f.get("quarterly-csv").hidden = false;
    f.get("quarterly-json").hidden = false;
    vm.runInContext("quarterlyInvalidate()", f.context);
    assert.equal(f.get("quarterly-csv").hidden, true);
    assert.equal(f.get("quarterly-json").hidden, true);
    assert.equal(
        f.calls.length,
        0,
        "Opening the report form must not generate an automatic export",
    );
});
