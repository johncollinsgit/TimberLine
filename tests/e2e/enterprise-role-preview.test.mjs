import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import vm from "node:vm";

const blade = await readFile(
    new URL(
        "../../resources/views/enterprise/role-preview.blade.php",
        import.meta.url,
    ),
    "utf8",
);
const source = blade.match(/<script>([\s\S]*?)<\/script>/)[1];
const AREA = "10000000-0000-4000-8000-000000000001";
const OTHER = "10000000-0000-4000-8000-000000000002";
const PROJECT = "20000000-0000-4000-8000-000000000001";
class Events {
    listeners = new Map();
    addEventListener(type, listener) {
        const list = this.listeners.get(type) || [];
        list.push(listener);
        this.listeners.set(type, list);
    }
    removeEventListener(type, listener) {
        this.listeners.set(
            type,
            (this.listeners.get(type) || []).filter((row) => row !== listener),
        );
    }
    dispatchEvent(event) {
        for (const listener of this.listeners.get(event.type) || [])
            listener(event);
    }
}
class Element extends Events {
    constructor(tag = "div") {
        super();
        this.tagName = tag;
        this.dataset = {};
        this.children = [];
        this.attributes = {};
        this.textContent = "";
        this.value = "";
        this.hidden = false;
    }
    replaceChildren(...children) {
        this.children = children;
        this.textContent = "";
        if (this.tagName === "select") this.value = "";
    }
    append(...children) {
        this.children.push(...children);
    }
    setAttribute(key, value) {
        this.attributes[key] = value;
    }
    removeAttribute(key) {
        delete this.attributes[key];
    }
    click() {
        this.dispatchEvent({ type: "click", preventDefault() {} });
    }
}
const flush = async () => {
    for (let i = 0; i < 5; i++) await Promise.resolve();
};
function fixture(overrides = {}) {
    const nodes = new Map();
    const root = new Element();
    root.querySelector = (selector) => {
        const name = selector.match(/data-erp-([^\]]+)/)[1];
        if (!nodes.has(name))
            nodes.set(name, new Element(name === "member" ? "select" : "div"));
        return nodes.get(name);
    };
    const events = new Events(),
        pending = [];
    const document = { createElement: (tag) => new Element(tag) };
    const context = vm.createContext({
        document,
        URL,
        AbortController,
        CustomEvent: class {
            constructor(type, init) {
                this.type = type;
                this.detail = init.detail;
            }
        },
        location: {
            href: "https://workspace.example/enterprise-campus/?tenant=synthetic",
            origin: "https://workspace.example",
        },
        fetch() {
            throw new Error("Unit fixture must not reach HTTP");
        },
    });
    vm.runInContext(source, context);
    const boot = {
        runtime: "private",
        role: "owner",
        identity: { viewer: "1", workspace: "11" },
        endpoint:
            "https://workspace.example/enterprise-campus/enterprise/role-preview?tenant=synthetic",
        routes: {
            back: "https://workspace.example/enterprise-campus/?tenant=synthetic",
        },
        ...overrides,
    };
    const transport = {
        request(url, body, options) {
            assert.equal(
                body,
                undefined,
                "Preview never sends a mutation body",
            );
            return new Promise((resolve, reject) =>
                pending.push({ url, options, resolve, reject }),
            );
        },
    };
    const mounted = context.EverbranchRolePreview.mount(root, boot, {
        document,
        events,
        transport,
    });
    const state = (extra) =>
        events.dispatchEvent({
            type: "enterprise:workspace-state",
            detail: {
                area: AREA,
                section: "people",
                features: { operations: true },
                loaded: true,
                viewer_id: "1",
                ...extra,
            },
        });
    const get = (name) => nodes.get(name);
    return { root, events, boot, pending, mounted, state, get };
}
function result(area = AREA, member = null) {
    const roster = [
        {
            id: "1",
            name: "Current admin",
            tenant_role: "owner",
            area_role: "coordinator",
        },
        {
            id: "2",
            name: "Existing viewer",
            tenant_role: "viewer",
            area_role: "viewer",
        },
    ];
    return {
        read_only: true,
        impersonation: false,
        observer: roster[0],
        scope: { tenant_id: "11", area_id: area },
        roster,
        as_of: "2026-10-22T12:00:00Z",
        preview: member
            ? {
                  member: roster.find((row) => row.id === member),
                  areas: [
                      {
                          id: area,
                          name: "Current department",
                          visibility: "department_only",
                      },
                  ],
                  projects: [
                      {
                          id: PROJECT,
                          area_id: area,
                          name: "<img src=x onerror=alert(1)> literal title",
                          archived: false,
                      },
                  ],
                  requests: [],
                  counts: { areas: 1, projects: 1, requests: 0 },
                  coverage: { projects_partial: true },
              }
            : null,
    };
}

test("preview requires private owner/admin bootstrap and current People/operations/viewer context", () => {
    for (const boot of [
        { runtime: "sample" },
        { role: "viewer" },
        { identity: { viewer: "", workspace: "11" } },
    ])
        assert.equal(fixture(boot).mounted, null);
    const ui = fixture();
    for (const state of [
        { loaded: false },
        { section: "projects" },
        { viewer_id: "other" },
        { features: { operations: false } },
        { area: "foreign arbitrary text" },
    ]) {
        ui.state(state);
        assert.equal(ui.root.hidden, true);
        assert.equal(ui.pending.length, 0);
    }
    ui.state();
    assert.equal(ui.root.hidden, false);
    assert.equal(ui.pending.length, 1);
});

