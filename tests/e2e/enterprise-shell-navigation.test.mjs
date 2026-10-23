import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { webcrypto } from "node:crypto";
import vm from "node:vm";

const shell = await readFile(
    new URL("../../resources/views/enterprise/app.blade.php", import.meta.url),
    "utf8",
);
const next = await readFile(
    new URL(
        "../../resources/views/enterprise/next-script.blade.php",
        import.meta.url,
    ),
    "utf8",
);
const adapterSource = await readFile(
    new URL(
        "../../resources/js/enterprise/reviewer/sample-adapter.js",
        import.meta.url,
    ),
    "utf8",
);
function between(source, start, end) {
    const first = source.indexOf(start),
        last = source.indexOf(end, first);
    assert.ok(
        first >= 0 && last > first,
        `Actual source region missing: ${start}`,
    );
    return source.slice(first, last);
}
const navigationSource = between(
    shell,
    "  // Workspace history stores navigation only.",
    "  $('search').oninput",
);
const handlers = between(
    shell,
    "  $('search').oninput",
    "  @include('enterprise.next-script')",
);
const dispatch = between(
    shell,
    "  document.addEventListener('enterprise:navigate'",
    "  load();\n})();",
);
const composerSource = between(
    next,
    "  function readDraft",
    "  function resetNext",
);
const rowsSource = between(shell, "  function rows()", "  function render(");
const locatedSource = shell.match(/    const located = [^\n]+;/)?.[0];
assert.ok(locatedSource);
const plain = (value) => JSON.parse(JSON.stringify(value));

