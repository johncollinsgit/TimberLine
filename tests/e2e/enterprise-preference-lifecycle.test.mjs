import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import vm from "node:vm";

const source = await readFile(
    new URL(
        "../../resources/views/enterprise/operations-script.blade.php",
        import.meta.url,
    ),
    "utf8",
);
function extract(start, end) {
    const first = source.indexOf(start);
    const last = source.indexOf(end, first);
    assert.ok(first >= 0 && last > first, "Actual preference lifecycle source must be available");
    return source.slice(first, last);
}
const loadSource = extract(
    "  async function loadPreferences(){",
    "  function renderFavoriteProject(){",
);
const resetSource = extract(
    "  function resetOperations(){",
    "  function moveTo(",
);
const copy = (value) => JSON.parse(JSON.stringify(value));
function preferenceResult(name, view = "map") {
    return {
        licensing: { description: name + " license context" },
        can_manage: true,
        intake_published: true,
        effective: {
            density: "compact",
            view,
            filter: "all",
            favorites: ["authorized-project", "unavailable-project"],
        },
    };
}

function fixture() {
    const writes = [], effects = [], requests = [], nodes = new Map();
    function node(id) {
        if (!nodes.has(id)) {
            const values = {
                hidden: false,
                disabled: false,
                checked: false,
                textContent: "",
                innerHTML: "",
                querySelector(selector) {
                    assert.equal(selector, "[value=workspace]");
                    return node(id + ":workspace-option");
                },
                querySelectorAll(selector) {
                    assert.equal(selector, "[data-favorite-project]");
                    return [];
                },
                replaceChildren() {
                    writes.push({ id, property: "children", value: [] });
                },
            };
            nodes.set(id, new Proxy(values, {
                set(target, property, value) {
                    writes.push({ id, property, value });
                    target[property] = value;
                    return true;
                },
            }));
        }
        return nodes.get(id);
    }
    let context;
    const dataset = new Proxy({}, {
        set(target, property, value) {
            writes.push({ id: "document", property, value });
            target[property] = value;
            return true;
        },
    });
    context = vm.createContext({
        area: "area-a",
        section: "ask",
        data: {
            features: { operations: true },
            projects: [{ id: "authorized-project", name: "Authorized current project" }],
        },
        workspaceLoadGeneration: 1,
        preferencesGeneration: 0,
        preferencesState: null,
        preferencesArea: null,
        view: "list",
        map: false,
        campusGeneration: 0,
        requesterGeneration: 0,
        operationsGeneration: 0,
        opsContextGeneration: 0,
        opsViewContext: null,
        opsRetryTarget: null,
        $: node,
        esc: (value) => String(value).replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll('"', "&quot;"),
        opUrl(path, query) {
            assert.equal(path, "/preferences");
            return path + "?" + new URLSearchParams(query);
        },
        api(url) {
            const request = { url };
            const promise = new Promise((resolve, reject) => {
                request.resolve = resolve;
                request.reject = reject;
            });
            requests.push(request);
            return promise;
        },
        preferenceFields() {
            effects.push({ type: "fields", preferences: copy(context.preferencesState) });
        },
        render(options) {
            effects.push({ type: "render", options: copy(options) });
        },
        renderFavoriteProject() {
            effects.push({ type: "favorite" });
        },
        document: {
            documentElement: { dataset },
            dispatchEvent(event) {
                effects.push({ type: "event", name: event.type });
            },
        },
        CustomEvent: class {
            constructor(type) { this.type = type; }
        },
    });
    vm.runInContext(resetSource + "\n" + loadSource, context);
    return {
        context, requests, writes, effects, node,
        load: () => vm.runInContext("loadPreferences()", context),
        reset: () => vm.runInContext("resetOperations()", context),
        snapshot: () => copy({
            preferences: context.preferencesState,
            preferencesArea: context.preferencesArea,
            view: context.view,
            map: context.map,
            section: context.section,
            density: dataset.workspaceDensity || null,
            writes,
            effects,
        }),
    };
}

test("a current first preference load applies authorized fields and renders with preserved detail", async () => {
    const f = fixture(), pending = f.load();
    assert.equal(f.requests.length, 1);
    assert.equal(f.requests[0].url, "/preferences?area_id=area-a");
    const result = preferenceResult("Current");
    f.requests[0].resolve(result);
    await pending;
    assert.deepEqual(copy(f.context.preferencesState), result);
    assert.equal(f.node("workspace-license").textContent, "Current license context");
    assert.equal(f.node("preferences-scope:workspace-option").disabled, false);
    assert.equal(f.node("preferences-intake").checked, true);
    assert.equal(f.context.document.documentElement.dataset.workspaceDensity, "compact");
    assert.equal(f.context.view, "map");
    assert.equal(f.context.map, true);
    assert.match(f.node("workspace-favorites").innerHTML, /Authorized current project/);
    assert.doesNotMatch(f.node("workspace-favorites").innerHTML, /unavailable-project/);
    assert.deepEqual(f.effects.map(effect => effect.type), ["fields", "render"]);
    assert.deepEqual(f.effects[1].options, { preserveDetail: true });
});

