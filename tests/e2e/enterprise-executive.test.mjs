import test from "node:test";
import assert from "node:assert/strict";
import vm from "node:vm";
import { readFile } from "node:fs/promises";
const source = await readFile(
    new URL(
        "../../resources/js/enterprise/reviewer/executive.js",
        import.meta.url,
    ),
    "utf8",
);
class Element {
    constructor(tag, document) {
        this.tagName = tag;
        this.ownerDocument = document;
        this.children = [];
        this.attributes = {};
        this.dataset = {};
        this.listeners = new Map();
        this.hidden = false;
        this.disabled = false;
        this._text = "";
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
    append(...children) {
        this.children.push(...children);
    }
    replaceChildren(...children) {
        this._text = "";
        this.children = [...children];
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
    const document = {
        createElement(tag) {
            return new Element(tag, this);
        },
        createElementNS(namespace, tag) {
            return new Element(tag, this);
        },
    };
    const forbidden = [];
    const context = vm.createContext({
        document,
        URL,
        Intl,
        console,
        fetch() {
            forbidden.push("fetch");
            throw new Error("No network");
        },
        sessionStorage: {
            getItem() {
                forbidden.push("storage");
                throw new Error("No storage");
            },
        },
        EverbranchSampleTransport: {
            create() {
                forbidden.push("transport");
                throw new Error("No transport");
            },
        },
    });
    vm.runInContext(source, context);
    return {
        api: context.EverbranchExecutiveDemo,
        root: document.createElement("section"),
        forbidden,
    };
}
const plain = (value) => JSON.parse(JSON.stringify(value));
const descendants = (root) =>
    root.children.flatMap((child) => [child, ...descendants(child)]);
const base = () => ({
    runtime: "sample",
    persona: "executive",
    asOf: "2026-10-23T09:00:00Z",
    scope: "Supplied demonstration department scope",
    allowedSourceOrigins: ["https://www.lmc.edu"],
    sources: [
        {
            label: "Official public source",
            url: "https://www.lmc.edu/example.pdf",
            detail: "Public source only",
        },
    ],
    network: [
        {
            id: "department-a",
            label: "Configured department A",
            lifecycle: "Live",
            summary: "Fictional current handoffs",
            available: true,
            target: {
                section: "projects",
                area: "department-a",
                selected: "project-a",
            },
        },
        {
            id: "department-b",
            label: "Configured department B",
            lifecycle: "Onboarding",
            available: true,
            target: { section: "requests", area: "department-b" },
        },
        {
            id: "department-c",
            label: "Configured department C",
            lifecycle: "Planned",
            available: false,
        },
    ],
    metrics: [
        {
            id: "active",
            label: "Work in view",
            value: 2,
            available: true,
            target: { section: "projects", area: "department-a" },
        },
        {
            id: "capacity",
            label: "Capacity",
            value: null,
            detail: "No capacity data supplied",
        },
    ],
    proposal: {
        stages: [{ amount: 1000 }, { amount: 2000 }, { amount: 2500 }],
    },
});

test("executive overview requires explicit sample persona and never resolves an account, transport or stored grant", () => {
    const f = fixture();
    for (const config of [
        {},
        { ...base(), runtime: "private" },
        { ...base(), persona: "manager" },
    ]) {
        assert.equal(f.api.model(config).supported, false);
        f.api.mount(f.root, config);
        assert.equal(f.root.hidden, true);
        assert.equal(f.root.children.length, 0);
    }
    f.api.mount(f.root, base());
    assert.equal(f.root.hidden, false);
    assert.match(f.root.textContent, /Blaine J\. Hansen · COO/);
    assert.match(f.root.textContent, /Demo admin persona/);
    assert.match(
        f.root.textContent,
        /No real account, administrator grant or institutional authority/,
    );
    assert.match(f.root.textContent, /browser simulation/i);
    assert.deepEqual(f.forbidden, []);
});

test("configured workspaces and missing metrics stay honest and software proposal is separate from job money", () => {
    const f = fixture(),
        config = base();
    f.api.mount(f.root, config);
    const data = plain(f.api.model(config));
    assert.deepEqual(
        data.network.map((row) => row.label),
        config.network.map((row) => row.label),
    );
    assert.deepEqual(
        data.network.map((row) => row.lifecycle),
        ["Live", "Onboarding", "Planned"],
    );
    assert.equal(data.metrics[1].value, null);
    assert.deepEqual(
        data.plans.map((row) => row.amount),
        [1000, 2000, 2500],
    );
    assert.match(f.root.textContent, /Unavailable/);
    assert.match(f.root.textContent, /not hosted customer deployments/);
    assert.match(
        f.root.textContent,
        /separate from project costs, invoice packets and work budgets/,
    );
    assert.match(f.root.textContent, /Billing is inactive/);
    assert.match(f.root.textContent, /cadence and timing remain uncommitted/);
    assert.match(f.root.textContent, /currency unconfirmed/);
    assert.doesNotMatch(
        f.root.textContent,
        /per month|per year|\/month|\/year/,
    );
    f.api.mount(f.root, { runtime: "sample", persona: "executive" });
    assert.match(f.root.textContent, /No configured workspaces were supplied/);
    assert.match(f.root.textContent, /No metrics were supplied/);
    assert.match(f.root.textContent, /freshness unverified/);
});

test("record buttons invoke only supplied navigation with allowlisted context and stale callbacks cannot repaint another persona", async () => {
    const f = fixture(),
        calls = [];
    let release;
    const config = {
        ...base(),
        onNavigate: (target, item) => {
            calls.push({ target: plain(target), item: plain(item) });
            return new Promise((resolve) => {
                release = resolve;
            });
        },
    };
    config.network[0].target.tenant = "other-tenant";
    const view = f.api.mount(f.root, config),
        buttons = descendants(f.root).filter(
            (node) => node.tagName === "button",
        );
    const card = buttons.find(
        (node) =>
            node.attributes["aria-label"] === "Open Configured department A",
    );
    const pending = card.click();
    assert.deepEqual(calls, [
        {
            target: {
                section: "projects",
                area: "department-a",
                selected: "project-a",
            },
            item: { id: "department-a", label: "Configured department A" },
        },
    ]);
    assert.equal(
        buttons.find(
            (node) =>
                node.attributes["aria-label"] ===
                "Open Configured department C",
        ).disabled,
        true,
    );
    view.update({ ...config, persona: "worker" });
    release({ available: true });
    await pending;
    assert.equal(f.root.hidden, true);
    assert.equal(f.root.children.length, 0);
    assert.deepEqual(f.forbidden, []);
    view.update({
        ...base(),
        network: [
            {
                id: "invalid",
                label: "Unsafe target",
                available: true,
                target: { section: "billing", area: "foreign/../area" },
            },
        ],
        onNavigate: () => {
            throw new Error("Must not navigate");
        },
    });
    assert.equal(
        descendants(f.root).find(
            (node) => node.attributes["aria-label"] === "Open Unsafe target",
        ).disabled,
        true,
    );
    view.destroy();
    assert.equal(f.root.hidden, true);
    assert.equal(f.root.children.length, 0);
});

test("source badges require exact configured HTTPS origins and supplied text is inert DOM content", () => {
    const f = fixture(),
        config = base();
    config.network[0].label = '<img src=x onerror="alert(1)">';
    config.sources.push(
        { label: "Unsafe script", url: "javascript:alert(1)" },
        {
            label: "Wrong origin",
            url: "https://www.lmc.edu.evil.example/source",
        },
        { label: "Unapproved", url: "https://other.example/source" },
        { label: "Credentials", url: "https://user:pass@www.lmc.edu/source" },
        { label: "HTTP source", url: "http://www.lmc.edu/source" },
    );
    f.api.mount(f.root, config);
    const anchors = descendants(f.root).filter((node) => node.tagName === "a");
    assert.equal(anchors.length, 1);
    assert.equal(anchors[0].href, "https://www.lmc.edu/example.pdf");
    assert.equal(anchors[0].rel, "noopener noreferrer");
    assert.equal(
        descendants(f.root).filter((node) => node.tagName === "img").length,
        0,
    );
    assert.match(f.root.textContent, /<img src=x/);
    assert.deepEqual(f.forbidden, []);
});
