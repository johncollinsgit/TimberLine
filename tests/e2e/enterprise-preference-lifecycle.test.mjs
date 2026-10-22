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