test("a same-area workspace reload invalidates an old success even when fresh operations data is active again", async () => {
    const f = fixture(), pending = f.load();
    // The area stays unchanged; a newer workspace load has reauthorized its data.
    f.context.workspaceLoadGeneration++;
    f.context.data = { features: { operations: true }, projects: [] };
    const before = f.snapshot();
    f.requests[0].resolve(preferenceResult("Former load"));
    await pending;
    assert.deepEqual(f.snapshot(), before);
    assert.equal(f.context.section, "ask");
});

test("the actual operations reset invalidates a same-area pending success without needing an area change", async () => {
    const f = fixture(), pending = f.load();
    f.reset();
    const afterReset = f.snapshot();
    f.requests[0].resolve(preferenceResult("Before reset"));
    await pending;
    assert.deepEqual(f.snapshot(), afterReset, "A late response must not touch cleared DOM or render");
    assert.equal(f.context.preferencesState, null);
    assert.equal(f.node("workspace-preferences").hidden, true);
});

test("cleared feature data blocks a pending preference response before a new area has been chosen", async () => {
    const f = fixture(), pending = f.load();
    f.context.data = { features: {}, projects: [] };
    const before = f.snapshot();
    f.requests[0].resolve(preferenceResult("Unavailable feature"));
    await pending;
    assert.deepEqual(f.snapshot(), before);
});

for (const order of ["old-first", "new-first"]) {
    test(`the newer preference request wins when responses arrive ${order}`, async () => {
        const f = fixture(), old = f.load(), latest = f.load();
        assert.equal(f.requests.length, 2);
        const result = preferenceResult("Newest", "calendar");
        if (order === "old-first") {
            const before = f.snapshot();
            f.requests[0].resolve(preferenceResult("Old"));
            await old;
            assert.deepEqual(f.snapshot(), before);
            f.requests[1].resolve(result);
            await latest;
        } else {
            f.requests[1].resolve(result);
            await latest;
            const before = f.snapshot();
            f.requests[0].resolve(preferenceResult("Old"));
            await old;
            assert.deepEqual(f.snapshot(), before);
        }
        assert.deepEqual(copy(f.context.preferencesState), result);
        assert.equal(f.node("workspace-license").textContent, "Newest license context");
        assert.equal(f.context.view, "calendar");
        assert.equal(f.effects.filter(effect => effect.type === "render").length, 1);
    });
}

test("a stale rejection cannot clear newer accepted preferences or hide their UI", async () => {
    const f = fixture(), old = f.load(), latest = f.load();
    f.requests[1].resolve(preferenceResult("Accepted newest"));
    await latest;
    const before = f.snapshot();
    f.requests[0].reject(Object.assign(new Error("Old authorization denial"), { status: 403 }));
    await old;
    assert.deepEqual(f.snapshot(), before);
    assert.equal(f.node("workspace-preferences").hidden, false);
});

test("a stale rejection after the actual reset does not mutate cleared context", async () => {
    const f = fixture(), pending = f.load();
    f.reset();
    const afterReset = f.snapshot();
    f.requests[0].reject(new Error("Interrupted former workspace request"));
    await pending;
    assert.deepEqual(f.snapshot(), afterReset);
});

test("a current rejection still clears preferences and hides their controls", async () => {
    const f = fixture(), first = f.load();
    f.requests[0].resolve(preferenceResult("Previously accepted"));
    await first;
    const refresh = f.load();
    f.requests[1].reject(Object.assign(new Error("Current access refused"), { status: 403 }));
    await refresh;
    assert.equal(f.context.preferencesState, null);
    assert.equal(f.node("workspace-preferences").hidden, true);
    assert.equal(f.effects.filter(effect => effect.type === "render").length, 1);
});

test("a current refresh updates fields and favorites without replacing the current view or rendering the shell again", async () => {
    const f = fixture(), first = f.load();
    f.requests[0].resolve(preferenceResult("Initial", "map"));
    await first;
    const refresh = f.load();
    const latest = preferenceResult("Refreshed", "calendar");
    f.requests[1].resolve(latest);
    await refresh;
    assert.deepEqual(copy(f.context.preferencesState), latest);
    assert.equal(f.context.view, "map");
    assert.equal(f.effects.filter(effect => effect.type === "render").length, 1);
    assert.equal(f.effects.filter(effect => effect.type === "favorite").length, 1);
});

