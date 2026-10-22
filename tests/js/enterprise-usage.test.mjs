import test from "node:test";
import assert from "node:assert/strict";
import vm from "node:vm";
import { readFile } from "node:fs/promises";
const source = await readFile(
    new URL("../../resources/js/enterprise/usage.js", import.meta.url),
    "utf8",
);
class Element {
    constructor(tag, document) {
        this.tagName = tag;
        this.ownerDocument = document;
        this.children = [];
        this.dataset = {};
        this.attributes = {};
        this.listeners = new Map();
        this._text = "";
        this.disabled = false;
        this.hidden = false;
    }
    set textContent(value) {
        this._text = String(value);
        this.children = [];
    }
    get textContent() {
        return (
            this._text +
            this.children.map((child) => child.textContent).join(" ")
        );
    }
    append(...nodes) {
        this.children.push(...nodes);
    }
    replaceChildren(...nodes) {
        this._text = "";
        this.children = [...nodes];
    }
    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }
    addEventListener(name, listener) {
        this.listeners.set(name, listener);
    }
    click() {
        if (!this.disabled) return this.listeners.get("click")?.();
    }
}
function fixture() {
    const forbidden = [],
        document = {
            createElement(tag) {
                return new Element(tag, this);
            },
        };
    const context = vm.createContext({
        document,
        console,
        fetch() {
            forbidden.push("fetch");
            throw new Error("No IO");
        },
        sessionStorage: {
            getItem() {
                forbidden.push("storage");
                throw new Error("No storage");
            },
        },
        EverbranchTransport: {
            create() {
                forbidden.push("auth/transport");
                throw new Error("No transport");
            },
        },
    });
    vm.runInContext(source, context);
    return {
        api: context.EverbranchUsage,
        create: context.createUsageOverview,
        host: document.createElement("section"),
        forbidden,
    };
}
const nodes = (root) =>
    root.children.flatMap((child) => [child, ...nodes(child)]);
const totals = () => ({
    requests: 4,
    completed: 1,
    reserved: 1,
    uncertain: 1,
    cancelled: 1,
    unreconciled: 1,
    input_tokens: 100,
    output_tokens: 40,
    reported_token_records: 1,
    token_unknown_records: 3,
    original_reserved_microusd: 50000,
    settled_usage_derived_microusd: 123,
    reserved_exposure_microusd: 20000,
    uncertain_reserved_microusd: 10000,
    estimated_total_exposure_microusd: 30123,
    exposure_unknown_records: 1,
});
const usage = () => ({
    sample_data: true,
    scope: { type: "tenant", area_id: null },
    as_of: "2026-10-23T08:30:00Z",
    currency: "USD",
    money_unit: "microusd",
    ledger_state: "synthetic",
    totals: totals(),
    departments: [
        {
            id: "department-one",
            name: "Configured sample department",
            totals: totals(),
            purposes: [
                {
                    purpose: "ask",
                    label: "Ask Everbranch — fictional sample",
                    totals: totals(),
                },
            ],
        },
    ],
    coverage: {
        partial: true,
        scanned_records: 4,
        record_scan_limit: 1000,
        period: { start: "2026-10-01", end: "2026-10-23" },
    },
    technical_budget: {
        ready: false,
        status: "Provider configuration unverified",
        provider_calls_verified: false,
    },
    provider_invoice_verified: false,
    read_only: true,
    basis: "Fictional permission-filtered ledger projection. No paid call.",
});

test("exact microusd display preserves tiny costs and refuses invalid or misleading amounts", () => {
    const { api } = fixture();
    assert.equal(api.formatMicroUsd(0), "$0.000000");
    assert.equal(api.formatMicroUsd(1), "$0.000001");
    assert.equal(api.formatMicroUsd(123), "$0.000123");
    assert.equal(api.formatMicroUsd(2000000), "$2.000000");
    assert.equal(
        api.formatMicroUsd(Number.MAX_SAFE_INTEGER),
        "$9,007,199,254.740991",
    );
    for (const bad of [
        null,
        undefined,
        -1,
        0.1,
        NaN,
        Infinity,
        "1000",
        Number.MAX_SAFE_INTEGER + 1,
    ])
        assert.equal(api.formatMicroUsd(bad), "Unavailable");
});