test("existing viewer roster and summaries remain read-only safely rendered and use own authorized drill links", async () => {
    const ui = fixture();
    ui.state();
    ui.pending.shift().resolve(result());
    await flush();
    assert.equal(ui.get("member").disabled, false);
    assert.match(
        ui.get("member").children[2].textContent,
        /Existing viewer.*viewer.*viewer/,
    );
    ui.get("member").value = "2";
    ui.get("member").dispatchEvent({ type: "change" });
    const request = ui.pending.shift(),
        url = new URL(request.url);
    assert.equal(url.pathname, "/enterprise-campus/enterprise/role-preview");
    assert.equal(url.searchParams.get("tenant"), "synthetic");
    assert.equal(url.searchParams.get("area_id"), AREA);
    assert.equal(url.searchParams.get("member_id"), "2");
    request.resolve(result(AREA, "2"));
    await flush();
    assert.equal(ui.get("preview").hidden, false);
    assert.match(ui.get("coverage").textContent, /bounded partial/);
    const anchor = ui.get("projects").children[0].children[0];
    assert.equal(anchor.tagName, "a");
    assert.equal(
        anchor.textContent,
        "<img src=x onerror=alert(1)> literal title",
    );
    assert.equal(anchor.children.length, 0);
    const target = new URL(anchor.href);
    assert.equal(target.origin, "https://workspace.example");
    assert.equal(target.pathname, "/enterprise-campus/");
    assert.equal(target.searchParams.get("tenant"), "synthetic");
    assert.equal(target.searchParams.get("selected"), PROJECT);
    assert.equal(target.searchParams.has("member_id"), false);
    let navigation;
    ui.events.addEventListener("enterprise:navigate", (event) => {
        navigation = event.detail;
    });
    anchor.click();
    assert.equal(navigation.section, "projects");
    assert.equal(navigation.selected, PROJECT);
    assert.equal(ui.root.hidden, true);
    assert.equal(ui.get("roster").children.length, 0);
    assert.equal(ui.boot.identity.viewer, "1");
});

test("scope change and load reset clear data synchronously and ignore late success or failure from old requests", async () => {
    const ui = fixture();
    ui.state();
    const old = ui.pending.shift();
    ui.state({ area: OTHER });
    const fresh = ui.pending.shift();
    assert.equal(old.options.signal.aborted, true);
    old.resolve(result());
    await flush();
    assert.equal(ui.get("roster").children.length, 0);
    fresh.resolve(result(OTHER));
    await flush();
    assert.equal(ui.get("roster").children.length, 2);
    ui.get("member").value = "2";
    ui.get("member").dispatchEvent({ type: "change" });
    const selected = ui.pending.shift();
    ui.state({ loaded: false, area: null });
    assert.equal(ui.root.hidden, true);
    assert.equal(ui.get("roster").children.length, 0);
    assert.equal(selected.options.signal.aborted, true);
    selected.reject(
        Object.assign(new Error("Old role denial"), { status: 403 }),
    );
    await flush();
    assert.equal(ui.get("status").textContent, "");
});

test("fresh role denial mismatched observer tenant or member clears old roster and all summaries", async () => {
    for (const mode of ["denied", "observer", "tenant", "member"]) {
        const ui = fixture();
        ui.state();
        ui.pending.shift().resolve(result());
        await flush();
        ui.get("member").value = "2";
        ui.get("member").dispatchEvent({ type: "change" });
        const call = ui.pending.shift();
        const data = result(AREA, "2");
        if (mode === "denied")
            call.reject(Object.assign(new Error("Denied"), { status: 403 }));
        else {
            if (mode === "observer")
                data.observer = { ...data.observer, id: "99" };
            if (mode === "tenant") data.scope.tenant_id = "foreign";
            if (mode === "member") data.preview.member.id = "99";
            call.resolve(data);
        }
        await flush();
        assert.equal(ui.get("roster").children.length, 0);
        assert.equal(ui.get("projects").children.length, 0);
        assert.equal(ui.get("preview").hidden, true);
        assert.equal(ui.get("member").disabled, true);
        assert.equal(ui.get("refresh").disabled, false);
    }
});

test("leaving People or losing features destroys stale preview and arbitrary dropdown values cannot fetch another user", async () => {
    const ui = fixture();
    ui.state();
    ui.pending.shift().resolve(result());
    await flush();
    ui.get("member").value = "999999";
    ui.get("member").dispatchEvent({ type: "change" });
    assert.equal(ui.pending.length, 0);
    ui.state({ section: "requests" });
    assert.equal(ui.root.hidden, true);
    assert.equal(ui.get("roster").children.length, 0);
    ui.state();
    const late = ui.pending.shift();
    ui.mounted.destroy();
    late.resolve(result());
    await flush();
    assert.equal(ui.root.hidden, true);
    assert.equal(ui.get("roster").children.length, 0);
});
