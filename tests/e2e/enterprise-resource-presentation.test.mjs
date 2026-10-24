import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import vm from "node:vm";

const source = await readFile(
    new URL(
        "../../resources/views/enterprise/resources-script.blade.php",
        import.meta.url,
    ),
    "utf8",
);
function fixture(entries, { plan = false, ...metadata } = {}) {
    const nodes = new Map();
    const element = () => {
        const selected = new Map();
        return {
            value: "",
            textContent: "",
            innerHTML: "",
            style: {},
            dataset: {},
            children: [],
            setAttribute() {},
            addEventListener() {},
            querySelector(selector) {
                // Only model selectors exercised by this catalog fixture.
                const markup = {
                    "[data-building-back]": /data-building-back=/,
                    h2: /<h2[ >]/,
                }[selector];
                assert.ok(markup, `Unsupported fixture selector: ${selector}`);
                if (!markup.test(this.innerHTML)) return null;
                if (!selected.has(selector)) selected.set(selector, element());
                return selected.get(selector);
            },
            querySelectorAll() {
                return [];
            },
            prepend(child) {
                this.children.unshift(child);
            },
            scrollTo() {},
        };
    };
    const get = (id) => {
        if (
            !plan &&
            [
                "res-plan",
                "res-plan-selection",
                "res-zoom",
                "res-zoom-value",
                "res-plan-image",
                "res-zoom-out",
                "res-zoom-in",
                "res-plan-fit",
                "res-plan-top",
                "res-plan-bottom",
                "res-plan-viewport",
            ].includes(id)
        )
            return null;
        if (!nodes.has(id)) nodes.set(id, element());
        return nodes.get(id);
    };
    const script = source
        .replace(/^@php\([^\n]*\)\r?\n/, "")
        .replace(
            "@json($campusResourceCatalog)",
            JSON.stringify({ entries, ...metadata }),
        );
    const context = vm.createContext({
        $: get,
        esc: (value) =>
            String(value)
                .replaceAll("<", "&lt;")
                .replaceAll(">", "&gt;")
                .replaceAll('"', "&quot;"),
        transport: { mode: "private" },
        workspace: "w",
        viewer: "u",
        nextBase: "/enterprise",
        section: "buildings",
        data: {},
        URL,
        URLSearchParams,
        document: {
            addEventListener() {},
            createElement(tag) {
                assert.equal(tag, "button");
                return element();
            },
        },
        matchMedia: () => ({ matches: false }),
        location: {
            href: "https://workspace.example/enterprise",
            origin: "https://workspace.example",
        },
    });
    vm.runInContext(script, context);
    return { nodes, get, run: (code) => vm.runInContext(code, context) };
}
test("a generic catalog starts without plan DOM and uses actual count and selected entry provenance", () => {
    const f = fixture([
        {
            map_number: "workshop",
            name: "Synthetic Workshop",
            source_label: "Reviewed register",
            source_page: 7,
            source_url: "https://customer.example/register",
        },
    ]);
    f.run(
        'resourceRenderList();resourceSelection="catalog:workshop";resourceRenderDetail();resourceZoom(200)',
    );
    assert.match(f.get("res-coverage").textContent, /All 1 catalog entries/);
    assert.match(f.get("res-detail").innerHTML, /Reviewed register, page 7/);
    assert.match(
        f.get("res-detail").innerHTML,
        /https:\/\/customer.example\/register/,
    );
    assert.doesNotMatch(
        f.get("res-detail").innerHTML,
        /Lees|page 2|campus parking/,
    );
    assert.equal(f.get("res-plan"), null);
    const back = f.get("res-detail").children[0];
    assert.equal(back.type, "button");
    assert.equal(back.textContent, "← Tickets");
    back.onclick();
    assert.equal(f.get("res-columns").dataset.buildingStep, "tickets");
    const heading = f.get("res-detail").querySelector("h2");
    assert.equal(heading.id, "res-detail-heading");
    assert.equal(heading.tabIndex, -1);
});
test("catalog-level provenance is a fallback and unsafe source URLs are never clickable", () => {
    const f = fixture(
        [
            {
                map_number: "room",
                name: "Room",
                source_url: "javascript:alert(1)",
                source_label: "<source>",
            },
        ],
        { source_label: "Fallback label", source_page: 4 },
    );
    f.run('resourceSelection="catalog:room";resourceRenderDetail()');
    assert.match(f.get("res-detail").innerHTML, /&lt;source&gt;, page 4/);
    assert.doesNotMatch(f.get("res-detail").innerHTML, /href=|javascript:/);
});
test("the existing 68 entry catalog and optional plan zoom still work", () => {
    const entries = Array.from({ length: 68 }, (_, i) => ({
        map_number: String(i + 1),
        name: "Location " + (i + 1),
    }));
    const f = fixture(entries, {
        plan: true,
        source_label: "Official campus parking map",
        source_page: 2,
    });
    f.run(
        'resourceRenderList();resourceSelection="catalog:1";resourceRenderDetail();resourceZoom(225)',
    );
    assert.match(f.get("res-coverage").textContent, /All 68 catalog entries/);
    assert.match(
        f.get("res-detail").innerHTML,
        /Official campus parking map, page 2/,
    );
    assert.equal(f.get("res-plan-image").style.width, "225%");
});