test("disabled or absent Operations and a missing area never issue a preference request", async () => {
    for (const data of [{}, { features: {} }, { features: { operations: false } }]) {
        const f = fixture();
        f.context.data = data;
        const before = f.snapshot();
        await f.load();
        assert.equal(f.requests.length, 0);
        assert.deepEqual(f.snapshot(), before);
    }
    const f = fixture();
    f.context.area = "";
    await f.load();
    assert.equal(f.requests.length, 0);
    assert.equal(f.writes.length, 0);
});

// Execute the actual catalog and lifecycle callbacks: departing requests may reject
// before pagehide, while a replacement document is still being loaded.
const shell = await readFile(new URL("../../resources/views/enterprise/app.blade.php", import.meta.url), "utf8");
const catalogSource = shell.slice(shell.indexOf("  async function loadProjectCatalog(){"), shell.indexOf("  function renderWorkFilters(){"));
const lifecycleSource = shell.slice(shell.indexOf("  globalThis.addEventListener('popstate'"), shell.indexOf("  $('search').oninput"));

function catalogFixture() {
    const events = new Map(), requests = [], renders = [], pending = { request_id: "retained-exact-write", body: "Unsent draft" };
    const result = id => ({ selected_area_id: id, projects: [{ id: "project-" + id }], requests: [], members: [] });
    let held = true;
    const context = vm.createContext({
        workspaceLoadGeneration: 1, projectCatalogGeneration: 0, projectCatalog: null,
        data: { ...result("a0"), areas: Array.from({ length: 6 }, (_, i) => ({ id: "a" + i, name: "Department " + i })) },
        section: "projects", urls: { data: "/data?tenant=fixture" }, pending,
        addEventListener: (name, handler) => events.set(name, handler),
        api(url) {
            const id = new URL(url, "https://fixture.invalid").searchParams.get("area_id"), request = { id };
            requests.push(request);
            if (!held) return Promise.resolve(result(id));
            return new Promise((resolve, reject) => { request.resolve = () => resolve(result(id)); request.reject = reject; });
        },
        render: options => renders.push(copy(options)),
        clearWorkspaceContext: () => { context.projectCatalog = null; context.projectCatalogGeneration++; },
        restoreNavigation: () => { context.workspaceLoadGeneration++; context.restored = context.loadProjectCatalog(); },
    });
    vm.runInContext(catalogSource + lifecycleSource, context);
    return { context, events, requests, renders, pending, load: () => context.loadProjectCatalog(), release: () => { held = false; } };
}

test("beforeunload stops the old catalog batch before any replacement-document read or render", async () => {
    const f = catalogFixture(), original = copy(f.pending), pending = f.load();
    assert.equal(f.requests.length, 2, "The first batch reuses the authorized current department");
    f.events.get("beforeunload")?.({ defaultPrevented: false });
    f.release();
    for (const request of f.requests) request.reject(new Error("Departing request canceled"));
    await pending;
    assert.equal(f.requests.length, 2, "A canceled old batch must not launch the next three reads during provisional navigation");
    assert.equal(f.context.projectCatalog, null);
    assert.equal(f.renders.length, 0);
    assert.deepEqual(f.pending, original, "Read invalidation cannot clear, rewrite or retry a pending write/draft");
});

test("Stay on a canceled navigation preserves data and permits a deliberate fresh catalog load", async () => {
    const f = catalogFixture(), original = copy(f.pending), data = f.context.data, old = f.load();
    f.events.get("beforeunload")?.({ defaultPrevented: true, returnValue: "" });
    f.release();
    for (const request of f.requests) request.reject(new Error("Interrupted obsolete read"));
    await old;
    assert.equal(f.context.data, data, "No pagehide or destructive context clear occurred on Stay");
    await f.load();
    assert.equal(f.context.projectCatalog.items.length, 6);
    assert.equal(f.context.projectCatalog.failed.length, 0);
    assert.equal(f.renders.length, 1);
    assert.deepEqual(f.pending, original);
});

test("a persisted pageshow uses the existing fresh-load path rather than reviving the old catalog", async () => {
    const f = catalogFixture(), old = f.load();
    f.events.get("beforeunload")?.({});
    f.events.get("pagehide")({});
    f.release();
    for (const request of f.requests) request.reject(new Error("Former document read"));
    await old;
    f.events.get("pageshow")({ persisted: false });
    assert.equal(f.context.restored, undefined);
    f.events.get("pageshow")({ persisted: true });
    await f.context.restored;
    assert.equal(f.context.projectCatalog.items.length, 6);
    assert.equal(f.context.projectCatalog.failed.length, 0);
    assert.equal(f.renders.length, 1);
});