test("the patched shell compiles with Blade composition left to its existing partials", () => {
    const core = shell.match(
        /<script>\s*(\(\(\) => \{[\s\S]*?)<\/script>/,
    )?.[1];
    assert.ok(core);
    const javascript = core
        .replace(/@json\(\$enterprisePresentation\)/g, "{}")
        .replace(/^\s*@include(?:If)?\([^\n]+\)\s*$/gm, "");
    assert.doesNotThrow(() => new vm.Script(javascript));
});

function fixture(mode = "private") {
    const input = { value: "" },
        note = { value: "Synthetic unsent request note" },
        events = new Map(),
        storage = new Map(),
        buttons = {
            view: ["list", "calendar", "map"].map((view) => ({
                dataset: { view },
            })),
            section: ["requests", "projects", "roll_call"].map((section) => ({
                dataset: { section },
                classList: { toggle() {} },
            })),
        },
        origin = "https://workspace.example",
        url = new URL(
            origin +
                (mode === "sample"
                    ? "/platform/demos/lees-mcrae#area_id=a&section=requests&selected=r1&scope=team&team_user_id=u1"
                    : "/enterprise-campus/?tenant=synthetic&area_id=a&section=requests&selected=r1&scope=team&team_user_id=u1"),
        ),
        history = [{ url: url.href }],
        calls = [];
    let index = 0;
    const context = vm.createContext({
        URL,
        URLSearchParams,
        crypto: webcrypto,
        Blob,
        TextEncoder,
        console,
        location: url,
        navigation: new URLSearchParams(
            mode === "sample" ? url.hash.slice(1) : url.search,
        ),
        section: "requests",
        area: "a",
        selected: "r1",
        view: "list",
        map: false,
        navigationPushPending: false,
        navigationSearchBatch: false,
        navigationViewOverride: null,
        allowedSections: ["requests", "projects", "roll_call"],
        tenantSlug: "synthetic",
        viewer: "u1",
        selectionMemory: new Map(),
        activeDiscussionKey: "a:requests:r1",
        draftPrefix: "fixture:",
        data: { viewer_id: "u1", projects: [], requests: [] },
        preferencesState: null,
        preferencesArea: null,
        $: (id) =>
            id === "search" ? input : id === "discussion-body" ? note : {},
        sessionStorage: {
            get length() {
                return storage.size;
            },
            key: (i) => [...storage.keys()][i] ?? null,
            getItem: (key) => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, value),
            removeItem: (key) => storage.delete(key),
        },
        addEventListener: (name, fn) => events.set(name, fn),
        document: {
            querySelectorAll: (selector) =>
                selector === "[data-view]" ? buttons.view : buttons.section,
            addEventListener: (name, fn) => events.set(name, fn),
        },
        message() {},
        resetNext() {
            calls.push("reset");
        },
        fetch() {
            throw new Error("Navigation must never fetch or call a provider");
        },
    });
    context.history = {
        state: null,
        pushState(state, _title, target) {
            history.splice(++index);
            history.push({ url: String(target), state });
            url.href = String(target);
            calls.push("push");
        },
        replaceState(state, _title, target) {
            history[index] = { url: String(target), state };
            url.href = String(target);
            calls.push("replace");
        },
    };
    if (mode === "sample") {
        vm.runInContext(adapterSource, context);
        context.baseTransport = context.EverbranchSampleTransport.create({
            runtime: "sample",
            identity: { workspace: "synthetic" },
            routes: {
                back: "/platform/demos/lees-mcrae",
                workflowWorkspace: "/platform/demos/lees-mcrae/workflows",
            },
        });
    } else context.baseTransport = { href: (value) => String(value), mode };
    context.transport = {
        ...context.baseTransport,
        mode,
        replace: (value) => context.rememberNavigation(value),
    };
    vm.runInContext(composerSource, context);
    function render() {
        context.stashComposer();
        context.rememberUrl();
        context.activeDiscussionKey = `${context.area}:${context.section}:${context.selected}`;
        note.value = context.readDraft(context.activeDiscussionKey).body || "";
        calls.push("render");
    }
    context.render = render;
    context.load = () => {
        calls.push("load");
        render();
    };
    vm.runInContext(navigationSource + handlers + dispatch, context);
    const visit = (direction) => {
        index += direction;
        url.href = history[index].url;
        events.get("popstate")();
    };
    return {
        context,
        input,
        note,
        buttons,
        calls,
        storage,
        history,
        render,
        events,
        back: () => visit(-1),
        forward: () => visit(1),
    };
}

for (const mode of ["private", "sample"]) {
    test(`${mode}: user navigation pushes, repeated render replaces, and Back/Forward restores unsaved view and drafts`, () => {
        const f = fixture(mode),
            c = f.context;
        f.render();
        assert.equal(
            f.history.length,
            1,
            "Initial canonicalization is not another navigation step",
        );
        f.note.value = "Synthetic unsent request note";
        f.buttons.view[1].onclick();
        assert.equal(f.history.length, 2);
        f.buttons.section[1].onclick();
        assert.equal(f.history.length, 3);
        assert.equal(c.section, "projects");
        f.back();
        assert.equal(c.section, "requests");
        assert.equal(c.selected, "r1");
        assert.equal(c.view, "calendar");
        assert.equal(f.note.value, "Synthetic unsent request note");
        assert.equal(
            f.history.length,
            3,
            "popstate and its fresh load cannot push a loop",
        );
        assert.deepEqual(plain(c.navigationViewOverride), {
            view: "calendar",
            map: false,
        });
        const guard = shell.match(
            /    if \(preserveDetail && navigationViewOverride\)[^\n]+;/,
        )?.[0];
        assert.ok(guard);
        vm.runInContext(
            `view='list'; map=true; var preserveDetail=true; ${guard}`,
            c,
        );
        assert.equal(
            c.view,
            "calendar",
            "Late saved view must not overwrite an explicit history view",
        );
        assert.equal(c.map, false);
        f.forward();
        assert.equal(c.section, "projects");
        assert.equal(c.view, "calendar");
        assert.ok(
            f.calls.includes("reset") && f.calls.includes("load"),
            "Restoration rechecks a projection, not stored rows",
        );
        const current = new URL(c.location.href),
            query = new URLSearchParams(
                mode === "sample" ? current.hash.slice(1) : current.search,
            );
        assert.equal(query.get("scope"), "team");
        assert.equal(
            query.get("team_user_id"),
            "u1",
            "Saved Roll Call scope is retained",
        );
        assert.equal(
            mode === "sample" ? current.search : query.get("tenant"),
            mode === "sample" ? "" : "synthetic",
        );
    });

    test(`${mode}: search typing is one history step, fixed fields are bounded, and unsafe destinations are refused`, () => {
        const f = fixture(mode),
            c = f.context;
        f.render();
        f.input.value = "B";
        f.input.oninput();
        f.input.value = "Bryan";
        f.input.oninput();
        assert.equal(f.history.length, 2);
        f.input.onblur();
        f.buttons.view[2].onclick();
        assert.equal(f.history.length, 3);
        f.back();
        assert.equal(f.input.value, "Bryan");
        assert.equal(c.view, "list");
        assert.equal(c.map, false);
        f.back();
        assert.equal(f.input.value, "");
        const before = c.location.href;
        c.beginNavigation();
        c.rememberNavigation("https://other.example/enterprise-campus/");
        assert.equal(c.location.href, before);
        c.rememberNavigation(
            "https://name:password@workspace.example/enterprise-campus/",
        );
        assert.equal(c.location.href, before);
        f.input.value = "?tenant=foreign#" + "x".repeat(300);
        f.input.oninput();
        const target = new URL(c.location.href),
            query = new URLSearchParams(
                mode === "sample" ? target.hash.slice(1) : target.search,
            );
        assert.equal(query.get("q").length, 200);
        assert.equal(query.get("q").startsWith("?tenant=foreign#"), true);
        assert.notEqual(query.get("tenant"), "foreign");
    });
}

test("map uses exactly the list/calendar search and saved filter projection, including current section", () => {
    const f = fixture(),
        c = f.context;
    c.data.requests = [
        {
            id: "active",
            title: "Audio check",
            status: "assigned",
            task: { assignee_id: "u1" },
            data: { latitude: 36, longitude: -81 },
        },
        {
            id: "complete",
            title: "Audio complete",
            status: "completed",
            data: { latitude: 36.1, longitude: -81.1 },
        },
        {
            id: "other",
            title: "Different job",
            status: "submitted",
            data: { latitude: 36.2, longitude: -81.2 },
        },
        {
            id: "unlocated",
            title: "Audio unknown",
            status: "assigned",
            data: { latitude: null, longitude: null },
        },
    ];
    c.data.projects = [
        { id: "project", name: "Audio project", archived: false },
    ];
    c.preferencesArea = "a";
    vm.runInContext(rowsSource, c);
    const ids = () =>
        plain(
            vm.runInContext(
                `(() => { const work=rows(); ${locatedSource} return {rows: work.map(r=>r.id), markers: located.map(r=>r.id)}; })()`,
                c,
            ),
        );
    f.input.value = "audio";
    c.preferencesState = { effective: { filter: "active" } };
    assert.deepEqual(ids(), {
        rows: ["active", "unlocated"],
        markers: ["active"],
    });
    c.preferencesState.effective.filter = "completed";
    assert.deepEqual(ids(), { rows: ["complete"], markers: ["complete"] });
    c.preferencesState.effective.filter = "mine";
    assert.deepEqual(ids(), { rows: ["active"], markers: ["active"] });
    c.preferencesState.effective.filter = "all";
    c.section = "projects";
    assert.deepEqual(
        ids(),
        { rows: ["project"], markers: [] },
        "A project view cannot show unrelated request pins",
    );
});

test("tour navigation preserves selection in the same scope and uses stored selection when returning", () => {
    const f = fixture(),
        c = f.context;
    f.render();
    f.events.get("enterprise:navigate")({ detail: { section: "requests" } });
    assert.equal(c.selected, "r1");
    f.events.get("enterprise:navigate")({
        detail: { section: "projects", selected: "p1" },
    });
    f.events.get("enterprise:navigate")({ detail: { section: "requests" } });
    assert.equal(c.selected, "r1");
    f.events.get("enterprise:navigate")({ detail: { section: "projects" } });
    assert.equal(c.selected, "p1");
    f.events.get("enterprise:navigate")({
        detail: { area: "b", section: "projects" },
    });
    assert.equal(
        c.selected,
        null,
        "A new workspace cannot inherit another workspace's record",
    );
    assert.equal(c.area, "b");
});