test("sample scope shows department and feature estimates, reservations and unknowns without billing or provider verification", () => {
    const f = fixture();
    f.create(f.host, { usage: usage() });
    const text = f.host.textContent;
    assert.match(text, /FICTIONAL SAMPLE USAGE/);
    assert.match(text, /fictional departmental and feature usage/);
    assert.match(text, /Configured sample department/);
    assert.match(text, /Ask Everbranch — fictional sample/);
    assert.match(text, /\$0\.000123/);
    assert.match(text, /\$0\.020000/);
    assert.match(text, /\$0\.010000/);
    assert.match(text, /\$0\.030123/);
    assert.match(text, /Reservations|reservation is not a charge/);
    assert.match(text, /Exposure is not fully known/);
    assert.match(text, /Missing usage is unknown, not zero/);
    assert.match(text, /Partial coverage/);
    assert.match(text, /2026-10-01 through 2026-10-23/);
    assert.match(text, /Provider configuration unverified/);
    assert.match(
        text,
        /not a price, included entitlement, remaining commercial balance or approved spending cap/,
    );
    assert.match(
        text,
        /Manual core workflows remain usable when AI is unavailable/,
    );
    assert.deepEqual(f.forbidden, []);
    const ids = nodes(f.host)
        .map((node) => node.id)
        .filter(Boolean);
    for (const id of [
        "enterprise-usage-summary",
        "enterprise-usage-estimates",
        "enterprise-usage-tokens",
        "enterprise-usage-departments",
        "enterprise-usage-coverage",
        "enterprise-usage-technical-budget",
    ])
        assert.ok(ids.includes(id));
});

test("missing and incompatible data remains unavailable instead of invented zero or currency conversion", () => {
    const f = fixture(),
        input = usage();
    input.sample_data = false;
    input.currency = "EUR";
    input.money_unit = "cents";
    input.totals = { requests: null, exposure_unknown_records: null };
    input.departments = [];
    input.as_of = null;
    input.coverage = {};
    f.create(f.host, { usage: input });
    assert.match(f.host.textContent, /CURRENT AUTHORIZED USAGE LEDGER/);
    assert.match(
        f.host.textContent,
        /expected USD \/ microusd denomination was not supplied/,
    );
    assert.match(f.host.textContent, /Unavailable/);
    assert.doesNotMatch(f.host.textContent, /\$0\./);
    assert.match(f.host.textContent, /freshness unverified/);
    assert.match(f.host.textContent, /not proof of zero usage/);
    f.create(f.host, {});
    assert.match(f.host.textContent, /No amount or activity is inferred/);
});

test("display whitelists aggregate fields, treats supplied text as inert and never renders user records or hidden objects", () => {
    const f = fixture(),
        input = usage();
    input.questions = ["PRIVATE_QUESTION"];
    input.identities = ["PRIVATE_IDENTITY"];
    input.coverage.period = { secret: "PRIVATE_PERIOD_PAYLOAD" };
    input.basis = { secret: "PRIVATE_BASIS_PAYLOAD" };
    input.departments[0].name = '<img src=x onerror="alert(1)">';
    input.departments[0].user_email = "PRIVATE_EMAIL";
    f.create(f.host, { usage: input });
    assert.match(f.host.textContent, /<img src=x/);
    assert.equal(
        nodes(f.host).filter((node) => node.tagName === "img").length,
        0,
    );
    assert.doesNotMatch(
        f.host.textContent,
        /PRIVATE_QUESTION|PRIVATE_IDENTITY|PRIVATE_PERIOD_PAYLOAD|PRIVATE_BASIS_PAYLOAD|PRIVATE_EMAIL/,
    );
    assert.deepEqual(f.forbidden, []);
});

test("backend and sample object basis preserves labeled estimate, invoice and coverage boundaries using only documented own string fields", () => {
    const f = fixture(),
        input = usage();
    input.scope.type = "authorized_departments";
    // Matches UsageOverview::build() and the explicit sample adapter DTO shape.
    input.basis = {
        source: "Existing permission-filtered ent_ai_usage ledger; no provider account or billing query.",
        settled_cost:
            "Derived from recorded provider-reported input/output token usage and request-time configured rates; not a verified provider invoice.",
        reserved_exposure:
            "Known maximum cost retained for pending, uncertain or unreconciled records; unknown exposure is separately counted.",
        tokens: "Only recorded completed-request input/output tokens are summed. Missing, pending and uncertain usage is unknown.",
        zero_records:
            "No ledger records in this checked scope is not proof of zero provider-account spend.",
        commercial:
            "Existing technical readiness only. No college price, billing quota, subscription, new cap or allowance is set.",
        snapshot:
            "Sources are read during generation, not reconstructed as an atomic historical account statement.",
        provider_payload: "PRIVATE_PROVIDER_PAYLOAD",
        user_identity: "PRIVATE_USER_IDENTITY",
        question: "PRIVATE_QUESTION_BODY",
    };
    const projection = f.api.model(input);
    assert.equal(projection.basis.length, 7);
    f.create(f.host, { usage: input });
    for (const label of [
        "Ledger source:",
        "Usage-derived estimate:",
        "Reservation and uncertainty:",
        "Token coverage:",
        "Empty ledger scope:",
        "Budget and commercial boundary:",
        "Snapshot coverage:",
    ])
        assert.match(f.host.textContent, new RegExp(label));
    assert.match(f.host.textContent, /not a verified provider invoice/);
    assert.match(
        f.host.textContent,
        /not proof of zero provider-account spend/,
    );
    assert.match(
        f.host.textContent,
        /not reconstructed as an atomic historical account statement/,
    );
    assert.doesNotMatch(
        f.host.textContent,
        /PRIVATE_PROVIDER_PAYLOAD|PRIVATE_USER_IDENTITY|PRIVATE_QUESTION_BODY/,
    );
    input.basis = Object.create({
        source: "INHERITED_SOURCE",
        commercial: "INHERITED_COMMERCIAL",
    });
    input.basis.settled_cost = { raw: "PRIVATE_NESTED_PROVIDER" };
    input.basis.tokens = '<script>alert("inert")</script>';
    f.create(f.host, { usage: input });
    assert.match(f.host.textContent, /Token coverage: <script>/);
    assert.equal(
        nodes(f.host).filter((node) => node.tagName === "script").length,
        0,
    );
    assert.doesNotMatch(
        f.host.textContent,
        /INHERITED_SOURCE|INHERITED_COMMERCIAL|PRIVATE_NESTED_PROVIDER/,
    );
    assert.deepEqual(f.forbidden, []);
});

test("bounded presentation reports omitted department cards without replacing source totals", () => {
    const f = fixture(),
        input = usage();
    input.departments = Array.from({ length: 101 }, (_, index) => ({
        id: "department-" + index,
        name: "Configured department " + index,
        totals: totals(),
        purposes: [],
    }));
    input.totals.requests = 9876;
    const result = f.api.model(input);
    assert.equal(result.departments.length, 100);
    assert.equal(result.totals.requests, 9876);
    assert.equal(result.displayLimited, true);
    f.create(f.host, { usage: input });
    assert.match(f.host.textContent, /Display limit reached/);
    assert.match(f.host.textContent, /totals have not been recalculated/);
});

test("department selection invokes supplied callback with only the current department ID and destroy fences late results", async () => {
    const f = fixture(),
        calls = [];
    let release;
    const view = f.create(f.host, {
        usage: usage(),
        onDepartment: (id) => {
            calls.push(id);
            return new Promise((resolve) => {
                release = resolve;
            });
        },
    });
    const button = nodes(f.host).find((node) => node.tagName === "button");
    assert.equal(
        button.attributes["aria-label"],
        "View usage for Configured sample department",
    );
    const pending = button.click();
    assert.deepEqual(calls, ["department-one"]);
    assert.equal(button.disabled, true);
    button.click();
    assert.equal(calls.length, 1);
    view.destroy();
    release({ available: true });
    await pending;
    assert.equal(f.host.children.length, 0);
    assert.equal(f.host.hidden, true);
    assert.deepEqual(f.forbidden, []);
    const malformed = usage();
    malformed.departments[0].id = "../other-tenant";
    f.create(f.host, {
        usage: malformed,
        onDepartment: () => assert.fail("Invalid ID must not navigate"),
    });
    assert.equal(
        nodes(f.host).filter((node) => node.tagName === "button").length,
        0,
    );
});
